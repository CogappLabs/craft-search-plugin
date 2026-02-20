<?php

/**
 * Search Index plugin for Craft CMS -- Console IndexController.
 */

namespace cogapp\searchindex\console\controllers;

use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use craft\console\Controller;
use craft\helpers\FileHelper;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console commands for managing search indexes (import, flush, refresh, redetect, status, debug, schema).
 *
 * @author cogapp
 * @since 1.0.0
 */
class IndexController extends Controller
{
    /** @var string The default action when none is specified. */
    public $defaultAction = 'status';
    /** @var string Output format for validate command. */
    public string $format = 'markdown';
    /** @var string Filter for validate command output. */
    public string $only = 'all';
    /** @var string Entry slug to validate against a specific entry. */
    public string $slug = '';
    /** @var bool When true, re-detect discards existing settings and uses fresh defaults. */
    public bool $fresh = false;
    /** @var bool Overwrite existing files for publish-sprig-templates command. */
    public bool $force = false;
    /** @var string Histogram interval for debug-numeric command (e.g. "100000" or "population:100000,area:50"). */
    public string $interval = '';
    /** @var string Range filter for debug-numeric command (e.g. "population:0-5000000" or "population:1000-,area:-500"). */
    public string $filter = '';

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if (in_array($actionID, ['validate', 'debug-schema', 'preview-schema'], true)) {
            $options[] = 'format';
        }
        if ($actionID === 'validate') {
            $options[] = 'only';
            $options[] = 'slug';
        }
        if ($actionID === 'redetect') {
            $options[] = 'fresh';
        }
        if ($actionID === 'publish-sprig-templates') {
            $options[] = 'force';
        }
        if ($actionID === 'debug-numeric') {
            $options[] = 'interval';
            $options[] = 'filter';
        }
        return $options;
    }

    /**
     * Publish starter frontend Sprig templates into the project templates directory.
     * Usage: php craft search-index/index/publish-sprig-templates [subpath] [--force]
     */
    public function actionPublishSprigTemplates(string $subpath = 'search-index/sprig'): int
    {
        $subpath = trim($subpath, '/');
        if ($subpath === '' || str_contains($subpath, '..')) {
            $this->stderr("Invalid subpath. Use a relative templates path without '..'.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $sourceRoot = SearchIndex::$plugin->getBasePath() . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'sprig';

        if (!is_dir($sourceRoot)) {
            $this->stderr("Source stubs directory not found: {$sourceRoot}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $templateRoot = \Craft::$app->getPath()->getSiteTemplatesPath();
        $targetRoot = FileHelper::normalizePath($templateRoot . DIRECTORY_SEPARATOR . $subpath);

        // Ensure the resolved target is still under the templates root (defense-in-depth)
        if (!str_starts_with($targetRoot, FileHelper::normalizePath($templateRoot))) {
            $this->stderr("Resolved path escapes the templates directory.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        FileHelper::createDirectory($targetRoot);

        $files = FileHelper::findFiles($sourceRoot, [
            'only' => ['*.twig', '*.md', '*.js'],
            'recursive' => true,
        ]);

        $publishedCount = 0;
        $skippedCount = 0;

        foreach ($files as $sourceFile) {
            $relativePath = ltrim(substr($sourceFile, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
            $targetFile = $targetRoot . DIRECTORY_SEPARATOR . $relativePath;

            if (is_file($targetFile) && !$this->force) {
                $skippedCount++;
                $this->stdout("Skipped existing file: {$targetFile}\n", Console::FG_YELLOW);
                continue;
            }

            FileHelper::createDirectory(dirname($targetFile));
            copy($sourceFile, $targetFile);
            $publishedCount++;
            $this->stdout("Published: {$targetFile}\n", Console::FG_GREEN);
        }

        $this->stdout("\nPublished {$publishedCount} file(s), skipped {$skippedCount} file(s).\n", Console::FG_CYAN);
        $this->stdout("Template location: {$targetRoot}\n", Console::FG_CYAN);

        return ExitCode::OK;
    }

    /**
     * Full re-index of all or a specific index.
     * Usage: php craft search-index/index/import [handle]
     */
    public function actionImport(?string $handle = null): int
    {
        $indexes = $this->_getIndexes($handle);

        if (empty($indexes)) {
            $this->stderr("No indexes found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($indexes as $index) {
            if ($index->isReadOnly()) {
                $this->stdout("Skipping read-only index: {$index->name} ({$index->handle})\n", Console::FG_YELLOW);
                continue;
            }

            $this->stdout("Importing index: {$index->name} ({$index->handle})...\n", Console::FG_CYAN);

            try {
                $engine = $index->createEngine();

                if (!$engine->indexExists($index)) {
                    $this->stdout("  Creating index in engine...\n");
                    $engine->createIndex($index);
                }

                // Update schema/settings
                $engine->updateIndexSettings($index);

                SearchIndex::$plugin->getSync()->importIndex($index);
                $this->stdout("  Import jobs queued.\n", Console::FG_GREEN);
            } catch (\Exception $e) {
                $this->stderr("  Error: {$e->getMessage()}\n", Console::FG_RED);
            }
        }

        $this->stdout("\nRun the queue to process: php craft queue/run\n", Console::FG_YELLOW);

        return ExitCode::OK;
    }

    /**
     * Clear all documents from an index.
     * Usage: php craft search-index/index/flush [handle]
     */
    public function actionFlush(?string $handle = null): int
    {
        $indexes = $this->_getIndexes($handle);

        if (empty($indexes)) {
            $this->stderr("No indexes found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($indexes as $index) {
            if ($index->isReadOnly()) {
                $this->stdout("Skipping read-only index: {$index->name} ({$index->handle})\n", Console::FG_YELLOW);
                continue;
            }

            $this->stdout("Flushing index: {$index->name} ({$index->handle})...\n", Console::FG_CYAN);

            try {
                SearchIndex::$plugin->getSync()->flushIndex($index);
                $this->stdout("  Flushed.\n", Console::FG_GREEN);
            } catch (\Exception $e) {
                $this->stderr("  Error: {$e->getMessage()}\n", Console::FG_RED);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Flush and re-import an index.
     * Usage: php craft search-index/index/refresh [handle]
     */
    public function actionRefresh(?string $handle = null): int
    {
        $indexes = $this->_getIndexes($handle);

        if (empty($indexes)) {
            $this->stderr("No indexes found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($indexes as $index) {
            if ($index->isReadOnly()) {
                $this->stdout("Skipping read-only index: {$index->name} ({$index->handle})\n", Console::FG_YELLOW);
                continue;
            }

            $this->stdout("Refreshing index: {$index->name} ({$index->handle})...\n", Console::FG_CYAN);

            try {
                $sync = SearchIndex::$plugin->getSync();

                if ($sync->supportsAtomicSwap($index)) {
                    // Zero-downtime refresh: import into temp index, then swap
                    $this->stdout("  Using atomic swap (zero-downtime)...\n");
                    $sync->importIndexForSwap($index);
                    $this->stdout("  Swap jobs queued.\n", Console::FG_GREEN);
                } else {
                    // Fallback: delete + recreate + import
                    $engine = $index->createEngine();

                    if ($engine->indexExists($index)) {
                        $engine->deleteIndex($index);
                    }
                    $engine->createIndex($index);
                    $engine->updateIndexSettings($index);

                    $sync->refreshIndex($index);
                    $this->stdout("  Refresh jobs queued.\n", Console::FG_GREEN);
                }
            } catch (\Exception $e) {
                $this->stderr("  Error: {$e->getMessage()}\n", Console::FG_RED);
            }
        }

        $this->stdout("\nRun the queue to process: php craft queue/run\n", Console::FG_YELLOW);

        return ExitCode::OK;
    }

    /**
     * Re-detect field mappings for all or a specific index.
     * Usage: php craft search-index/index/redetect [handle]
     */
    public function actionRedetect(?string $handle = null): int
    {
        $indexes = $this->_getIndexes($handle);

        if (empty($indexes)) {
            $this->stderr("No indexes found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($indexes as $index) {
            $fieldMapper = SearchIndex::$plugin->getFieldMapper();

            if ($index->isReadOnly()) {
                // Read-only indexes: detect from engine schema
                $this->stdout("Detecting schema fields for read-only index: {$index->name} ({$index->handle})" . ($this->fresh ? ' [fresh]' : '') . "...\n", Console::FG_CYAN);

                try {
                    $mappings = $this->fresh
                        ? $fieldMapper->detectSchemaFieldMappings($index)
                        : $fieldMapper->redetectSchemaFieldMappings($index);
                    $index->setFieldMappings($mappings);
                    SearchIndex::$plugin->getIndexes()->saveIndex($index, false);

                    $suffix = $this->fresh ? '(fresh defaults)' : '(role assignments preserved)';
                    $this->stdout("  Detected " . count($mappings) . " schema fields {$suffix}.\n", Console::FG_GREEN);
                } catch (\Exception $e) {
                    $this->stderr("  Error: {$e->getMessage()}\n", Console::FG_RED);
                }

                continue;
            }

            // Synced indexes: detect from Craft entry types
            $this->stdout("Re-detecting fields for: {$index->name} ({$index->handle})" . ($this->fresh ? ' [fresh]' : '') . "...\n", Console::FG_CYAN);

            $mappings = $this->fresh
                ? $fieldMapper->detectFieldMappings($index)
                : $fieldMapper->redetectFieldMappings($index);
            $index->setFieldMappings($mappings);
            SearchIndex::$plugin->getIndexes()->saveIndex($index, false);

            $suffix = $this->fresh ? '(fresh defaults)' : '(user settings preserved)';
            $this->stdout("  Detected " . count($mappings) . " field mappings {$suffix}.\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /**
     * Show status of all indexes.
     * Usage: php craft search-index/index/status
     */
    public function actionStatus(): int
    {
        $indexes = SearchIndex::$plugin->getIndexes()->getAllIndexes();

        if (empty($indexes)) {
            $this->stdout("No indexes configured.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $rows = [];
        foreach ($indexes as $index) {
            $engineName = 'Unknown';
            $connected = false;
            $docCount = '-';

            try {
                if (class_exists($index->engineType)) {
                    $engineName = $index->engineType::displayName();
                    $engine = $index->createEngine();
                    $connected = $engine->testConnection();

                    if ($connected && $engine->indexExists($index)) {
                        $docCount = (string)$engine->getDocumentCount($index);
                    }
                }
            } catch (\Exception $e) {
                \Craft::warning("Failed to connect to engine for index \"{$index->handle}\": {$e->getMessage()}", __METHOD__);
            }

            $rows[] = [
                $index->handle,
                $index->name,
                $engineName,
                $index->isReadOnly() ? 'Read-only' : 'Synced',
                $index->enabled ? 'Yes' : 'No',
                $connected ? 'Connected' : 'Disconnected',
                $docCount,
            ];
        }

        $this->stdout("\n");
        $this->table(
            ['Handle', 'Name', 'Engine', 'Mode', 'Enabled', 'Connection', 'Documents'],
            $rows
        );
        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Debug a search query and return both raw and normalised results.
     * Usage: php craft search-index/index/debug-search <handle> "<query>" '{"perPage":10,"page":1}'
     *
     * @param string      $handle      Index handle.
     * @param string      $query       Search query string.
     * @param string|null $optionsJson JSON-encoded options array.
     */
    public function actionDebugSearch(string $handle, string $query, ?string $optionsJson = null): int
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);
        if (!$index) {
            $this->stderr("Index not found: {$handle}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $options = [];
        if ($optionsJson) {
            try {
                $options = json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->stderr("Options must be a valid JSON object: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        // Resolve vectorSearch option: generate embedding via Voyage AI
        if (!empty($options['vectorSearch']) && !isset($options['embedding']) && trim($query) !== '') {
            $hadEmbeddingField = isset($options['embeddingField']) && $options['embeddingField'] !== '';
            $options = SearchIndex::$plugin->getVoyageClient()->resolveEmbeddingOptions($index, $query, $options);

            if (!$hadEmbeddingField && isset($options['embeddingField'])) {
                $this->stdout("Auto-detected embedding field: {$options['embeddingField']}\n", Console::FG_GREEN);
            }

            if (isset($options['embedding'])) {
                $this->stdout("Embedding generated (" . count($options['embedding']) . " dimensions).\n", Console::FG_GREEN);
            } elseif (!isset($options['embeddingField'])) {
                $this->stderr("No embedding field found on index \"{$handle}\". Skipping vector search.\n", Console::FG_YELLOW);
            } else {
                $this->stderr("Voyage AI embedding failed (check API key and logs).\n", Console::FG_YELLOW);
            }
        }

        try {
            $engine = $index->createEngine();
            $result = $engine->search($index, $query, $options);

            $payload = [
                'index' => [
                    'handle' => $index->handle,
                    'name' => $index->name,
                    'engine' => $index->engineType,
                ],
                'options' => $options,
                'result' => [
                    'hits' => $result->hits,
                    'totalHits' => $result->totalHits,
                    'page' => $result->page,
                    'perPage' => $result->perPage,
                    'totalPages' => $result->totalPages,
                    'facets' => $result->facets,
                    'stats' => $result->stats,
                    'histograms' => $result->histograms,
                ],
                'raw' => $result->raw,
            ];

            $this->stdout(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Debug facet value search across one or more facet fields.
     * Usage: php craft search-index/index/debug-facet-search <handle> "<query>" ['{"maxPerField":5,"facetFields":["region"]}']
     *
     * If facetFields is omitted, auto-detects all TYPE_FACET fields on the index.
     */
    public function actionDebugFacetSearch(string $handle, string $query = '', ?string $optionsJson = null): int
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);
        if (!$index) {
            $this->stderr("Index not found: {$handle}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $options = [];
        if ($optionsJson) {
            try {
                $options = json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->stderr("Options must be a valid JSON object: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $maxPerField = (int)($options['maxPerField'] ?? 10);
        $facetFields = $options['facetFields'] ?? [];

        // Auto-detect facet fields if none specified
        if (empty($facetFields)) {
            foreach ($index->getFieldMappings() as $mapping) {
                if ($mapping->enabled && $mapping->indexFieldType === \cogapp\searchindex\models\FieldMapping::TYPE_FACET) {
                    $facetFields[] = $mapping->indexFieldName;
                }
            }
            $facetFields = array_values(array_unique($facetFields));

            if (empty($facetFields)) {
                $this->stderr("No facet fields found on index \"{$handle}\".\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }
            $this->stdout('Auto-detected facet fields: ' . implode(', ', $facetFields) . "\n", Console::FG_GREEN);
        }

        try {
            $engine = $index->createEngine();
            $results = $engine->searchFacetValues($index, $facetFields, $query, $maxPerField);

            $this->stdout("\n");
            $this->stdout("Index:   {$index->name} ({$handle})\n");
            $this->stdout("Engine:  {$index->engineType}\n");
            $this->stdout("Query:   " . ($query !== '' ? "\"{$query}\"" : '(empty — all values)') . "\n\n");

            if (empty($results)) {
                $this->stdout("No matching facet values found.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            foreach ($results as $field => $values) {
                $this->stdout("{$field}\n", Console::FG_CYAN);
                foreach ($values as $item) {
                    $this->stdout("  {$item['value']} ({$item['count']})\n");
                }
                $this->stdout("\n");
            }

            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Debug autocomplete search results (lightweight search with role-based field retrieval).
     * Usage: php craft search-index/index/debug-autocomplete <handle> "<query>" ['{"perPage":5}']
     *
     * @param string      $handle      Index handle.
     * @param string      $query       Autocomplete query string.
     * @param string|null $optionsJson JSON-encoded options array.
     */
    public function actionDebugAutocomplete(string $handle, string $query, ?string $optionsJson = null): int
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);
        if (!$index) {
            $this->stderr("Index not found: {$handle}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $options = [];
        if ($optionsJson) {
            try {
                $options = json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->stderr("Options must be a valid JSON object: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        try {
            $variable = new \cogapp\searchindex\variables\SearchIndexVariable();
            $result = $variable->autocomplete($handle, $query, $options);

            $this->stdout("\n");
            $this->stdout("Index:   {$index->name} ({$handle})\n");
            $this->stdout("Engine:  {$index->engineType}\n");
            $this->stdout("Query:   \"{$query}\"\n");
            $this->stdout("Hits:    {$result->totalHits} total, showing {$result->perPage}\n\n");

            if (empty($result->hits)) {
                $this->stdout("No results found.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            // Detect which role fields are present
            $roleFields = [];
            foreach ($index->getFieldMappings() as $mapping) {
                if ($mapping->enabled && $mapping->role !== null) {
                    $roleFields[$mapping->role] = $mapping->indexFieldName;
                }
            }

            foreach ($result->hits as $i => $hit) {
                $num = $i + 1;
                $title = $roleFields['title'] ?? 'title';
                $titleValue = $hit[$title] ?? $hit['title'] ?? $hit['objectID'] ?? '-';
                $this->stdout("{$num}. {$titleValue}\n", Console::FG_CYAN);

                if (isset($roleFields['url']) && isset($hit[$roleFields['url']])) {
                    $this->stdout("   url: {$hit[$roleFields['url']]}\n");
                }
                if (isset($roleFields['image']) && isset($hit[$roleFields['image']])) {
                    $this->stdout("   image: {$hit[$roleFields['image']]}\n");
                }
                if (isset($hit['_score'])) {
                    $this->stdout("   score: {$hit['_score']}\n");
                }
            }

            $this->stdout("\n");
            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Debug "did you mean?" suggestions for a query.
     * Usage: php craft search-index/index/debug-suggest <handle> "<query>"
     *
     * Searches with suggest: true and highlight: true, then displays any spelling
     * suggestions alongside the top results and their highlights.
     * Currently ES/OpenSearch only — other engines return results via typo tolerance
     * but do not populate separate suggestions.
     *
     * @param string $handle Index handle.
     * @param string $query  Search query (try a misspelling like "edinbrugh").
     */
    public function actionDebugSuggest(string $handle, string $query): int
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);
        if (!$index) {
            $this->stderr("Index not found: {$handle}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $engine = $index->createEngine();
            $result = $engine->search($index, $query, [
                'suggest' => true,
                'highlight' => true,
                'perPage' => 5,
            ]);

            $this->stdout("\n");
            $this->stdout("Index:   {$index->name} ({$handle})\n");
            $this->stdout("Engine:  {$index->engineType}\n");
            $this->stdout("Query:   \"{$query}\"\n");
            $this->stdout("Hits:    {$result->totalHits}\n");

            // Suggestions
            if (!empty($result->suggestions)) {
                $this->stdout("\n");
                $this->stdout("Did you mean:\n", Console::FG_GREEN);
                foreach ($result->suggestions as $suggestion) {
                    $this->stdout("  → {$suggestion}\n", Console::FG_GREEN);
                }
            } else {
                $this->stdout("\nNo suggestions returned", Console::FG_YELLOW);
                if (!str_contains($index->engineType, 'Elastic') && !str_contains($index->engineType, 'OpenSearch')) {
                    $this->stdout(" (suggest is ES/OpenSearch only — this engine uses built-in typo tolerance instead)", Console::FG_YELLOW);
                }
                $this->stdout("\n", Console::FG_YELLOW);
            }

            // Top results with highlights
            if (!empty($result->hits)) {
                $this->stdout("\nTop results:\n");
                foreach ($result->hits as $i => $hit) {
                    $num = $i + 1;
                    $title = $hit['title'] ?? $hit['objectID'] ?? '-';
                    $this->stdout("  {$num}. {$title}\n", Console::FG_CYAN);

                    // Show highlights if present
                    $highlights = $hit['_highlights'] ?? [];
                    if (!empty($highlights)) {
                        foreach ($highlights as $field => $fragments) {
                            if (is_array($fragments)) {
                                foreach ($fragments as $fragment) {
                                    $this->stdout("     [{$field}] {$fragment}\n");
                                }
                            }
                        }
                    }
                }
            } else {
                $this->stdout("\nNo results found.\n", Console::FG_YELLOW);
            }

            $this->stdout("\n");
            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Debug numeric fields: show stats (min/max), range filters, and histogram distributions.
     *
     * Auto-detects integer/float fields on the index and runs a search with stats.
     * Optionally add histograms (--interval) and range filters (--filter).
     *
     * Usage:
     *   php craft search-index/index/debug-numeric <handle> [query]
     *   php craft search-index/index/debug-numeric <handle> --interval=100000
     *   php craft search-index/index/debug-numeric <handle> --interval="population:1000000,area:50"
     *   php craft search-index/index/debug-numeric <handle> --filter="population:0-5000000"
     *   php craft search-index/index/debug-numeric <handle> --filter="population:1000-" --interval=100000
     *
     * @param string $handle Index handle.
     * @param string $query  Optional search query (default: match all).
     */
    public function actionDebugNumeric(string $handle, string $query = '*'): int
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);
        if (!$index) {
            $this->stderr("Index not found: {$handle}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Auto-detect numeric fields
        $numericFields = [];
        foreach ($index->getFieldMappings() as $mapping) {
            if ($mapping->enabled
                && in_array($mapping->indexFieldType, [
                    \cogapp\searchindex\models\FieldMapping::TYPE_INTEGER,
                    \cogapp\searchindex\models\FieldMapping::TYPE_FLOAT,
                ], true)
                && $mapping->role === null
            ) {
                $numericFields[] = $mapping->indexFieldName;
            }
        }
        $numericFields = array_values(array_unique($numericFields));

        if (empty($numericFields)) {
            $this->stderr("No numeric (integer/float) fields found on index \"{$handle}\".\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("\n");
        $this->stdout("Index:   {$index->name} ({$handle})\n");
        $this->stdout("Engine:  {$index->engineType}\n");
        $this->stdout("Query:   " . ($query !== '*' ? "\"{$query}\"" : '* (all)') . "\n");
        $this->stdout("Fields:  " . implode(', ', $numericFields) . "\n");

        $options = [
            'stats' => $numericFields,
            'perPage' => 0,
        ];

        // Parse --filter option: "field:min-max" or "field:min-,field:-max"
        $filters = [];
        if ($this->filter !== '') {
            foreach (explode(',', $this->filter) as $part) {
                $part = trim($part);
                if (!str_contains($part, ':')) {
                    $this->stderr("Invalid --filter format: {$part}. Expected field:min-max.\n", Console::FG_RED);
                    return ExitCode::USAGE;
                }
                [$filterField, $range] = explode(':', $part, 2);
                $filterField = trim($filterField);
                if (!in_array($filterField, $numericFields, true)) {
                    $this->stderr("Field \"{$filterField}\" is not a numeric field on this index.\n", Console::FG_RED);
                    return ExitCode::USAGE;
                }
                if (!str_contains($range, '-')) {
                    $this->stderr("Invalid range format: {$range}. Expected min-max (e.g. 0-5000000, 1000-, -500).\n", Console::FG_RED);
                    return ExitCode::USAGE;
                }
                [$minStr, $maxStr] = explode('-', $range, 2);
                $rangeFilter = [];
                if ($minStr !== '') {
                    $rangeFilter['min'] = (float)$minStr;
                }
                if ($maxStr !== '') {
                    $rangeFilter['max'] = (float)$maxStr;
                }
                if (!empty($rangeFilter)) {
                    $filters[$filterField] = $rangeFilter;
                }
            }
        }

        if (!empty($filters)) {
            $options['filters'] = $filters;
            $this->stdout("Filters: ");
            $parts = [];
            foreach ($filters as $f => $r) {
                $min = $r['min'] ?? '*';
                $max = $r['max'] ?? '*';
                $parts[] = "{$f}: [{$min} to {$max}]";
            }
            $this->stdout(implode(', ', $parts) . "\n");
        }

        // Parse --interval option: single number (applies to all) or "field:interval,field:interval"
        $histogramConfig = [];
        if ($this->interval !== '') {
            if (is_numeric($this->interval)) {
                // Single interval for all numeric fields
                $interval = (float)$this->interval;
                foreach ($numericFields as $field) {
                    $histogramConfig[$field] = $interval;
                }
            } else {
                // Per-field intervals: "population:1000000,area:50"
                foreach (explode(',', $this->interval) as $part) {
                    $part = trim($part);
                    if (!str_contains($part, ':')) {
                        $this->stderr("Invalid --interval format: {$part}. Expected number or field:number.\n", Console::FG_RED);
                        return ExitCode::USAGE;
                    }
                    [$intField, $intValue] = explode(':', $part, 2);
                    $intField = trim($intField);
                    $intValue = trim($intValue);
                    if (!is_numeric($intValue)) {
                        $this->stderr("Invalid interval value: {$intValue}. Must be a number.\n", Console::FG_RED);
                        return ExitCode::USAGE;
                    }
                    $histogramConfig[$intField] = (float)$intValue;
                }
            }
            $options['histogram'] = $histogramConfig;
        }

        try {
            $engine = $index->createEngine();
            $result = $engine->search($index, $query, $options);

            $this->stdout("Hits:    {$result->totalHits}\n");

            // Stats
            if (!empty($result->stats)) {
                $this->stdout("\n");
                $this->stdout("Stats\n", Console::FG_CYAN);

                $rows = [];
                foreach ($result->stats as $field => $stat) {
                    $rows[] = [
                        $field,
                        $this->_formatNumber($stat['min'] ?? null),
                        $this->_formatNumber($stat['max'] ?? null),
                    ];
                }
                $this->table(['Field', 'Min', 'Max'], $rows);
            } else {
                $this->stdout("\nNo stats returned (engine may not support stats).\n", Console::FG_YELLOW);
            }

            // Histograms
            if (!empty($result->histograms)) {
                $this->stdout("\n");
                $this->stdout("Histograms\n", Console::FG_CYAN);

                foreach ($result->histograms as $field => $buckets) {
                    $this->stdout("\n  {$field}", Console::FG_GREEN);
                    $interval = $histogramConfig[$field] ?? '?';
                    $this->stdout(" (interval: {$interval})\n");

                    if (empty($buckets)) {
                        $this->stdout("  (no buckets)\n", Console::FG_YELLOW);
                        continue;
                    }

                    $maxCount = max(array_column($buckets, 'count'));
                    $barWidth = 40;

                    foreach ($buckets as $bucket) {
                        $key = $this->_formatNumber($bucket['key']);
                        $count = $bucket['count'];
                        $bar = $maxCount > 0
                            ? str_repeat('█', (int)round($count / $maxCount * $barWidth))
                            : '';
                        $this->stdout(sprintf("  %12s │ %-{$barWidth}s %d\n", $key, $bar, $count));
                    }
                }
            } elseif (!empty($histogramConfig)) {
                $this->stdout("\nNo histograms returned (engine may not support histograms).\n", Console::FG_YELLOW);
            }

            $this->stdout("\n");
            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Format a number for display: integers show without decimals, floats with up to 2 decimals.
     */
    private function _formatNumber(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }
        if (is_float($value) && floor($value) !== $value) {
            return number_format($value, 2, '.', ',');
        }
        return number_format((float)$value, 0, '.', ',');
    }

    /**
     * Validate field mappings and output results.
     * Usage: php craft search-index/index/validate [handle] --format=markdown --only=all --slug=arduaine-garden
     *
     * --format: markdown|json (default: markdown)
     * --only: all|issues (issues = warnings, errors, nulls)
     * --slug: validate against a specific entry by slug
     */
    public function actionValidate(?string $handle = null): int
    {
        if (!in_array($this->format, ['markdown', 'json'], true)) {
            $this->stderr("Invalid --format: {$this->format}. Use 'markdown' or 'json'.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        if (!in_array($this->only, ['all', 'issues'], true)) {
            $this->stderr("Invalid --only: {$this->only}. Use 'all' or 'issues'.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $indexes = $this->_getIndexes($handle);

        if (empty($indexes)) {
            $this->stderr("No indexes found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $validator = SearchIndex::$plugin->getFieldMappingValidator();

        foreach ($indexes as $index) {
            if ($this->slug !== '' && $index->isReadOnly()) {
                $this->stderr("--slug is not supported for read-only indexes.\n", Console::FG_YELLOW);
                continue;
            }

            // Look up entry by slug scoped to this index's sections/types
            $forceEntry = null;
            if ($this->slug !== '') {
                $slugQuery = \craft\elements\Entry::find()->slug($this->slug)->status(null);
                if (!empty($index->sectionIds)) {
                    $slugQuery->sectionId($index->sectionIds);
                }
                if (!empty($index->entryTypeIds)) {
                    $slugQuery->typeId($index->entryTypeIds);
                }
                if ($index->siteId) {
                    $slugQuery->siteId($index->siteId);
                }
                $forceEntry = $slugQuery->one();
                if (!$forceEntry) {
                    $this->stderr("No entry found with slug '{$this->slug}' in index {$index->handle}.\n", Console::FG_RED);
                    continue;
                }
                $this->stdout("Using entry: {$forceEntry->title} (#{$forceEntry->id})\n", Console::FG_CYAN);
            }

            $payload = $validator->validateIndex($index, $forceEntry);

            if (!$payload['success']) {
                $this->stderr(($payload['message'] ?? 'Validation failed') . " for {$index->handle}.\n", Console::FG_YELLOW);
                continue;
            }

            if ($this->format === 'json') {
                $this->stdout(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                continue;
            }

            $filterMode = $this->only === 'issues' ? 'issues' : null;
            $titleSuffix = $filterMode === 'issues' ? ' (Warnings, Errors & Nulls)' : '';
            $this->stdout(SearchIndex::$plugin->getFieldMappingValidator()->buildValidationMarkdown($payload, $filterMode, $titleSuffix) . "\n");
        }

        return ExitCode::OK;
    }

    /**
     * Debug how a specific entry resolves a field mapping.
     * Usage: php craft search-index/index/debug-entry <handle> <slug> [indexFieldName]
     *
     * @param string      $indexHandle  Index handle.
     * @param string      $slug         Entry slug.
     * @param string|null $fieldName    Optional index field name to debug (debugs all if omitted).
     */
    public function actionDebugEntry(string $indexHandle, string $slug, ?string $fieldName = null): int
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($indexHandle);
        if (!$index) {
            $this->stderr("Index not found: {$indexHandle}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $query = \craft\elements\Entry::find()->slug($slug)->status(null);
        if (!empty($index->sectionIds)) {
            $query->sectionId($index->sectionIds);
        }
        if (!empty($index->entryTypeIds)) {
            $query->typeId($index->entryTypeIds);
        }
        $entry = $query->one();
        if (!$entry) {
            $this->stderr("No entry found with slug: {$slug}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Entry: {$entry->title} (#{$entry->id})\n\n", Console::FG_CYAN);

        $fieldMapper = SearchIndex::$plugin->getFieldMapper();
        $mappings = $index->getFieldMappings();

        // Resolve the full document once, outside the loop
        $document = $fieldMapper->resolveElement($entry, $index);

        foreach ($mappings as $mapping) {
            if (!$mapping->enabled) {
                continue;
            }
            if ($fieldName !== null && $mapping->indexFieldName !== $fieldName) {
                continue;
            }

            $this->stdout("--- {$mapping->indexFieldName} (type={$mapping->indexFieldType}) ---\n");

            if ($mapping->isSubField()) {
                $parentField = $mapping->parentFieldUid ? \Craft::$app->getFields()->getFieldByUid($mapping->parentFieldUid) : null;
                $subField = $mapping->fieldUid ? \Craft::$app->getFields()->getFieldByUid($mapping->fieldUid) : null;

                $this->stdout("  parent: " . ($parentField ? $parentField->handle . ' (' . get_class($parentField) . ')' : 'NULL') . "\n");
                $this->stdout("  sub:    " . ($subField ? $subField->handle . ' (' . get_class($subField) . ')' : 'NULL') . "\n");

                if ($parentField && $subField) {
                    $matrixQuery = $entry->getFieldValue($parentField->handle);
                    $blocks = $matrixQuery ? $matrixQuery->all() : [];
                    $this->stdout("  blocks: " . count($blocks) . "\n");

                    foreach ($blocks as $block) {
                        $fl = $block->getFieldLayout();
                        $customFields = $fl ? $fl->getCustomFields() : [];
                        $handles = array_map(fn($f) => $f->handle, $customFields);
                        $hasField = in_array($subField->handle, $handles, true);

                        $this->stdout("    block #{$block->id} type={$block->type->handle} has_field=" . ($hasField ? 'yes' : 'no') . "\n");
                        $this->stdout("      all fields: " . implode(', ', array_map(fn($f) => $f->handle . ' (' . (new \ReflectionClass($f))->getShortName() . ' uid=' . $f->uid . ')', $customFields)) . "\n");

                        if ($hasField) {
                            $val = $block->getFieldValue($subField->handle);
                            if ($val instanceof \craft\elements\db\ElementQuery) {
                                $count = $val->count();
                                $this->stdout("      query_count={$count}");
                                if ($count > 0) {
                                    $first = $val->one();
                                    $this->stdout(" first=" . ($first ? $first->title . ' url=' . ($first->getUrl() ?? 'null') : 'null'));
                                }
                                $this->stdout("\n");
                            } else {
                                $this->stdout('      value=' . (is_scalar($val) ? var_export($val, true) : gettype($val)) . "\n");
                            }
                        } else {
                            // Check if any Asset field on this block has data
                            foreach ($customFields as $bf) {
                                if ($bf instanceof \craft\fields\Assets) {
                                    $val = $block->getFieldValue($bf->handle);
                                    $count = ($val instanceof \craft\elements\db\ElementQuery) ? $val->count() : 0;
                                    $this->stdout("      asset field '{$bf->handle}' (uid={$bf->uid}) count={$count}");
                                    if ($count > 0) {
                                        $first = $val->one();
                                        $this->stdout(" → " . ($first ? $first->title : 'null'));
                                    }
                                    $this->stdout("\n");
                                }
                            }
                        }
                    }
                }
            }

            // Show the actual resolved value
            $value = $document[$mapping->indexFieldName] ?? null;
            $this->stdout("  resolved: " . ($value !== null ? json_encode($value, JSON_UNESCAPED_SLASHES) : 'null') . "\n\n");
        }

        return ExitCode::OK;
    }

    /**
     * Fetch and display a raw document from the engine by ID.
     * Usage: php craft search-index/index/get-document <handle> <documentId>
     *
     * @param string $handle     Index handle.
     * @param string $documentId The document ID (objectID) to retrieve.
     */
    public function actionGetDocument(string $handle, string $documentId): int
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);
        if (!$index) {
            $this->stderr("Index not found: {$handle}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $engine = $index->createEngine();
            $document = $engine->getDocument($index, $documentId);

            if ($document === null) {
                $this->stderr("Document not found: {$documentId}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Show the live engine schema for an index.
     * Usage: php craft search-index/index/debug-schema <handle> [--format=json]
     *
     * Default: normalised field table + raw engine schema JSON.
     * --format=json: single JSON payload with both.
     *
     * @param string $handle Index handle.
     */
    public function actionDebugSchema(string $handle): int
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);
        if (!$index) {
            $this->stderr("Index not found: {$handle}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $engine = $index->createEngine();

            if (!$engine->indexExists($index)) {
                $this->stderr("Index does not exist in engine: {$handle}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $schemaFields = $engine->getSchemaFields($index);
            $rawSchema = $engine->getIndexSchema($index);

            if ($this->format === 'json') {
                $payload = [
                    'index' => [
                        'handle' => $index->handle,
                        'name' => $index->name,
                        'engine' => $index->engineType,
                    ],
                    'fields' => $schemaFields,
                    'rawSchema' => $rawSchema,
                ];
                $this->stdout(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                return ExitCode::OK;
            }

            // Default table output
            $this->stdout("\n");
            $this->stdout("Index:   {$index->name} ({$handle})\n");
            $this->stdout("Engine:  {$index->engineType}\n\n");

            if (!empty($schemaFields)) {
                $this->stdout("Fields\n", Console::FG_CYAN);
                $rows = [];
                foreach ($schemaFields as $field) {
                    $rows[] = [$field['name'], $field['type']];
                }
                $this->table(['Name', 'Type'], $rows);
            } else {
                $this->stdout("No fields detected.\n", Console::FG_YELLOW);
            }

            $this->stdout("\nRaw Schema\n", Console::FG_CYAN);
            $this->stdout(json_encode($rawSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n");

            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Preview what schema would be built from current field mappings.
     * Usage: php craft search-index/index/preview-schema <handle> [--format=json]
     *
     * Shows the proposed schema from field mappings and the live engine schema
     * (if the index exists), flagging whether they differ.
     *
     * @param string $handle Index handle.
     */
    public function actionPreviewSchema(string $handle): int
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);
        if (!$index) {
            $this->stderr("Index not found: {$handle}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $mappings = $index->getFieldMappings();
        if (empty($mappings) || $index->isReadOnly()) {
            $this->stderr("No field mappings on this index (read-only or unconfigured). Use debug-schema instead.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        try {
            $engine = $index->createEngine();
            $proposedSchema = $engine->buildSchema($mappings);

            $liveSchema = null;
            if ($engine->indexExists($index)) {
                $liveSchema = $engine->getIndexSchema($index);
            }

            $differs = $liveSchema !== null && json_encode($proposedSchema) !== json_encode($liveSchema);

            if ($this->format === 'json') {
                $payload = [
                    'index' => [
                        'handle' => $index->handle,
                        'name' => $index->name,
                        'engine' => $index->engineType,
                    ],
                    'proposedSchema' => $proposedSchema,
                    'liveSchema' => $liveSchema,
                    'differs' => $differs,
                ];
                $this->stdout(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                return ExitCode::OK;
            }

            // Default output
            $this->stdout("\n");
            $this->stdout("Index:   {$index->name} ({$handle})\n");
            $this->stdout("Engine:  {$index->engineType}\n\n");

            $this->stdout("Proposed Schema (from field mappings)\n", Console::FG_CYAN);
            $this->stdout(json_encode($proposedSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n");

            if ($liveSchema !== null) {
                $this->stdout("Live Schema (from engine)\n", Console::FG_CYAN);
                $this->stdout(json_encode($liveSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n");

                if ($differs) {
                    $this->stdout("Schemas differ. Run 'refresh' to apply the proposed schema.\n", Console::FG_YELLOW);
                } else {
                    $this->stdout("Schemas match.\n", Console::FG_GREEN);
                }
            } else {
                $this->stdout("Index does not exist in engine yet. Run 'import' to create it.\n", Console::FG_YELLOW);
            }

            $this->stdout("\n");
            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Run multiple search queries in a single batch.
     * Usage: php craft search-index/index/debug-multi-search '[{"handle":"h1","query":"q1"},{"handle":"h2","query":"q2","options":{}}]'
     *
     * Input: JSON array of {handle, query, options?} objects.
     * Output: JSON array of results with query echo and normalised result data.
     *
     * Note: vectorSearch with auto-embedding is not supported in multi-search.
     * Pass pre-computed `embedding` arrays in options if needed.
     *
     * @param string $queriesJson JSON-encoded array of search queries.
     */
    public function actionDebugMultiSearch(string $queriesJson): int
    {
        try {
            $queries = json_decode($queriesJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->stderr("Invalid JSON: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!is_array($queries) || empty($queries)) {
            $this->stderr("Input must be a non-empty JSON array of query objects.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Validate all handles upfront
        $indexService = SearchIndex::$plugin->getIndexes();
        $searches = [];
        foreach ($queries as $i => $q) {
            $handle = $q['handle'] ?? '';
            if ($handle === '') {
                $this->stderr("Query #{$i}: missing 'handle'.\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $index = $indexService->getIndexByHandle($handle);
            if (!$index) {
                $this->stderr("Query #{$i}: index not found: {$handle}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $searches[] = [
                'handle' => $handle,
                'query' => $q['query'] ?? '',
                'options' => $q['options'] ?? [],
            ];
        }

        try {
            $variable = new \cogapp\searchindex\variables\SearchIndexVariable();
            $results = $variable->multiSearch($searches);

            $output = [];
            foreach ($searches as $i => $search) {
                $result = $results[$i] ?? null;
                $entry = [
                    'query' => $search,
                ];

                if ($result instanceof \cogapp\searchindex\models\SearchResult) {
                    $entry['result'] = [
                        'totalHits' => $result->totalHits,
                        'page' => $result->page,
                        'perPage' => $result->perPage,
                        'totalPages' => $result->totalPages,
                            'hits' => $result->hits,
                        'facets' => $result->facets,
                        'suggestions' => $result->suggestions,
                    ];
                } else {
                    $entry['result'] = null;
                    $entry['error'] = 'No result returned';
                }

                $output[] = $entry;
            }

            $this->stdout(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Return indexes filtered by handle, or all indexes if no handle given.
     *
     * @param string|null $handle
     * @return Index[]
     */
    private function _getIndexes(?string $handle): array
    {
        if ($handle) {
            $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);
            return $index ? [$index] : [];
        }

        return SearchIndex::$plugin->getIndexes()->getAllIndexes();
    }
}

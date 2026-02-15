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
 * Console commands for managing search indexes (import, flush, refresh, redetect, status).
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

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'validate') {
            $options[] = 'format';
            $options[] = 'only';
            $options[] = 'slug';
        }
        if ($actionID === 'redetect') {
            $options[] = 'fresh';
        }
        if ($actionID === 'publish-sprig-templates') {
            $options[] = 'force';
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
            'only' => ['*.twig', '*.md'],
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
                    'processingTimeMs' => $result->processingTimeMs,
                    'facets' => $result->facets,
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

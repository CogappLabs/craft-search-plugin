<?php

/**
 * Search Index plugin for Craft CMS -- Console IndexController.
 */

namespace cogapp\searchindex\console\controllers;

use cogapp\searchindex\SearchIndex;
use Craft;
use craft\console\Controller;
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
            $this->stdout("Importing index: {$index->name} ({$index->handle})...\n", Console::FG_CYAN);

            try {
                $engineClass = $index->engineType;
                $engine = new $engineClass($index->engineConfig ?? []);

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
            $this->stdout("Refreshing index: {$index->name} ({$index->handle})...\n", Console::FG_CYAN);

            try {
                $engineClass = $index->engineType;
                $engine = new $engineClass($index->engineConfig ?? []);

                if (!$engine->indexExists($index)) {
                    $engine->createIndex($index);
                }

                $engine->updateIndexSettings($index);

                SearchIndex::$plugin->getSync()->refreshIndex($index);
                $this->stdout("  Refresh jobs queued.\n", Console::FG_GREEN);
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
            $this->stdout("Re-detecting fields for: {$index->name} ({$index->handle})...\n", Console::FG_CYAN);

            $mappings = SearchIndex::$plugin->getFieldMapper()->detectFieldMappings($index);
            $index->setFieldMappings($mappings);
            SearchIndex::$plugin->getIndexes()->saveIndex($index, false);

            $this->stdout("  Detected " . count($mappings) . " field mappings.\n", Console::FG_GREEN);
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
                $engineClass = $index->engineType;
                if (class_exists($engineClass)) {
                    $engineName = $engineClass::displayName();
                    $engine = new $engineClass($index->engineConfig ?? []);
                    $connected = $engine->testConnection();

                    if ($connected && $engine->indexExists($index)) {
                        $docCount = (string)$engine->getDocumentCount($index);
                    }
                }
            } catch (\Exception $e) {
                // Keep defaults
            }

            $rows[] = [
                $index->handle,
                $index->name,
                $engineName,
                $index->enabled ? 'Yes' : 'No',
                $connected ? 'Connected' : 'Disconnected',
                $docCount,
            ];
        }

        $this->stdout("\n");
        $this->_renderTable(
            ['Handle', 'Name', 'Engine', 'Enabled', 'Connection', 'Documents'],
            $rows
        );
        $this->stdout("\n");

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

    /**
     * Render a table to the console output.
     *
     * @param string[] $headers
     * @param array[]  $rows
     * @return void
     */
    private function _renderTable(array $headers, array $rows): void
    {
        $this->table($headers, $rows);
    }
}

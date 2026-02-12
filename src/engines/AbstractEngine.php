<?php

/**
 * Abstract base class for search engine implementations.
 */

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\Index;
use craft\helpers\App;

/**
 * Provides shared functionality for all search engine implementations.
 *
 * Handles per-index configuration, environment-variable resolution, and
 * fallback batch operations that concrete engines can override with native
 * bulk APIs.
 *
 * @author cogapp
 * @since 1.0.0
 */
abstract class AbstractEngine implements EngineInterface
{
    /**
     * Per-index engine configuration (e.g. index prefix).
     *
     * @var array
     */
    protected array $config;

    /**
     * @param array $config Per-index engine configuration values.
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Return the resolved index name, applying any configured prefix.
     *
     * @param Index $index The index model.
     * @return string The prefixed index name.
     */
    protected function getIndexName(Index $index): string
    {
        $prefix = $this->config['indexPrefix'] ?? '';
        $prefix = App::parseEnv($prefix);

        return $prefix . $index->handle;
    }

    /**
     * Return a parsed config value, resolving environment variables.
     *
     * @param string $key     The configuration key to look up.
     * @param string $default Fallback value if the key is not set.
     * @return string The resolved value.
     */
    protected function parseSetting(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? $default;

        return App::parseEnv($value);
    }

    /**
     * Default batch index: loops single indexDocument calls.
     * Engine implementations should override with native bulk APIs.
     *
     * @param Index $index     The target index.
     * @param array $documents Array of document bodies, each containing an 'objectID' key.
     * @return void
     */
    public function indexDocuments(Index $index, array $documents): void
    {
        foreach ($documents as $document) {
            $elementId = $document['objectID'] ?? 0;
            $this->indexDocument($index, (int)$elementId, $document);
        }
    }

    /**
     * Default batch delete: loops single deleteDocument calls.
     * Engine implementations should override with native bulk APIs.
     *
     * @param Index $index      The target index.
     * @param int[] $elementIds Array of Craft element IDs to remove.
     * @return void
     */
    public function deleteDocuments(Index $index, array $elementIds): void
    {
        foreach ($elementIds as $elementId) {
            $this->deleteDocument($index, $elementId);
        }
    }
}

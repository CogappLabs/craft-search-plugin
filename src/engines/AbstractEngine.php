<?php

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\Index;
use craft\helpers\App;

abstract class AbstractEngine implements EngineInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Returns the resolved index name, applying any configured prefix.
     */
    protected function getIndexName(Index $index): string
    {
        $prefix = $this->config['indexPrefix'] ?? '';
        $prefix = App::parseEnv($prefix);

        return $prefix . $index->handle;
    }

    /**
     * Returns a parsed config value, resolving environment variables.
     */
    protected function parseSetting(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? $default;

        return App::parseEnv($value);
    }

    /**
     * Default batch index: loops single indexDocument calls.
     * Engine implementations should override with native bulk APIs.
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
     */
    public function deleteDocuments(Index $index, array $elementIds): void
    {
        foreach ($elementIds as $elementId) {
            $this->deleteDocument($index, $elementId);
        }
    }
}

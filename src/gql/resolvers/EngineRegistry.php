<?php

/**
 * Search Index plugin for Craft CMS -- Engine instance registry for GQL resolvers.
 */

namespace cogapp\searchindex\gql\resolvers;

use cogapp\searchindex\engines\EngineInterface;
use cogapp\searchindex\models\Index;

/**
 * Request-scoped engine instance registry shared across all GQL resolvers.
 *
 * @author cogapp
 * @since 1.0.0
 */
class EngineRegistry
{
    /** @var array<string, EngineInterface> */
    private static array $_cache = [];

    /**
     * Return a cached engine instance for the given index.
     *
     * Engines are keyed by engine type + config hash so the same HTTP client
     * is reused across multiple GQL queries within a single request.
     *
     * @param Index $index
     * @return EngineInterface
     */
    public static function get(Index $index): EngineInterface
    {
        $key = $index->engineType . ':' . md5(json_encode($index->engineConfig ?? []));

        if (!isset(self::$_cache[$key])) {
            self::$_cache[$key] = $index->createEngine();
        }

        return self::$_cache[$key];
    }
}

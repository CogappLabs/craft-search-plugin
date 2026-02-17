<?php

/**
 * Search Index plugin for Craft CMS -- Engine caching for GQL resolvers.
 */

namespace cogapp\searchindex\gql\resolvers;

use cogapp\searchindex\engines\EngineInterface;
use cogapp\searchindex\models\Index;

/**
 * Trait providing engine caching via the shared EngineRegistry.
 *
 * @author cogapp
 * @since 1.0.0
 */
trait EngineCacheTrait
{
    /**
     * Return a cached engine instance for the given index.
     *
     * @param Index $index
     * @return EngineInterface
     */
    protected static function getEngine(Index $index): EngineInterface
    {
        return EngineRegistry::get($index);
    }
}

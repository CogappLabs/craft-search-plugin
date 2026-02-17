<?php

/**
 * Search Index plugin for Craft CMS -- GQL scope helpers.
 */

namespace cogapp\searchindex\gql;

use cogapp\searchindex\models\Index;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Error\UserError;

/**
 * Helper for checking GQL schema permissions on search indexes.
 *
 * Schema scope format: `searchindex.{indexUid}:read`
 *
 * @author cogapp
 * @since 1.0.0
 */
class GqlPermissions
{
    /**
     * Check whether the active GQL schema has read access to the given index.
     *
     * @param Index $index The search index to check.
     * @throws UserError If the schema does not grant access.
     */
    public static function requireIndexReadAccess(Index $index): void
    {
        if (!GqlHelper::canSchema("searchindex.{$index->uid}", 'read')) {
            throw new UserError("You do not have permission to query the \"{$index->handle}\" search index.");
        }
    }
}

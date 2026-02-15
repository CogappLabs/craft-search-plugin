<?php

/**
 * Search Index plugin for Craft CMS -- PluginTrait.
 */

namespace cogapp\searchindex\base;

use cogapp\searchindex\services\FieldMapper;
use cogapp\searchindex\services\FieldMappingValidator;
use cogapp\searchindex\services\Indexes;
use cogapp\searchindex\services\Sync;
use cogapp\searchindex\services\VoyageClient;

/**
 * Registers and provides typed accessors for the plugin's service components.
 *
 * @author cogapp
 * @since 1.0.0
 */
trait PluginTrait
{
    /**
     * Return the component configuration for the plugin's services.
     *
     * @return array
     */
    public static function config(): array
    {
        return [
            'components' => [
                'indexes' => Indexes::class,
                'fieldMapper' => FieldMapper::class,
                'fieldMappingValidator' => FieldMappingValidator::class,
                'sync' => Sync::class,
                'voyageClient' => VoyageClient::class,
            ],
        ];
    }

    /**
     * Return the Indexes service instance.
     *
     * @return Indexes
     */
    public function getIndexes(): Indexes
    {
        return $this->get('indexes');
    }

    /**
     * Return the FieldMapper service instance.
     *
     * @return FieldMapper
     */
    public function getFieldMapper(): FieldMapper
    {
        return $this->get('fieldMapper');
    }

    /**
     * Return the FieldMappingValidator service instance.
     *
     * @return FieldMappingValidator
     */
    public function getFieldMappingValidator(): FieldMappingValidator
    {
        return $this->get('fieldMappingValidator');
    }

    /**
     * Return the Sync service instance.
     *
     * @return Sync
     */
    public function getSync(): Sync
    {
        return $this->get('sync');
    }

    /**
     * Return the VoyageClient service instance.
     *
     * @return VoyageClient
     */
    public function getVoyageClient(): VoyageClient
    {
        return $this->get('voyageClient');
    }
}

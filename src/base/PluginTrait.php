<?php

/**
 * Search Index plugin for Craft CMS -- PluginTrait.
 */

namespace cogapp\searchindex\base;

use cogapp\searchindex\services\EngineOverrides;
use cogapp\searchindex\services\FieldMapper;
use cogapp\searchindex\services\FieldMappingValidator;
use cogapp\searchindex\services\Indexes;
use cogapp\searchindex\services\ResponsiveImages;
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
     * @return array<string, mixed>
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
                'engineOverrides' => EngineOverrides::class,
                'responsiveImages' => ResponsiveImages::class,
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
        /** @var Indexes */
        return $this->get('indexes');
    }

    /**
     * Return the FieldMapper service instance.
     *
     * @return FieldMapper
     */
    public function getFieldMapper(): FieldMapper
    {
        /** @var FieldMapper */
        return $this->get('fieldMapper');
    }

    /**
     * Return the FieldMappingValidator service instance.
     *
     * @return FieldMappingValidator
     */
    public function getFieldMappingValidator(): FieldMappingValidator
    {
        /** @var FieldMappingValidator */
        return $this->get('fieldMappingValidator');
    }

    /**
     * Return the Sync service instance.
     *
     * @return Sync
     */
    public function getSync(): Sync
    {
        /** @var Sync */
        return $this->get('sync');
    }

    /**
     * Return the VoyageClient service instance.
     *
     * @return VoyageClient
     */
    public function getVoyageClient(): VoyageClient
    {
        /** @var VoyageClient */
        return $this->get('voyageClient');
    }

    /**
     * Return the EngineOverrides service instance.
     *
     * @return EngineOverrides
     */
    public function getEngineOverrides(): EngineOverrides
    {
        /** @var EngineOverrides */
        return $this->get('engineOverrides');
    }

    /**
     * Return the ResponsiveImages service instance.
     *
     * @return ResponsiveImages
     */
    public function getResponsiveImages(): ResponsiveImages
    {
        /** @var ResponsiveImages */
        return $this->get('responsiveImages');
    }
}

<?php

namespace cogapp\searchindex\base;

use cogapp\searchindex\services\FieldMapper;
use cogapp\searchindex\services\Indexes;
use cogapp\searchindex\services\Sync;

trait PluginTrait
{
    public static function config(): array
    {
        return [
            'components' => [
                'indexes' => Indexes::class,
                'fieldMapper' => FieldMapper::class,
                'sync' => Sync::class,
            ],
        ];
    }

    public function getIndexes(): Indexes
    {
        return $this->get('indexes');
    }

    public function getFieldMapper(): FieldMapper
    {
        return $this->get('fieldMapper');
    }

    public function getSync(): Sync
    {
        return $this->get('sync');
    }
}

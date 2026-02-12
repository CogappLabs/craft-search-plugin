<?php

namespace cogapp\searchindex\models;

use craft\base\Model;

class Index extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public string $engineType = '';
    public ?array $engineConfig = null;
    public ?array $sectionIds = null;
    public ?array $entryTypeIds = null;
    public ?int $siteId = null;
    public bool $enabled = true;
    public int $sortOrder = 0;
    public ?string $uid = null;

    /** @var FieldMapping[] */
    private array $_fieldMappings = [];

    public function defineRules(): array
    {
        return [
            [['name', 'handle', 'engineType'], 'required'],
            ['handle', 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/'],
            ['name', 'string', 'max' => 255],
            ['engineType', 'string', 'max' => 255],
            ['siteId', 'integer'],
            ['enabled', 'boolean'],
            ['sortOrder', 'integer'],
        ];
    }

    public function getFieldMappings(): array
    {
        return $this->_fieldMappings;
    }

    public function setFieldMappings(array $mappings): void
    {
        $this->_fieldMappings = $mappings;
    }

    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'engineType' => $this->engineType,
            'engineConfig' => $this->engineConfig,
            'sectionIds' => $this->sectionIds,
            'entryTypeIds' => $this->entryTypeIds,
            'siteId' => $this->siteId,
            'enabled' => $this->enabled,
            'sortOrder' => $this->sortOrder,
            'fieldMappings' => array_combine(
                array_map(fn(FieldMapping $m) => $m->uid, $this->_fieldMappings),
                array_map(fn(FieldMapping $m) => $m->getConfig(), $this->_fieldMappings),
            ),
        ];
    }
}

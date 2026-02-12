<?php

namespace cogapp\searchindex\models;

use craft\base\Model;

class FieldMapping extends Model
{
    public const TYPE_TEXT = 'text';
    public const TYPE_KEYWORD = 'keyword';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_FLOAT = 'float';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_DATE = 'date';
    public const TYPE_GEO_POINT = 'geo_point';
    public const TYPE_FACET = 'facet';
    public const TYPE_OBJECT = 'object';

    public const FIELD_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_KEYWORD,
        self::TYPE_INTEGER,
        self::TYPE_FLOAT,
        self::TYPE_BOOLEAN,
        self::TYPE_DATE,
        self::TYPE_GEO_POINT,
        self::TYPE_FACET,
        self::TYPE_OBJECT,
    ];

    public ?int $id = null;
    public ?int $indexId = null;
    public ?string $fieldUid = null;
    public ?string $parentFieldUid = null;
    public ?string $attribute = null;
    public string $indexFieldName = '';
    public string $indexFieldType = self::TYPE_TEXT;
    public bool $enabled = true;
    public int $weight = 5;
    public ?array $resolverConfig = null;
    public int $sortOrder = 0;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['indexFieldName', 'indexFieldType'], 'required'],
            ['indexFieldName', 'string', 'max' => 255],
            ['indexFieldType', 'in', 'range' => self::FIELD_TYPES],
            ['weight', 'integer', 'min' => 1, 'max' => 10],
            ['enabled', 'boolean'],
            ['sortOrder', 'integer'],
        ];
    }

    public function isAttribute(): bool
    {
        return $this->attribute !== null;
    }

    public function isSubField(): bool
    {
        return $this->parentFieldUid !== null;
    }

    public function getConfig(): array
    {
        return [
            'fieldUid' => $this->fieldUid,
            'parentFieldUid' => $this->parentFieldUid,
            'attribute' => $this->attribute,
            'indexFieldName' => $this->indexFieldName,
            'indexFieldType' => $this->indexFieldType,
            'enabled' => $this->enabled,
            'weight' => $this->weight,
            'resolverConfig' => $this->resolverConfig,
            'sortOrder' => $this->sortOrder,
        ];
    }
}

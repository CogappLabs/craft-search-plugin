<?php

/**
 * Field mapping model representing how a Craft field maps to a search index field.
 */

namespace cogapp\searchindex\models;

use craft\base\Model;

/**
 * Defines the mapping between a Craft CMS field and a field in the search index,
 * including type, weight, and resolver configuration.
 *
 * @author cogapp
 * @since 1.0.0
 */
class FieldMapping extends Model
{
    /** Full-text searchable field type */
    public const TYPE_TEXT = 'text';

    /** Exact-match keyword field type */
    public const TYPE_KEYWORD = 'keyword';

    /** Integer numeric field type */
    public const TYPE_INTEGER = 'integer';

    /** Floating-point numeric field type */
    public const TYPE_FLOAT = 'float';

    /** Boolean field type */
    public const TYPE_BOOLEAN = 'boolean';

    /** Date/datetime field type */
    public const TYPE_DATE = 'date';

    /** Geographic coordinate point field type */
    public const TYPE_GEO_POINT = 'geo_point';

    /** Facetable field type for aggregation/filtering */
    public const TYPE_FACET = 'facet';

    /** Nested object field type */
    public const TYPE_OBJECT = 'object';

    /** Vector embedding field type (e.g. CLIP, sentence embeddings) */
    public const TYPE_EMBEDDING = 'embedding';

    /** All supported index field types */
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
        self::TYPE_EMBEDDING,
    ];

    // --8<-- [start:field-mapping-roles]
    /** Semantic role: primary display title */
    public const ROLE_TITLE = 'title';

    /** Semantic role: primary image (asset ID or URL) */
    public const ROLE_IMAGE = 'image';

    /** Semantic role: thumbnail image (asset ID or URL) */
    public const ROLE_THUMBNAIL = 'thumbnail';

    /** Semantic role: short description/excerpt */
    public const ROLE_SUMMARY = 'summary';

    /** Semantic role: link URL / URI */
    public const ROLE_URL = 'url';

    /** Semantic role: primary date (publish date, created date, etc.) */
    public const ROLE_DATE = 'date';

    /** Semantic role: IIIF Image API info.json URL */
    public const ROLE_IIIF = 'iiif';

    /** All supported semantic roles */
    public const ROLES = [
        self::ROLE_TITLE,
        self::ROLE_IMAGE,
        self::ROLE_THUMBNAIL,
        self::ROLE_SUMMARY,
        self::ROLE_URL,
        self::ROLE_DATE,
        self::ROLE_IIIF,
    ];
    // --8<-- [end:field-mapping-roles]

    /** @var int|null Primary key ID */
    public ?int $id = null;

    /** @var int|null Foreign key to the parent Index record */
    public ?int $indexId = null;

    /** @var string|null UID of the Craft field this mapping references */
    public ?string $fieldUid = null;

    /** @var string|null UID of the parent Craft field (for sub-fields like Matrix blocks) */
    public ?string $parentFieldUid = null;

    /** @var string|null Element attribute name (when mapping an attribute instead of a field) */
    public ?string $attribute = null;

    /** @var string Name of the field in the search index */
    public string $indexFieldName = '';

    /** @var string Data type of the field in the search index */
    public string $indexFieldType = self::TYPE_TEXT;

    /** @var string|null Semantic role for this mapping (title, image, thumbnail, summary, url, date, iiif) */
    public ?string $role = null;

    /** @var bool Whether this field mapping is enabled */
    public bool $enabled = true;

    /** @var int Search weight/boost for this field (1-10) */
    public int $weight = 5;

    /** @var array|null Configuration for the field value resolver */
    public ?array $resolverConfig = null;

    /** @var int Position used for ordering field mappings */
    public int $sortOrder = 0;

    /** @var string|null UUID for Project Config storage */
    public ?string $uid = null;

    /**
     * Returns the validation rules for the field mapping model.
     *
     * @return array Validation rules
     */
    public function defineRules(): array
    {
        return [
            [['indexFieldName', 'indexFieldType'], 'required'],
            ['indexFieldName', 'string', 'max' => 255],
            ['indexFieldType', 'in', 'range' => self::FIELD_TYPES],
            ['weight', 'integer', 'min' => 1, 'max' => 10],
            ['role', 'in', 'range' => self::ROLES, 'strict' => true, 'skipOnEmpty' => true],
            ['enabled', 'boolean'],
            ['sortOrder', 'integer'],
        ];
    }

    /**
     * Returns whether this mapping is for an element attribute rather than a field.
     *
     * @return bool True if the mapping targets an element attribute
     */
    public function isAttribute(): bool
    {
        return $this->attribute !== null;
    }

    /**
     * Returns whether this mapping is for a sub-field (e.g. a field within a Matrix block).
     *
     * @return bool True if the mapping has a parent field
     */
    public function isSubField(): bool
    {
        return $this->parentFieldUid !== null;
    }

    /**
     * Returns the field mapping configuration array for Project Config storage.
     *
     * @return array Serializable configuration array
     */
    public function getConfig(): array
    {
        return [
            'fieldUid' => $this->fieldUid,
            'parentFieldUid' => $this->parentFieldUid,
            'attribute' => $this->attribute,
            'indexFieldName' => $this->indexFieldName,
            'indexFieldType' => $this->indexFieldType,
            'role' => $this->role,
            'enabled' => $this->enabled,
            'weight' => $this->weight,
            'resolverConfig' => $this->resolverConfig,
            'sortOrder' => $this->sortOrder,
        ];
    }
}

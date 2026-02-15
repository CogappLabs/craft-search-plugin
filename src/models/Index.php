<?php

/**
 * Index model representing a single search index configuration.
 */

namespace cogapp\searchindex\models;

use cogapp\searchindex\engines\EngineInterface;
use craft\base\Model;

/**
 * Represents a search index, including its engine type, scope, and field mappings.
 *
 * @author cogapp
 * @since 1.0.0
 */
class Index extends Model
{
    /** @var int|null Primary key ID */
    public ?int $id = null;

    /** @var string Human-readable index name */
    public string $name = '';

    /** @var string Unique handle used for programmatic reference */
    public string $handle = '';

    /** @var string Fully qualified class name of the search engine */
    public string $engineType = '';

    /** @var array|null Engine-specific configuration options */
    public ?array $engineConfig = null;

    /** @var array|null Section IDs that this index covers */
    public ?array $sectionIds = null;

    /** @var array|null Entry type IDs that this index covers */
    public ?array $entryTypeIds = null;

    /** @var int|null Site ID to restrict indexing to a specific site */
    public ?int $siteId = null;

    /** @var bool Whether the index is enabled */
    public bool $enabled = true;

    /** @var string Index mode: 'synced' (default) or 'readonly' */
    public string $mode = self::MODE_SYNCED;

    public const MODE_SYNCED = 'synced';
    public const MODE_READONLY = 'readonly';

    /** @var int Position used for ordering indexes */
    public int $sortOrder = 0;

    /** @var string|null UUID for Project Config storage */
    public ?string $uid = null;

    /** @var FieldMapping[] Field mappings associated with this index */
    private array $_fieldMappings = [];

    /**
     * Whether this index is read-only (externally managed, no sync).
     */
    public function isReadOnly(): bool
    {
        return $this->mode === self::MODE_READONLY;
    }

    /**
     * Create an engine instance for this index.
     *
     * @return EngineInterface
     */
    public function createEngine(): EngineInterface
    {
        $engineClass = $this->engineType;

        if (!class_exists($engineClass)) {
            throw new \InvalidArgumentException("Engine class not found: {$engineClass}");
        }

        if (!is_subclass_of($engineClass, EngineInterface::class)) {
            throw new \InvalidArgumentException("Engine class must implement EngineInterface: {$engineClass}");
        }

        return new $engineClass($this->engineConfig ?? []);
    }

    /**
     * Returns the validation rules for the index model.
     *
     * @return array Validation rules
     */
    public function defineRules(): array
    {
        return [
            [['name', 'handle', 'engineType'], 'required'],
            ['handle', 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/'],
            ['name', 'string', 'max' => 255],
            ['engineType', 'string', 'max' => 255],
            ['siteId', 'integer'],
            ['enabled', 'boolean'],
            ['mode', 'in', 'range' => [self::MODE_SYNCED, self::MODE_READONLY]],
            ['sortOrder', 'integer'],
        ];
    }

    /**
     * Returns the field mappings associated with this index.
     *
     * @return FieldMapping[] Array of field mapping models
     */
    public function getFieldMappings(): array
    {
        return $this->_fieldMappings;
    }

    /**
     * Sets the field mappings for this index.
     *
     * @param FieldMapping[] $mappings Array of field mapping models
     * @return void
     */
    public function setFieldMappings(array $mappings): void
    {
        $this->_fieldMappings = $mappings;
    }

    /**
     * Return the index field name of the first enabled embedding field, or null.
     *
     * Used by vector search callers to auto-detect the target KNN field
     * without duplicating the field mapping scan.
     *
     * @return string|null
     */
    public function getEmbeddingFieldName(): ?string
    {
        foreach ($this->_fieldMappings as $mapping) {
            if ($mapping->enabled && $mapping->indexFieldType === FieldMapping::TYPE_EMBEDDING) {
                return $mapping->indexFieldName;
            }
        }

        return null;
    }

    /**
     * Returns the index configuration array for Project Config storage.
     *
     * @return array Serializable configuration array
     */
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
            'mode' => $this->mode,
            'sortOrder' => $this->sortOrder,
            'fieldMappings' => !empty($this->_fieldMappings) ? array_combine(
                array_map(fn(FieldMapping $m) => $m->uid, $this->_fieldMappings),
                array_map(fn(FieldMapping $m) => $m->getConfig(), $this->_fieldMappings),
            ) : [],
        ];
    }
}

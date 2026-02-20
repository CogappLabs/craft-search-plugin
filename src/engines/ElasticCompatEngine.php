<?php

/**
 * Shared base class for Elasticsearch and OpenSearch engines.
 */

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use Craft;

/**
 * Abstract base for Elasticsearch-compatible engines (Elasticsearch, OpenSearch).
 *
 * Both engines share an almost identical REST API surface. This base class
 * implements every shared operation; subclasses only need to provide the
 * client, display name, and the handful of methods whose signatures or
 * exception types differ between the two client libraries.
 *
 * @author cogapp
 * @since 1.0.0
 */
abstract class ElasticCompatEngine extends AbstractEngine
{
    /** @var array<string, array<string, string>> Memoized field type maps keyed by index handle. */
    private array $_fieldTypeMapCache = [];

    /** @var array<string, string|null> Memoized suggest field names keyed by index handle. */
    private array $_suggestFieldCache = [];

    /**
     * Return the underlying client (Elasticsearch or OpenSearch).
     *
     * @return mixed The engine-specific client instance.
     */
    abstract protected function getClient(): mixed;

    /**
     * @inheritdoc
     */
    public static function configFields(): array
    {
        return [
            'indexName' => [
                'label' => 'Index Name',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Use a specific engine index name instead of the handle. Useful for connecting to external indexes. Supports environment variables.',
            ],
            'indexPrefix' => [
                'label' => 'Index Prefix',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Optional prefix for this index name (e.g. "production_"). Ignored when Index Name is set. Supports environment variables.',
            ],
            'host' => [
                'label' => 'Host',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global host URL for this index. Leave blank to use the global setting.',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function createIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $schema = $this->buildSchema($index->getFieldMappings());

        $this->getClient()->indices()->create([
            'index' => $indexName,
            'body' => [
                'mappings' => $schema,
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function updateIndexSettings(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $schema = $this->buildSchema($index->getFieldMappings());

        $this->getClient()->indices()->putMapping([
            'index' => $indexName,
            'body' => $schema,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function supportsAtomicSwap(): bool
    {
        return true;
    }

    /**
     * Return the swap handle, alternating between `_swap_a` and `_swap_b`.
     *
     * Checks which backing index the production alias currently points to
     * and returns the other suffix so the swap can alternate.
     *
     * @inheritdoc
     */
    public function buildSwapHandle(Index $index): string
    {
        $aliasName = $this->getIndexName($index);
        $currentTarget = $this->_getAliasTarget($aliasName);

        if ($currentTarget !== null && str_ends_with($currentTarget, '_swap_a')) {
            return $index->handle . '_swap_b';
        }

        return $index->handle . '_swap_a';
    }

    /**
     * Atomically swap the production alias to point to the new backing index.
     *
     * On first swap (migrating from a direct index to aliases), the direct index
     * is deleted and replaced with an alias. Subsequent swaps are fully atomic.
     *
     * @inheritdoc
     */
    public function swapIndex(Index $index, Index $swapIndex): void
    {
        $aliasName = $this->getIndexName($index);
        $newTarget = $this->getIndexName($swapIndex);
        $currentTarget = $this->_getAliasTarget($aliasName);

        if ($currentTarget !== null) {
            // Atomic alias swap: remove old, add new in a single request
            $this->getClient()->indices()->updateAliases([
                'body' => [
                    'actions' => [
                        ['remove' => ['index' => $currentTarget, 'alias' => $aliasName]],
                        ['add' => ['index' => $newTarget, 'alias' => $aliasName]],
                    ],
                ],
            ]);
            // Clean up old backing index
            $this->getClient()->indices()->delete(['index' => $currentTarget]);
        } else {
            // First swap: migrate from direct index to alias-based
            // Delete the direct index (brief gap is unavoidable on first swap)
            if ($this->_directIndexExists($aliasName)) {
                $this->getClient()->indices()->delete(['index' => $aliasName]);
            }
            // Create alias pointing to the new backing index
            $this->getClient()->indices()->putAlias([
                'index' => $newTarget,
                'name' => $aliasName,
            ]);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $target = $this->_getAliasTarget($indexName);

        if ($target !== null) {
            // Alias-based: delete the alias and its backing index
            $this->getClient()->indices()->deleteAlias(['index' => $target, 'name' => $indexName]);
            $this->getClient()->indices()->delete(['index' => $target]);
        } else {
            // Direct index
            $this->getClient()->indices()->delete(['index' => $indexName]);
        }
    }

    /**
     * @inheritdoc
     */
    public function getIndexSchema(Index $index): array
    {
        $indexName = $this->getIndexName($index);

        try {
            $response = $this->responseToArray(
                $this->getClient()->indices()->getMapping(['index' => $indexName])
            );
            return $response[$indexName] ?? $response;
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @inheritdoc
     */
    public function getSchemaFields(Index $index): array
    {
        $schema = $this->getIndexSchema($index);

        if (isset($schema['error'])) {
            return $this->handleSchemaError($index);
        }

        return $this->parseSchemaFields($schema);
    }

    /**
     * @inheritdoc
     */
    protected function parseSchemaFields(array $schema): array
    {
        // Schema structure: ['mappings']['properties'] => ['field' => ['type' => '...']]
        $properties = $schema['mappings']['properties'] ?? $schema['properties'] ?? [];
        $fields = [];

        foreach ($properties as $name => $definition) {
            $nativeType = $definition['type'] ?? 'text';
            $fields[] = ['name' => $name, 'type' => $this->reverseMapFieldType($nativeType)];
        }

        return $fields;
    }

    /**
     * @inheritdoc
     */
    protected function handleSchemaError(Index $index): array
    {
        return $this->inferSchemaFieldsFromSampleDocuments($index);
    }

    /**
     * @inheritdoc
     */
    protected function sampleDocumentsForSchemaInference(Index $index): array
    {
        $indexName = $this->getIndexName($index);
        $response = $this->responseToArray(
            $this->getClient()->search([
                'index' => $indexName,
                'body' => ['size' => 5, 'query' => ['match_all' => (object)[]]],
            ])
        );

        $documents = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            if (is_array($hit['_source'] ?? null)) {
                $documents[] = $hit['_source'];
            }
        }

        return $documents;
    }

    /**
     * Infer a plugin field type from a field name and sample value.
     *
     * Uses both the value's PHP type and name-based heuristics (e.g. fields
     * ending in `_at` or containing `date` are likely dates even when stored
     * as epoch integers).
     *
     * @param string $name  The field name.
     * @param mixed  $value A sample value from a document.
     * @return string A FieldMapping::TYPE_* constant.
     */
    protected function inferFieldType(string $name, mixed $value): string
    {
        // Unambiguous value types take priority over name heuristics
        if (is_bool($value)) {
            return FieldMapping::TYPE_BOOLEAN;
        }

        // Name-based overrides (for integer timestamps, ambiguous strings, etc.)
        $lower = strtolower($name);

        // Date heuristics: epoch timestamps are integers but semantically dates
        if (preg_match('/(_at|_date|_time|timestamp)$/', $lower)
            || preg_match('/^(created|updated|deleted|modified|date)_/', $lower)
        ) {
            return FieldMapping::TYPE_DATE;
        }

        // Boolean heuristics: is_*, has_*, *_enabled, *_active
        if (preg_match('/^(is_|has_)/', $lower) || preg_match('/_(enabled|active|visible|archived)$/', $lower)) {
            return FieldMapping::TYPE_BOOLEAN;
        }

        // Value-based inference
        if (is_int($value)) {
            return FieldMapping::TYPE_INTEGER;
        }
        if (is_float($value)) {
            return FieldMapping::TYPE_FLOAT;
        }
        if (is_array($value)) {
            if (array_is_list($value) && !empty($value)) {
                $first = $value[0];
                // List of strings → facet (e.g. ["red","green","blue"])
                if (is_string($first)) {
                    return FieldMapping::TYPE_FACET;
                }
                // Large numeric array → vector embedding
                if ((is_float($first) || is_int($first)) && count($value) > static::EMBEDDING_MIN_DIMENSIONS) {
                    return FieldMapping::TYPE_EMBEDDING;
                }
            }
            return FieldMapping::TYPE_OBJECT;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return FieldMapping::TYPE_DATE;
        }

        return FieldMapping::TYPE_TEXT;
    }

    /**
     * Map an Elasticsearch/OpenSearch native type back to a plugin field type constant.
     *
     * @param string $nativeType The engine-native type string.
     * @return string A FieldMapping::TYPE_* constant.
     */
    protected function reverseMapFieldType(string $nativeType): string
    {
        return match ($nativeType) {
            'text' => FieldMapping::TYPE_TEXT,
            'keyword' => FieldMapping::TYPE_KEYWORD,
            'integer', 'long', 'short', 'byte' => FieldMapping::TYPE_INTEGER,
            'float', 'double', 'half_float', 'scaled_float' => FieldMapping::TYPE_FLOAT,
            'boolean' => FieldMapping::TYPE_BOOLEAN,
            'date', 'date_nanos' => FieldMapping::TYPE_DATE,
            'geo_point' => FieldMapping::TYPE_GEO_POINT,
            'object', 'nested' => FieldMapping::TYPE_OBJECT,
            'knn_vector', 'dense_vector' => FieldMapping::TYPE_EMBEDDING,
            default => FieldMapping::TYPE_TEXT,
        };
    }

    /**
     * @inheritdoc
     */
    public function indexDocument(Index $index, int $elementId, array $document): void
    {
        $indexName = $this->getIndexName($index);
        $document = $this->normaliseDateFields($index, $document, self::DATE_FORMAT_ISO8601);

        $this->getClient()->index([
            'index' => $indexName,
            'id' => (string)$elementId,
            'body' => $document,
        ]);
    }

    /**
     * Bulk-index multiple documents using the _bulk API.
     *
     * @param Index $index     The target index.
     * @param array $documents Array of document bodies, each containing an 'objectID' key.
     * @return void
     */
    public function indexDocuments(Index $index, array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $indexName = $this->getIndexName($index);
        $params = ['body' => []];

        foreach ($documents as $document) {
            $elementId = $document['objectID'] ?? null;
            if (!$elementId) {
                continue;
            }
            $document = $this->normaliseDateFields($index, $document, self::DATE_FORMAT_ISO8601);

            $params['body'][] = [
                'index' => [
                    '_index' => $indexName,
                    '_id' => (string)$elementId,
                ],
            ];
            $params['body'][] = $document;
        }

        $response = $this->responseToArray($this->getClient()->bulk($params));

        if ($response['errors'] ?? false) {
            $errors = [];
            foreach ($response['items'] as $item) {
                $action = $item['index'] ?? $item['create'] ?? [];
                if (isset($action['error'])) {
                    $errors[] = $action['error']['reason'] ?? 'Unknown error';
                }
            }
            Craft::warning(static::displayName() . ' bulk indexing errors: ' . implode('; ', $errors), __METHOD__);
        }
    }

    /**
     * Bulk-delete multiple documents using the _bulk API.
     *
     * @param Index $index      The target index.
     * @param int[] $elementIds Array of Craft element IDs to remove.
     * @return void
     */
    public function deleteDocuments(Index $index, array $elementIds): void
    {
        if (empty($elementIds)) {
            return;
        }

        $indexName = $this->getIndexName($index);
        $params = ['body' => []];

        foreach ($elementIds as $elementId) {
            $params['body'][] = [
                'delete' => [
                    '_index' => $indexName,
                    '_id' => (string)$elementId,
                ],
            ];
        }

        $this->getClient()->bulk($params);
    }

    /**
     * @inheritdoc
     */
    public function flushIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        $this->getClient()->deleteByQuery([
            'index' => $indexName,
            'body' => [
                'query' => [
                    'match_all' => (object)[],
                ],
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getDocument(Index $index, string $documentId): ?array
    {
        $indexName = $this->getIndexName($index);

        try {
            $response = $this->responseToArray(
                $this->getClient()->get([
                    'index' => $indexName,
                    'id' => $documentId,
                ])
            );
            return $response['_source'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @inheritdoc
     */
    public function search(Index $index, string $query, array $options = []): SearchResult
    {
        $indexName = $this->getIndexName($index);

        [$facets, $filters, $maxValuesPerFacet, $options] = $this->extractFacetParams($options);
        [$statsFields, $options] = $this->extractStatsParams($options);
        [$histogramConfig, $options] = $this->extractHistogramParams($options);
        [$sort, $options] = $this->extractSortParams($options);
        [$attributesToRetrieve, $options] = $this->extractAttributesToRetrieve($options);
        [$highlight, $options] = $this->extractHighlightParams($options);
        [$suggest, $options] = $this->extractSuggestParams($options);
        [$embedding, $embeddingField, $options] = $this->extractEmbeddingParams($options);
        [$geoFilter, $geoSort, $options] = $this->extractGeoParams($options);
        [$geoGrid, $options] = $this->extractGeoGridParams($options);
        [$page, $perPage, $remaining] = $this->extractPaginationParams($options, 20);

        $fields = $remaining['fields'] ?? ['*'];

        // Engine-native from/size take precedence over unified page/perPage.
        $from = $remaining['from'] ?? $this->offsetFromPage($page, $perPage);
        $size = $remaining['size'] ?? $perPage;

        // Build the text query component
        if (trim($query) === '') {
            $textQuery = null;
        } else {
            $textQuery = [
                'multi_match' => [
                    'query' => $query,
                    'fields' => $fields,
                    'type' => $remaining['matchType'] ?? 'bool_prefix',
                ],
            ];
        }

        // Build the KNN vector query component
        $knnQuery = null;
        if ($embedding !== null && $embeddingField !== null) {
            $knnQuery = [
                'knn' => [
                    $embeddingField => [
                        'vector' => $embedding,
                        'k' => $size,
                    ],
                ],
            ];
        }

        // Combine text and KNN into the final match query
        if ($knnQuery !== null && $textQuery !== null) {
            // Hybrid: text + vector combined via bool/should
            $matchQuery = [
                'bool' => [
                    'should' => [
                        $textQuery,
                        $knnQuery,
                    ],
                ],
            ];
        } elseif ($knnQuery !== null) {
            // Vector-only search
            $matchQuery = $knnQuery;
        } elseif ($textQuery !== null) {
            // Text-only search
            $matchQuery = $textQuery;
        } else {
            // No query and no embedding → match_all (browse mode)
            $matchQuery = ['match_all' => (object)[]];
        }

        // If unified filters are provided, wrap in a bool query with filter clauses
        if (!empty($filters)) {
            $filterClauses = $this->buildNativeFilterParams($filters, $index);

            // OpenSearch requires KNN at the top level with filter inside the KNN clause
            if ($knnQuery !== null && $this instanceof \cogapp\searchindex\engines\OpenSearchEngine) {
                $knnField = array_key_first($knnQuery['knn']);
                $knnQuery['knn'][$knnField]['filter'] = ['bool' => ['filter' => $filterClauses]];

                if ($textQuery !== null) {
                    // Hybrid: text query filtered + KNN with embedded filter
                    $body = [
                        'query' => [
                            'bool' => [
                                'should' => [$textQuery, $knnQuery],
                                'filter' => $filterClauses,
                            ],
                        ],
                    ];
                } else {
                    // Vector-only with filter inside KNN
                    $body = ['query' => $knnQuery];
                }
            } else {
                $body = [
                    'query' => [
                        'bool' => [
                            'must' => [$matchQuery],
                            'filter' => $filterClauses,
                        ],
                    ],
                ];
            }
        } else {
            $body = ['query' => $matchQuery];
        }

        // Geo-distance filter: adds a geo_distance filter clause to the query
        if ($geoFilter !== null) {
            $geoField = $this->detectGeoField($index);
            if ($geoField !== null) {
                $geoClause = [
                    'geo_distance' => [
                        'distance' => $geoFilter['radius'],
                        $geoField => [
                            'lat' => (float)$geoFilter['lat'],
                            'lon' => (float)$geoFilter['lng'],
                        ],
                    ],
                ];

                if (isset($body['query']['bool']['filter'])) {
                    $body['query']['bool']['filter'][] = $geoClause;
                } else {
                    $body = [
                        'query' => [
                            'bool' => [
                                'must' => [$body['query']],
                                'filter' => [$geoClause],
                            ],
                        ],
                    ];
                }
            }
        }

        $body['from'] = $from;
        $body['size'] = $size;

        // Unified sort → ES DSL: ['field' => 'asc'] → [['field' => ['order' => 'asc']]]
        if (!empty($sort)) {
            $body['sort'] = $this->buildNativeSortParams($sort, $index);
        }

        // Geo-distance sort: sort results by distance from a point
        if ($geoSort !== null) {
            $geoField = $this->detectGeoField($index);
            if ($geoField !== null) {
                $geoSortClause = [
                    '_geo_distance' => [
                        $geoField => [
                            'lat' => (float)$geoSort['lat'],
                            'lon' => (float)$geoSort['lng'],
                        ],
                        'order' => 'asc',
                        'unit' => 'km',
                    ],
                ];
                if (isset($body['sort'])) {
                    array_unshift($body['sort'], $geoSortClause);
                } else {
                    $body['sort'] = [$geoSortClause];
                }
            }
        }

        // Unified attributesToRetrieve → ES _source filter
        if ($attributesToRetrieve !== null) {
            $body['_source'] = $attributesToRetrieve;
        }

        // Unified highlight → ES highlight DSL
        if ($highlight !== null && !isset($remaining['highlight'])) {
            if ($highlight === true) {
                // Highlight all fields with a wildcard
                $body['highlight'] = ['fields' => ['*' => new \stdClass()]];
            } elseif (is_array($highlight)) {
                $highlightFields = [];
                foreach ($highlight as $field) {
                    $highlightFields[$field] = new \stdClass();
                }
                $body['highlight'] = ['fields' => $highlightFields];
            }
        } elseif (isset($remaining['highlight'])) {
            // Engine-native highlight — pass through
            $body['highlight'] = $remaining['highlight'];
        }

        // Unified suggest → ES phrase suggester
        // Requires an explicit text field — the _all meta-field was removed in ES 6.0.
        if ($suggest && $query !== '') {
            // Auto-detect suggest field: use explicit fields if given, otherwise
            // find the title field from index mappings, falling back to 'title'.
            $suggestField = null;
            if ($fields[0] !== '*') {
                $suggestField = $fields[0];
            } else {
                $suggestField = $this->detectSuggestField($index);
            }

            if ($suggestField !== null) {
                $body['suggest'] = [
                    'text' => $query,
                    'phrase_suggestion' => [
                        'phrase' => [
                            'field' => $suggestField,
                            'size' => 3,
                            'gram_size' => 3,
                            'direct_generator' => [[
                                'field' => $suggestField,
                                'suggest_mode' => 'missing',
                            ]],
                        ],
                    ],
                ];
            }
        }

        // Engine-native aggs take precedence over unified facets
        if (isset($remaining['aggs'])) {
            $body['aggs'] = $remaining['aggs'];
        } elseif (!empty($facets)) {
            $fieldTypeMap = $this->buildFieldTypeMap($index);
            $body['aggs'] = [];
            foreach ($facets as $field) {
                // Only text fields need the .keyword sub-field for term aggregations;
                // keyword, facet, integer, date, etc. use the base field name.
                $aggField = ($fieldTypeMap[$field] ?? '') === 'text' ? $field . '.keyword' : $field;
                $body['aggs'][$field] = [
                    'terms' => ['field' => $aggField, 'size' => $maxValuesPerFacet ?? 100],
                ];
            }
        }

        // Stats aggregations for numeric fields
        if (!empty($statsFields)) {
            if (!isset($body['aggs'])) {
                $body['aggs'] = [];
            }
            foreach ($statsFields as $field) {
                $body['aggs'][$field . '_stats'] = ['stats' => ['field' => $field]];
            }
        }

        // Histogram aggregations for numeric fields
        if (!empty($histogramConfig)) {
            if (!isset($body['aggs'])) {
                $body['aggs'] = [];
            }
            foreach ($histogramConfig as $field => $config) {
                $histogramAgg = [
                    'field' => $field,
                    'interval' => (float)$config['interval'],
                    'min_doc_count' => 0,
                ];
                if (isset($config['min'], $config['max'])) {
                    $histogramAgg['extended_bounds'] = [
                        'min' => (float)$config['min'],
                        'max' => (float)$config['max'],
                    ];
                }
                $body['aggs'][$field . '_histogram'] = ['histogram' => $histogramAgg];
            }
        }

        // Geo grid aggregation (geotile_grid) for map clustering
        if ($geoGrid !== null) {
            $geoField = $geoGrid['field'] ?? $this->detectGeoField($index);
            if ($geoField !== null) {
                if (!isset($body['aggs'])) {
                    $body['aggs'] = [];
                }
                $gridAgg = [
                    'field' => $geoField,
                    'precision' => $geoGrid['precision'],
                ];
                // Viewport bounds: limit aggregation to the visible map area
                if (isset($geoGrid['bounds'])) {
                    $gridAgg['bounds'] = $geoGrid['bounds'];
                }
                $body['aggs']['geo_grid'] = [
                    'geotile_grid' => $gridAgg,
                    'aggs' => [
                        'centroid' => [
                            'geo_centroid' => ['field' => $geoField],
                        ],
                        'sample' => [
                            'top_hits' => ['size' => 1],
                        ],
                    ],
                ];
            }
        }

        $responseArray = $this->responseToArray(
            $this->getClient()->search([
                'index' => $indexName,
                'body' => $body,
            ])
        );

        // Flatten _source, preserve _id/_score, normalise highlights.
        $rawHits = array_map([$this, 'normaliseRawHit'], $responseArray['hits']['hits'] ?? []);
        $hits = $this->normaliseHits($rawHits, '_id', '_score', null);

        $totalHits = $responseArray['hits']['total']['value'] ?? 0;

        // Normalise aggregations → unified facet shape
        $normalisedFacets = $this->normaliseRawFacets($responseArray);

        // Normalise stats aggregations
        $normalisedStats = $this->normaliseRawStats($responseArray, $statsFields);

        // Normalise histogram aggregations
        $normalisedHistograms = $this->normaliseRawHistograms($responseArray, $histogramConfig);

        // Normalise geo grid aggregation clusters
        $geoClusters = [];
        if ($geoGrid !== null && isset($responseArray['aggregations']['geo_grid']['buckets'])) {
            // Batch-collect all sample raw hits, normalise once, distribute back
            $rawSamples = [];
            $sampleBucketIndexes = [];
            foreach ($responseArray['aggregations']['geo_grid']['buckets'] as $bi => $bucket) {
                if (isset($bucket['sample']['hits']['hits'][0])) {
                    $sampleBucketIndexes[] = $bi;
                    $rawSamples[] = $this->normaliseRawHit($bucket['sample']['hits']['hits'][0]);
                }
            }
            $normalisedSamples = !empty($rawSamples)
                ? $this->normaliseHits($rawSamples, '_id', '_score', null)
                : [];
            // Map bucket index → normalised sample hit
            $sampleHitMap = [];
            foreach ($sampleBucketIndexes as $j => $bi) {
                $sampleHitMap[$bi] = $normalisedSamples[$j] ?? null;
            }

            foreach ($responseArray['aggregations']['geo_grid']['buckets'] as $bi => $bucket) {
                // Use the actual geo_centroid (average of documents in this tile)
                // instead of the tile centre — prevents markers jumping between zoom levels.
                if (isset($bucket['centroid']['location']['lat'], $bucket['centroid']['location']['lon'])) {
                    $lat = (float)$bucket['centroid']['location']['lat'];
                    $lng = (float)$bucket['centroid']['location']['lon'];
                } else {
                    // Fallback to tile centre if centroid unavailable
                    $fallback = $this->geotileToLatLng($bucket['key']);
                    $lat = $fallback['lat'];
                    $lng = $fallback['lng'];
                }

                $geoClusters[] = [
                    'lat' => $lat,
                    'lng' => $lng,
                    'count' => $bucket['doc_count'],
                    'key' => $bucket['key'],
                    'hit' => $sampleHitMap[$bi] ?? null,
                ];
            }
        }

        // Extract spelling suggestions from phrase suggester response
        $suggestions = [];
        foreach ($responseArray['suggest']['phrase_suggestion'] ?? [] as $entry) {
            foreach ($entry['options'] ?? [] as $option) {
                if (isset($option['text']) && $option['text'] !== $query) {
                    $suggestions[] = $option['text'];
                }
            }
        }

        return new SearchResult(
            hits: $hits,
            totalHits: $totalHits,
            page: $size > 0 ? (int)floor($from / $size) + 1 : 1,
            perPage: $size,
            totalPages: $this->computeTotalPages($totalHits, $size),
            facets: $normalisedFacets,
            stats: $normalisedStats,
            histograms: $normalisedHistograms,
            raw: $responseArray,
            suggestions: $suggestions,
            geoClusters: $geoClusters,
        );
    }

    /**
     * Native "More Like This" search using Elasticsearch/OpenSearch MLT query.
     *
     * @inheritdoc
     */
    public function relatedSearch(Index $index, string $documentId, int $perPage = 5, array $fields = []): SearchResult
    {
        $indexName = $this->getIndexName($index);

        // Determine fields for MLT: use provided fields or auto-detect text fields
        if (empty($fields)) {
            $fields = [];
            foreach ($index->getFieldMappings() as $mapping) {
                if ($mapping->enabled && $mapping->indexFieldType === FieldMapping::TYPE_TEXT) {
                    $fields[] = $mapping->indexFieldName;
                }
            }
        }

        if (empty($fields)) {
            return SearchResult::empty();
        }

        $body = [
            'query' => [
                'bool' => [
                    'must' => [
                        'more_like_this' => [
                            'fields' => $fields,
                            'like' => [
                                [
                                    '_index' => $indexName,
                                    '_id' => $documentId,
                                ],
                            ],
                            'min_term_freq' => 1,
                            'min_doc_freq' => 1,
                            'max_query_terms' => 25,
                        ],
                    ],
                    // Exclude the source document
                    'must_not' => [
                        'ids' => ['values' => [$documentId]],
                    ],
                ],
            ],
            'size' => $perPage,
        ];

        try {
            $responseArray = $this->responseToArray(
                $this->getClient()->search([
                    'index' => $indexName,
                    'body' => $body,
                ])
            );

            $rawHits = array_map([$this, 'normaliseRawHit'], $responseArray['hits']['hits'] ?? []);
            $hits = $this->normaliseHits($rawHits, '_id', '_score', null);
            $totalHits = $responseArray['hits']['total']['value'] ?? 0;

            return new SearchResult(
                hits: $hits,
                totalHits: min($totalHits, $perPage),
                page: 1,
                perPage: $perPage,
                totalPages: 1,
            );
        } catch (\Throwable $e) {
            // Fallback to keyword-extraction approach
            return parent::relatedSearch($index, $documentId, $perPage, $fields);
        }
    }

    // multiSearch() is intentionally NOT overridden here.
    // The parent AbstractEngine::multiSearch() loops individual search() calls,
    // which correctly supports all options (filters, facets, highlight, stats,
    // histograms, geo params, vector search, etc.). A native ES _msearch override
    // previously existed but silently dropped most options.

    /**
     * @inheritdoc
     */
    public function getDocumentCount(Index $index): int
    {
        $indexName = $this->getIndexName($index);

        $response = $this->responseToArray(
            $this->getClient()->count(['index' => $indexName])
        );

        return $response['count'] ?? 0;
    }

    /**
     * Retrieve all document IDs using search_after pagination.
     *
     * @param Index $index The index to query.
     * @return string[] Array of document ID strings.
     */
    public function getAllDocumentIds(Index $index): array
    {
        $indexName = $this->getIndexName($index);
        $ids = [];

        // Use search_after with _doc sort for efficient pagination
        $params = [
            'index' => $indexName,
            'body' => [
                'size' => 1000,
                'query' => ['match_all' => (object)[]],
                'sort' => ['_doc'],
                '_source' => false,
            ],
        ];

        $response = $this->responseToArray($this->getClient()->search($params));
        $hits = $response['hits']['hits'] ?? [];

        while (!empty($hits)) {
            foreach ($hits as $hit) {
                $ids[] = $hit['_id'];
            }

            $lastHit = end($hits);
            if (empty($lastHit['sort'])) {
                break;
            }
            $params['body']['search_after'] = $lastHit['sort'];
            $response = $this->responseToArray($this->getClient()->search($params));
            $hits = $response['hits']['hits'] ?? [];
        }

        return $ids;
    }

    /**
     * @inheritdoc
     */
    public function mapFieldType(string $indexFieldType): mixed
    {
        return match ($indexFieldType) {
            FieldMapping::TYPE_TEXT => 'text',
            FieldMapping::TYPE_KEYWORD => 'keyword',
            FieldMapping::TYPE_INTEGER => 'integer',
            FieldMapping::TYPE_FLOAT => 'float',
            FieldMapping::TYPE_BOOLEAN => 'boolean',
            FieldMapping::TYPE_DATE => 'date',
            FieldMapping::TYPE_GEO_POINT => 'geo_point',
            FieldMapping::TYPE_FACET => 'keyword',
            FieldMapping::TYPE_OBJECT => 'object',
            FieldMapping::TYPE_EMBEDDING => 'knn_vector',
            default => 'text',
        };
    }

    /**
     * @inheritdoc
     */
    public function buildSchema(array $fieldMappings): array
    {
        $properties = [];

        foreach ($fieldMappings as $mapping) {
            if (!$mapping instanceof FieldMapping || !$mapping->enabled) {
                continue;
            }

            $fieldName = $mapping->indexFieldName;
            $type = $this->mapFieldType($mapping->indexFieldType);

            $fieldDef = ['type' => $type];

            // Text fields with keyword sub-field for exact matching
            if ($type === 'text') {
                $fieldDef['fields'] = [
                    'keyword' => [
                        'type' => 'keyword',
                        'ignore_above' => 256,
                    ],
                ];
            }

            // Date fields with format
            if ($type === 'date') {
                $fieldDef['format'] = 'epoch_second||epoch_millis||strict_date_optional_time';
            }

            // Embedding fields require a dimension parameter
            // OpenSearch uses 'dimension', Elasticsearch uses 'dims'
            if (in_array($type, ['knn_vector', 'dense_vector'], true) && $mapping->indexFieldType === FieldMapping::TYPE_EMBEDDING) {
                $dimension = $mapping->resolverConfig['dimension'] ?? 1024;
                $dimKey = $type === 'dense_vector' ? 'dims' : 'dimension';
                $fieldDef[$dimKey] = (int)$dimension;
            }

            $properties[$fieldName] = $fieldDef;
        }

        return [
            'properties' => $properties,
        ];
    }

    /**
     * Convert unified sort to ES DSL: `['field' => 'asc']` → `[['field' => ['order' => 'asc']]]`.
     *
     * Text fields are automatically suffixed with `.keyword` since ES/OpenSearch
     * text fields cannot be sorted directly (they need the keyword sub-field).
     *
     * @inheritdoc
     */
    protected function buildNativeSortParams(array $sort, ?Index $index = null): mixed
    {
        if (empty($sort)) {
            return [];
        }

        if (!$this->isUnifiedSort($sort)) {
            return $sort;
        }

        // Build field type map so we know which fields are text (need .keyword for sorting)
        $fieldTypeMap = $index ? $this->buildFieldTypeMap($index) : [];

        $result = [];
        foreach ($sort as $field => $direction) {
            // Text fields need .keyword sub-field for sorting
            $sortField = ($fieldTypeMap[$field] ?? '') === 'text' ? $field . '.keyword' : $field;
            $result[] = [$sortField => ['order' => $direction]];
        }
        return $result;
    }

    /**
     * Convert unified filters to ES bool/filter clauses.
     *
     * @inheritdoc
     */
    protected function buildNativeFilterParams(array $filters, Index $index): mixed
    {
        if (empty($filters)) {
            return [];
        }

        $fieldTypeMap = $this->buildFieldTypeMap($index);
        $filterClauses = [];

        foreach ($filters as $field => $value) {
            if ($this->isRangeFilter($value)) {
                // Range queries target numeric/date fields — never use .keyword suffix
                $range = [];
                if (isset($value['min']) && $value['min'] !== '') {
                    $range['gte'] = $value['min'];
                }
                if (isset($value['max']) && $value['max'] !== '') {
                    $range['lte'] = $value['max'];
                }
                if (!empty($range)) {
                    $filterClauses[] = ['range' => [$field => $range]];
                }
            } else {
                // Only text fields need the .keyword sub-field for exact matching;
                // keyword, integer, date, boolean, etc. use the base field name.
                $filterField = ($fieldTypeMap[$field] ?? '') === 'text' ? $field . '.keyword' : $field;
                if (is_array($value)) {
                    $filterClauses[] = ['terms' => [$filterField => $value]];
                } else {
                    $filterClauses[] = ['term' => [$filterField => $value]];
                }
            }
        }

        return $filterClauses;
    }

    /**
     * Flatten an ES/OpenSearch hit: merge _source with _id/_score, normalise highlights.
     *
     * @inheritdoc
     */
    protected function normaliseRawHit(array $hit): array
    {
        $doc = array_merge(
            $hit['_source'] ?? [],
            [
                '_id' => $hit['_id'],
                '_score' => $hit['_score'],
            ]
        );
        $doc['_highlights'] = $this->normaliseHighlightData($hit['highlight'] ?? []);
        return $doc;
    }

    /**
     * Convert an engine response to a plain associative array.
     *
     * The Elasticsearch PHP client v8 returns a response object whose
     * `(array)` cast exposes private properties instead of the JSON body.
     * The OpenSearch client returns plain arrays. This method normalises
     * both to a plain array.
     *
     * @param mixed $response The engine response (object or array).
     * @return array The response as a plain associative array.
     */
    protected function responseToArray(mixed $response): array
    {
        if (is_object($response) && method_exists($response, 'asArray')) {
            return $response->asArray();
        }

        return (array)$response;
    }

    /**
     * Normalise ES/OpenSearch aggregation buckets into unified facet shape.
     *
     * @inheritdoc
     */
    protected function normaliseRawFacets(array $response): array
    {
        $normalised = [];
        foreach ($response['aggregations'] ?? [] as $field => $agg) {
            // Skip internal aggregation keys (geo_grid, stats, histograms)
            if ($field === 'geo_grid' || str_ends_with($field, '_stats') || str_ends_with($field, '_histogram')) {
                continue;
            }
            if (isset($agg['buckets'])) {
                $normalised[$field] = array_map(fn($bucket) => [
                    'value' => (string)$bucket['key'],
                    'count' => (int)$bucket['doc_count'],
                ], $agg['buckets']);
            }
        }
        return $normalised;
    }

    /**
     * Normalise ES/OpenSearch histogram aggregations into unified shape.
     *
     * @inheritdoc
     */
    protected function normaliseRawHistograms(array $response, array $histogramConfig = []): array
    {
        $normalised = [];
        foreach ($histogramConfig as $field => $config) {
            $buckets = $response['aggregations'][$field . '_histogram']['buckets'] ?? [];
            if (!empty($buckets)) {
                $normalised[$field] = array_map(fn(array $bucket) => [
                    'key' => $bucket['key'],
                    'count' => (int)$bucket['doc_count'],
                ], $buckets);
            }
        }
        return $normalised;
    }

    /**
     * Normalise ES/OpenSearch stats aggregations into unified shape.
     *
     * @inheritdoc
     */
    protected function normaliseRawStats(array $response, array $statsFields = []): array
    {
        $normalised = [];
        foreach ($statsFields as $field) {
            $statsData = $response['aggregations'][$field . '_stats'] ?? null;
            if ($statsData !== null && isset($statsData['min'], $statsData['max'])) {
                $normalised[$field] = [
                    'min' => (float)$statsData['min'],
                    'max' => (float)$statsData['max'],
                ];
            }
        }
        return $normalised;
    }

    /**
     * Build a map of index field names to their engine-native types.
     *
     * Used at search time to decide whether a field needs a `.keyword`
     * sub-field for exact matching (only text fields have one).
     *
     * @param Index $index
     * @return array<string, string> Map of fieldName => native type string.
     */
    protected function buildFieldTypeMap(Index $index): array
    {
        $handle = $index->handle;
        if (isset($this->_fieldTypeMapCache[$handle])) {
            return $this->_fieldTypeMapCache[$handle];
        }

        $map = [];
        foreach ($index->getFieldMappings() as $mapping) {
            if ($mapping instanceof FieldMapping && $mapping->enabled) {
                $map[$mapping->indexFieldName] = $this->mapFieldType($mapping->indexFieldType);
            }
        }
        return $this->_fieldTypeMapCache[$handle] = $map;
    }

    /**
     * Detect the best field for ES phrase suggester.
     * Prefers the ROLE_TITLE field, falls back to 'title'.
     */
    protected function detectSuggestField(Index $index): ?string
    {
        $handle = $index->handle;
        if (array_key_exists($handle, $this->_suggestFieldCache)) {
            return $this->_suggestFieldCache[$handle];
        }

        foreach ($index->getFieldMappings() as $mapping) {
            if ($mapping instanceof FieldMapping && $mapping->enabled && $mapping->role === FieldMapping::ROLE_TITLE) {
                return $this->_suggestFieldCache[$handle] = $mapping->indexFieldName;
            }
        }
        // Fallback — most indexes have a 'title' field
        return $this->_suggestFieldCache[$handle] = 'title';
    }

    // -- Facet value search ---------------------------------------------------

    /**
     * Search facet values using ES/OpenSearch terms aggregation with regex include.
     *
     * Instead of fetching all values and filtering client-side (the AbstractEngine
     * fallback), this uses the `include` regex parameter on the terms aggregation
     * to filter server-side. This correctly finds matching values in high-cardinality
     * facets where the desired value may not be in the top N by doc count.
     *
     * @inheritdoc
     */
    public function searchFacetValues(Index $index, array $facetFields, string $query, int $maxPerField = 5, array $filters = []): array
    {
        $indexName = $this->getIndexName($index);
        $fieldTypeMap = $this->buildFieldTypeMap($index);

        // Build aggregations — one per facet field
        $aggs = [];
        foreach ($facetFields as $field) {
            $aggField = ($fieldTypeMap[$field] ?? '') === 'text' ? $field . '.keyword' : $field;
            $termsDef = [
                'field' => $aggField,
                'size' => $maxPerField,
            ];

            // When a query is provided, use regex include for server-side filtering.
            // ES/OpenSearch regex is case-sensitive, so we build a case-insensitive
            // pattern using character classes: "rock" → ".*[rR][oO][cC][kK].*"
            if ($query !== '') {
                $termsDef['include'] = $this->buildCaseInsensitiveRegex($query);
            }

            $aggs[$field] = ['terms' => $termsDef];
        }

        // Build the search body — zero hits, aggregations only
        $body = [
            'size' => 0,
            'aggs' => $aggs,
        ];

        // Apply filters if provided (same logic as search())
        if (!empty($filters)) {
            $filterClauses = $this->buildNativeFilterParams($filters, $index);
            $body['query'] = [
                'bool' => [
                    'must' => [['match_all' => (object)[]]],
                    'filter' => $filterClauses,
                ],
            ];
        }

        $responseArray = $this->responseToArray(
            $this->getClient()->search([
                'index' => $indexName,
                'body' => $body,
            ])
        );

        $normalised = $this->normaliseRawFacets($responseArray);

        // Strip empty fields from result
        return array_filter($normalised, fn(array $values) => !empty($values));
    }

    /**
     * Build a case-insensitive regex for ES/OpenSearch terms aggregation include.
     *
     * ES/OpenSearch regex doesn't support case-insensitive flags, so we convert
     * each letter to a character class: "rock" → ".*[rR][oO][cC][kK].*"
     *
     * Non-letter characters are escaped for regex safety.
     *
     * @param string $query The search query.
     * @return string The regex pattern.
     */
    private function buildCaseInsensitiveRegex(string $query): string
    {
        $pattern = '.*';
        // Iterate over each character (multibyte-safe)
        $length = mb_strlen($query);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($query, $i, 1);
            $lower = mb_strtolower($char);
            $upper = mb_strtoupper($char);

            if ($lower !== $upper) {
                // Letter — build character class [aA]
                $pattern .= '[' . $this->escapeRegexChar($lower) . $this->escapeRegexChar($upper) . ']';
            } else {
                // Non-letter — escape for regex safety
                $pattern .= $this->escapeRegexChar($char);
            }
        }
        $pattern .= '.*';

        return $pattern;
    }

    /**
     * Escape a single character for ES/OpenSearch regex syntax.
     *
     * ES uses Lucene regex which treats these as special: . ? + * | { } [ ] ( ) " \ # @ & < > ~
     *
     * @param string $char A single character.
     * @return string The escaped character.
     */
    private function escapeRegexChar(string $char): string
    {
        // Lucene regex special characters
        if (str_contains('.?+*|{}[]()\"#@&<>~', $char)) {
            return '\\' . $char;
        }

        return $char;
    }

    // -- Alias helpers --------------------------------------------------------

    /**
     * Check whether an alias exists.
     *
     * Subclasses override this to handle client-specific return types
     * (Elasticsearch returns a Response requiring `->asBool()`).
     *
     * @param string $aliasName The alias name to check.
     * @return bool
     */
    protected function _aliasExists(string $aliasName): bool
    {
        try {
            return (bool)$this->getClient()->indices()->existsAlias(['name' => $aliasName]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the backing index name that an alias points to.
     *
     * @param string $aliasName The alias name.
     * @return string|null The backing index name, or null if no alias exists.
     */
    protected function _getAliasTarget(string $aliasName): ?string
    {
        try {
            $response = $this->_getAliasResponse($aliasName);

            // Response shape: { 'backing_index_name': { 'aliases': { 'alias_name': {} } } }
            foreach ($response as $indexName => $data) {
                if (isset($data['aliases'][$aliasName])) {
                    return $indexName;
                }
            }
        } catch (\Throwable $e) {
            // Alias doesn't exist
        }

        return null;
    }

    /**
     * Get the raw alias response from the engine.
     *
     * Subclasses override this to handle client-specific return types
     * (Elasticsearch returns a Response requiring `->asArray()`).
     *
     * @param string $aliasName The alias name.
     * @return array Raw response data.
     */
    protected function _getAliasResponse(string $aliasName): array
    {
        return (array)$this->getClient()->indices()->getAlias(['name' => $aliasName]);
    }

    /**
     * Check whether a direct index (not an alias) exists.
     *
     * Subclasses override this to handle client-specific return types.
     *
     * @param string $indexName The index name to check.
     * @return bool
     */
    protected function _directIndexExists(string $indexName): bool
    {
        try {
            return (bool)$this->getClient()->indices()->exists(['index' => $indexName]);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

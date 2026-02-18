<?php

/**
 * Search Index plugin for Craft CMS -- CP index health Sprig component.
 */

namespace cogapp\searchindex\sprig\components;

use cogapp\searchindex\SearchIndex;
use cogapp\searchindex\sprig\SprigBooleanTrait;
use Craft;
use putyourlightson\sprig\base\Component;

/**
 * Lazy-loads index health (document count + engine connectivity) for CP list rows.
 */
class IndexHealth extends Component
{
    use SprigBooleanTrait;

    /** @var int|null Index ID to inspect. */
    public ?int $indexId = null;

    /** @var bool|int|string Whether to run health checks on this request. */
    public bool|int|string $hydrate = false;

    /** @var bool|int|string Whether the index is enabled. */
    public bool|int|string $enabled = true;

    /** @var int|null Document count when available. */
    public ?int $docCount = null;

    /** @var bool|null Connectivity state. */
    public ?bool $connected = null;

    /** @var string|null Error message for debugging (no sensitive data). */
    public ?string $errorHint = null;

    /** @var int Cache TTL in seconds for health payload. */
    public int $cacheTtl = 45;

    /** @inheritdoc */
    protected ?string $_template = 'search-index/_sprig/index-health';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        if (!$this->shouldHydrate() || !$this->indexId) {
            return;
        }

        $cacheKey = "searchIndex:cp:indexHealth:{$this->indexId}";
        $cached = Craft::$app->getCache()->get($cacheKey);
        if (is_array($cached)) {
            $this->docCount = isset($cached['docCount']) ? (int)$cached['docCount'] : null;
            $this->connected = array_key_exists('connected', $cached) ? (bool)$cached['connected'] : null;
            return;
        }

        $docCount = null;
        $connected = null;

        try {
            $index = SearchIndex::$plugin->getIndexes()->getIndexById((int)$this->indexId);
            if ($index && class_exists($index->engineType)) {
                $engine = $index->createEngine();
                $connected = $engine->testConnection();
                if ($connected && $engine->indexExists($index)) {
                    $docCount = $engine->getDocumentCount($index);
                }
            }
        } catch (\Throwable $e) {
            $connected = false;
            // Expose error class + message for debugging (no credentials)
            $this->errorHint = get_class($e) . ': ' . $e->getMessage();
            Craft::warning("Failed to load index health for index ID {$this->indexId}: {$e->getMessage()}", __METHOD__);
        }

        $this->docCount = $docCount === null ? null : (int)$docCount;
        $this->connected = $connected;

        Craft::$app->getCache()->set($cacheKey, [
            'docCount' => $this->docCount,
            'connected' => $this->connected,
        ], $this->cacheTtl);
    }

    /**
     * Returns whether this request should execute health checks.
     */
    private function shouldHydrate(): bool
    {
        return $this->toBool($this->hydrate);
    }
}

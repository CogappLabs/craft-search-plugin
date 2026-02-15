<?php

/**
 * Search Index plugin for Craft CMS -- Sprig test connection component.
 */

namespace cogapp\searchindex\sprig\components;

use cogapp\searchindex\engines\AlgoliaEngine;
use cogapp\searchindex\engines\EngineInterface;
use cogapp\searchindex\sprig\SprigBooleanTrait;
use putyourlightson\sprig\base\Component;

/**
 * Sprig component class for testing engine connection in the CP.
 *
 * @author cogapp
 * @since 1.0.0
 */
class TestConnection extends Component
{
    use SprigBooleanTrait;

    /**
     * @var bool|int|string Whether the connection test should run.
     */
    public bool|int|string $run = false;

    /**
     * @var string|null Fully-qualified engine class name.
     */
    public ?string $engineType = null;

    /**
     * @var array<string, mixed> Engine configuration values.
     */
    public array $engineConfig = [];

    /**
     * @var string Current index mode from the edit form.
     */
    public string $mode = 'synced';

    /**
     * @var string Current index handle from the edit form.
     */
    public string $handle = '';

    /**
     * @var array{success: bool, message: string}|null Connection test result.
     */
    public ?array $result = null;

    /**
     * @inheritdoc
     */
    protected ?string $_template = 'search-index/_sprig/test-connection';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        if (!$this->shouldRun()) {
            return;
        }

        if (empty($this->engineType)) {
            $this->result = [
                'success' => false,
                'message' => 'Please select an engine first.',
            ];

            return;
        }

        $engineType = (string)$this->engineType;

        if (!class_exists($engineType) || !is_subclass_of($engineType, EngineInterface::class)) {
            $this->result = [
                'success' => false,
                'message' => 'Invalid engine type.',
            ];

            return;
        }

        if (!$engineType::isClientInstalled()) {
            $this->result = [
                'success' => false,
                'message' => 'Client library not installed. Run: composer require ' . $engineType::requiredPackage(),
            ];

            return;
        }

        @set_time_limit(10);

        $engineConfig = $this->engineConfig;

        // Provide context needed for read-only connection checks.
        $engineConfig['__mode'] = $this->mode;
        $engineConfig['__handle'] = $this->handle;

        if ($engineType === AlgoliaEngine::class && $this->mode === 'readonly' && trim($this->handle) === '') {
            $this->result = [
                'success' => false,
                'message' => 'Set a handle to test read-only connection.',
            ];

            return;
        }

        $engine = new $engineType($engineConfig);

        try {
            $ok = $engine->testConnection();
            $this->result = [
                'success' => $ok,
                'message' => $ok ? 'Connection successful.' : 'Connection failed.',
            ];
        } catch (\Throwable $e) {
            $this->result = [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Returns whether testing should run for the current request.
     */
    private function shouldRun(): bool
    {
        return $this->toBool($this->run);
    }
}

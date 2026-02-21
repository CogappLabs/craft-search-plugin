<?php

/**
 * Service for managing engine credential overrides stored in the database.
 */

namespace cogapp\searchindex\services;

use cogapp\searchindex\records\EngineOverrideRecord;
use craft\helpers\Json;
use yii\base\Component;

/**
 * Reads and writes engine credential overrides to the database, bypassing
 * project config entirely. This allows environment-specific engine settings
 * (hosts, API keys) to be configured even when allowAdminChanges is false.
 *
 * @author cogapp
 * @since 1.0.0
 */
class EngineOverrides extends Component
{
    /**
     * Cached overrides for the current request.
     *
     * @var array|null
     */
    private ?array $_cache = null;

    /**
     * Return all engine overrides from the database.
     *
     * @return array Key-value pairs of engine credential overrides
     */
    public function getOverrides(): array
    {
        if ($this->_cache !== null) {
            return $this->_cache;
        }

        $record = EngineOverrideRecord::find()->one();

        if (!$record) {
            $this->_cache = [];
            return $this->_cache;
        }

        $settings = $record->settings;

        if (is_string($settings)) {
            $settings = Json::decodeIfJson($settings);
        }

        $this->_cache = is_array($settings) ? $settings : [];

        return $this->_cache;
    }

    /**
     * Save engine overrides to the database (upsert into single row).
     *
     * @param array $overrides Key-value pairs to store
     * @return bool Whether the save was successful
     */
    public function saveOverrides(array $overrides): bool
    {
        $record = EngineOverrideRecord::find()->one();

        if (!$record) {
            $record = new EngineOverrideRecord();
        }

        // Remove empty values so they fall back to project config
        $overrides = array_filter($overrides, static fn($v) => $v !== '' && $v !== null);

        $record->settings = $overrides;
        $saved = $record->save();

        if ($saved) {
            $this->_cache = $overrides;
        }

        return $saved;
    }
}

<?php

/**
 * Search Index plugin for Craft CMS -- Main plugin class.
 */

namespace cogapp\searchindex;

use cogapp\searchindex\base\PluginTrait;
use cogapp\searchindex\models\Settings;
use cogapp\searchindex\services\Indexes;
use cogapp\searchindex\variables\SearchIndexVariable;
use Craft;
use craft\base\Plugin;
use craft\events\ElementEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Elements;
use craft\services\ProjectConfig;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Search Index plugin for Craft CMS.
 *
 * Provides configurable search engine integration with automatic element
 * synchronisation, field mapping, and bulk indexing via the queue.
 *
 * @property-read Indexes    $indexes
 * @property-read \cogapp\searchindex\services\FieldMapper $fieldMapper
 * @property-read \cogapp\searchindex\services\Sync        $sync
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchIndex extends Plugin
{
    use PluginTrait;

    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;
    public string $schemaVersion = '1.1.0';

    /** @var SearchIndex Static reference to the plugin instance. */
    public static SearchIndex $plugin;

    /**
     * Initialise the plugin, registering routes, project config listeners,
     * element event listeners, and template variables.
     *
     * @return void
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->_registerCpRoutes();
        $this->_registerProjectConfigListeners();
        $this->_registerElementListeners();
        $this->_registerVariables();
    }

    /**
     * Return the control panel navigation item with subnav links.
     *
     * @return array|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Search Index';
        $item['subnav'] = [
            'indexes' => ['label' => 'Indexes', 'url' => 'search-index'],
            'settings' => ['label' => 'Settings', 'url' => 'search-index/settings'],
        ];

        return $item;
    }

    /**
     * Create the plugin settings model.
     *
     * @return Settings|null
     */
    protected function createSettingsModel(): ?Settings
    {
        return new Settings();
    }

    /**
     * Render the plugin settings HTML.
     *
     * @return string|null
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('search-index/settings/index', [
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * Register CP URL routing rules for the plugin.
     *
     * @return void
     */
    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['search-index'] = 'search-index/indexes/index';
                $event->rules['search-index/indexes/new'] = 'search-index/indexes/edit';
                $event->rules['search-index/indexes/<indexId:\d+>'] = 'search-index/indexes/edit';
                $event->rules['search-index/indexes/<indexId:\d+>/fields'] = 'search-index/field-mappings/edit';
                $event->rules['search-index/settings'] = 'search-index/indexes/settings';
            }
        );
    }

    /**
     * Register project config add/update/remove listeners for index definitions.
     *
     * @return void
     */
    private function _registerProjectConfigListeners(): void
    {
        Craft::$app->getProjectConfig()
            ->onAdd(Indexes::CONFIG_KEY . '.{uid}', [$this->getIndexes(), 'handleChangedIndex'])
            ->onUpdate(Indexes::CONFIG_KEY . '.{uid}', [$this->getIndexes(), 'handleChangedIndex'])
            ->onRemove(Indexes::CONFIG_KEY . '.{uid}', [$this->getIndexes(), 'handleDeletedIndex']);

        Event::on(
            ProjectConfig::class,
            ProjectConfig::EVENT_REBUILD,
            function(RebuildConfigEvent $event) {
                $event->config['searchIndex'] = $this->getIndexes()->rebuildProjectConfig();
            }
        );
    }

    /**
     * Register element save, delete, restore, and slug-change listeners for real-time sync.
     *
     * @return void
     */
    private function _registerElementListeners(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function(ElementEvent $event) {
                $this->getSync()->handleElementSave($event);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_RESTORE_ELEMENT,
            function(ElementEvent $event) {
                $this->getSync()->handleElementSave($event);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_DELETE_ELEMENT,
            function(ElementEvent $event) {
                $this->getSync()->handleElementDelete($event);
            }
        );

        // Re-index when slugs/URIs change (e.g. parent slug changed)
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI,
            function(ElementEvent $event) {
                $this->getSync()->handleSlugChange($event);
            }
        );
    }

    /**
     * Register the Twig variable class for front-end template access.
     *
     * @return void
     */
    private function _registerVariables(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                $event->sender->set('searchIndex', SearchIndexVariable::class);
            }
        );
    }
}

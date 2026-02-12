<?php

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

class SearchIndex extends Plugin
{
    use PluginTrait;

    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;
    public string $schemaVersion = '1.1.0';

    public static SearchIndex $plugin;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->_registerCpRoutes();
        $this->_registerProjectConfigListeners();
        $this->_registerElementListeners();
        $this->_registerVariables();
    }

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

    protected function createSettingsModel(): ?Settings
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('search-index/settings/index', [
            'settings' => $this->getSettings(),
        ]);
    }

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

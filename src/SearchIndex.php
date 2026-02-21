<?php

/**
 * Search Index plugin for Craft CMS -- Main plugin class.
 */

namespace cogapp\searchindex;

use cogapp\searchindex\base\PluginTrait;
use cogapp\searchindex\fields\SearchDocumentField;
use cogapp\searchindex\gql\queries\SearchIndex as SearchIndexQueries;
use cogapp\searchindex\models\Settings;
use cogapp\searchindex\services\Indexes;
use cogapp\searchindex\variables\SearchIndexVariable;
use cogapp\searchindex\web\twig\SearchIndexTwigExtension;
use Craft;
use craft\base\Plugin;
use craft\events\ElementEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gql;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\utilities\ClearCaches;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use putyourlightson\sprig\Sprig;
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
 * @property-read \cogapp\searchindex\services\VoyageClient $voyageClient
 * @property-read \cogapp\searchindex\services\ResponsiveImages $responsiveImages
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchIndex extends Plugin
{
    use PluginTrait;

    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;
    public string $schemaVersion = '1.3.0';

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

        Sprig::bootstrap();

        $this->_registerCpRoutes();
        $this->_registerSiteRoutes();
        $this->_registerApiRoutes();
        $this->_registerProjectConfigListeners();
        $this->_registerElementListeners();
        $this->_registerTemplateRoots();
        $this->_registerVariables();
        $this->_registerTwigExtensions();
        $this->_registerFieldTypes();
        $this->_registerGraphQl();
        $this->_registerCacheOptions();
        $this->_registerPermissions();
    }

    /**
     * Return the control panel navigation item with subnav links.
     *
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Search Index';

        $subnav = [];
        $user = Craft::$app->getUser()->getIdentity();
        $canManage = $user && ($user->admin || $user->can('searchIndex-manageIndexes'));

        if ($canManage) {
            $subnav['indexes'] = ['label' => 'Indexes', 'url' => 'search-index'];
        }

        $subnav['search'] = ['label' => 'Search', 'url' => 'search-index/search'];

        if ($canManage) {
            $subnav['settings'] = ['label' => 'Settings', 'url' => 'search-index/settings'];
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    /**
     * Return the plugin's settings model, typed to the concrete Settings class.
     *
     * Overrides the parent to narrow the return type from `craft\base\Model`
     * to `Settings`, so callers can access custom properties and methods
     * (e.g. `getEffective()`, `$syncOnSave`) without PHPStan errors.
     *
     * @return Settings
     */
    public function getSettings(): Settings
    {
        /** @var Settings */
        return parent::getSettings();
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
     * Redirect to our custom settings page instead of using Craft's built-in
     * plugin settings rendering. Our settings page has two forms (engine
     * connections + general settings) with different save targets.
     *
     * @return mixed
     */
    public function getSettingsResponse(): mixed
    {
        /** @var \craft\web\Response $response */
        $response = Craft::$app->getResponse();

        return $response->redirect(
            UrlHelper::cpUrl('search-index/settings')
        );
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
                $event->rules['search-index/indexes/<indexId:\d+>/structure'] = 'search-index/indexes/structure-page';
                $event->rules['search-index/search'] = 'search-index/indexes/search-page';
                $event->rules['search-index/settings'] = 'search-index/indexes/settings';
            }
        );
    }

    /**
     * Register site URL routing rules for frontend demos/examples.
     *
     * @return void
     */
    private function _registerSiteRoutes(): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            return;
        }

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['search-sprig--default-components'] = 'search-index/demo/default-components';
            }
        );
    }

    /**
     * Register public REST API routes (always available, not gated by devMode).
     *
     * @return void
     */
    private function _registerApiRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['search-index/api/search'] = 'search-index/api/search';
                $event->rules['search-index/api/autocomplete'] = 'search-index/api/autocomplete';
                $event->rules['search-index/api/facet-values'] = 'search-index/api/facet-values';
                $event->rules['search-index/api/meta'] = 'search-index/api/meta';
                $event->rules['search-index/api/document'] = 'search-index/api/document';
                $event->rules['search-index/api/multi-search'] = 'search-index/api/multi-search';
                $event->rules['search-index/api/related'] = 'search-index/api/related';
                $event->rules['search-index/api/stats'] = 'search-index/api/stats';
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
                /** @var CraftVariable $sender */
                $sender = $event->sender;
                $sender->set('searchIndex', SearchIndexVariable::class);
            }
        );
    }

    /**
     * Register plugin templates for site requests so Sprig AJAX renders can resolve them.
     *
     * @return void
     */
    private function _registerTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['search-index'] = $this->getBasePath() . DIRECTORY_SEPARATOR . 'templates';
            }
        );
    }

    /**
     * Register Twig extensions used by this plugin.
     *
     * @return void
     */
    private function _registerTwigExtensions(): void
    {
        Craft::$app->getView()->registerTwigExtension(new SearchIndexTwigExtension());
    }

    /**
     * Register the Search Document custom field type.
     *
     * @return void
     */
    private function _registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = SearchDocumentField::class;
            }
        );
    }

    /**
     * Register GraphQL queries for the search index.
     *
     * @return void
     */
    private function _registerGraphQl(): void
    {
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            function(RegisterGqlQueriesEvent $event) {
                $event->queries = array_merge(
                    $event->queries,
                    SearchIndexQueries::getQueries()
                );
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            function(RegisterGqlSchemaComponentsEvent $event) {
                $label = 'Search Index';
                $queryComponents = [];
                foreach ($this->getIndexes()->getAllIndexes() as $index) {
                    $queryComponents["searchindex.{$index->uid}:read"] = [
                        'label' => "Query the \"{$index->name}\" search index",
                    ];
                }
                $event->queries[$label] = $queryComponents;
            }
        );
    }

    /**
     * Register the Search Index cache option in the CP Clear Caches utility.
     *
     * @return void
     */
    private function _registerCacheOptions(): void
    {
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function(RegisterCacheOptionsEvent $event) {
                $event->options[] = [
                    'key' => 'search-index',
                    'label' => 'Search Index data and API response caches',
                    'action' => function() {
                        $this->getIndexes()->invalidateCache();
                    },
                ];
            }
        );
    }

    /**
     * Register user permissions for managing search indexes and settings.
     *
     * @return void
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Search Index',
                    'permissions' => [
                        'searchIndex-manageIndexes' => [
                            'label' => 'Manage search indexes and settings',
                        ],
                    ],
                ];
            }
        );
    }
}

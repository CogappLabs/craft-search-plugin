<?php

/**
 * Search Index plugin for Craft CMS -- frontend demo pages controller.
 */

namespace cogapp\searchindex\controllers;

use cogapp\searchindex\SearchIndex;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Renders public demo pages for Search Index frontend patterns.
 *
 * @author cogapp
 * @since 1.0.0
 */
class DemoController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|int|bool $allowAnonymous = true;

    /**
     * Demo page for default frontend Sprig components.
     */
    public function actionDefaultComponents(): Response
    {
        $this->requireDevMode();

        $context = $this->buildDemoContext();

        return $this->renderTemplate('search-index/site/search-sprig-default-components', $context);
    }

    /**
     * Throws a 404 when not in dev mode.
     */
    private function requireDevMode(): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            throw new NotFoundHttpException(Craft::t('search-index', 'errors.pageNotFound'));
        }
    }

    /**
     * Shared context for demo pages.
     *
     * @return array<string, mixed>
     */
    private function buildDemoContext(): array
    {
        $indexes = SearchIndex::$plugin->getIndexes()->getAllIndexes();
        $indexOptions = [];

        foreach ($indexes as $index) {
            if (!$index->enabled) {
                continue;
            }

            $indexOptions[] = [
                'label' => $index->name,
                'value' => $index->handle,
            ];
        }

        $firstIndexHandle = $indexOptions[0]['value'] ?? '';
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $selectedIndex = (string)$request->getQueryParam('index', $firstIndexHandle);
        $validHandles = array_map(static fn(array $option): string => (string)$option['value'], $indexOptions);
        if (!in_array($selectedIndex, $validHandles, true)) {
            $selectedIndex = $firstIndexHandle;
        }

        return [
            'indexOptions' => $indexOptions,
            'firstIndexHandle' => $firstIndexHandle,
            'selectedIndex' => $selectedIndex,
            'isDevMode' => Craft::$app->getConfig()->getGeneral()->devMode,
        ];
    }
}

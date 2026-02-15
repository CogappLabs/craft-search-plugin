<?php

namespace cogapp\searchindex\web\assets\searchpage;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class SearchPageAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $css = [
        'search-page.css',
    ];
}

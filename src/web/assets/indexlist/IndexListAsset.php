<?php

namespace cogapp\searchindex\web\assets\indexlist;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class IndexListAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = [
        'index-list.js',
    ];
}

<?php

namespace cogapp\searchindex\web\assets\indexstructure;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class IndexStructureAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = [
        'index-structure.js',
    ];
}

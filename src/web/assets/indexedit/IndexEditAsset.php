<?php

namespace cogapp\searchindex\web\assets\indexedit;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class IndexEditAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = [
        'index-edit.js',
    ];
}

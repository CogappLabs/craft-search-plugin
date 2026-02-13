<?php

namespace cogapp\searchindex\web\assets\fieldmappings;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class FieldMappingsAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = [
        'field-mappings.js',
    ];

    public $css = [
        'field-mappings.css',
    ];
}

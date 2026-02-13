<?php

namespace cogapp\searchindex\web\assets\searchdocumentfield;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class SearchDocumentFieldAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = [
        'search-document-field.js',
    ];

    public $css = [
        'search-document-field.css',
    ];
}

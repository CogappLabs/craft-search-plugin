<?php

use craft\console\Application;

define('CRAFT_BASE_PATH', '/var/www/html');
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

require CRAFT_VENDOR_PATH . '/autoload.php';

$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
$app->init();

$index = \cogapp\searchindex\SearchIndex::$plugin->getIndexes()->getIndexByHandle('places_meili');
$engine = $index->createEngine();
$doc = $engine->getDocument($index, '227351');
echo "getDocument() result:\n";
echo json_encode($doc, JSON_PRETTY_PRINT) . "\n";

echo "\nRole map:\n";
foreach ($index->getFieldMappings() as $mapping) {
    if ($mapping->enabled && $mapping->role !== null) {
        echo "  {$mapping->role} => {$mapping->indexFieldName}\n";
    }
}

echo "\nimage_image value: " . ($doc['image_image'] ?? 'NOT PRESENT') . "\n";

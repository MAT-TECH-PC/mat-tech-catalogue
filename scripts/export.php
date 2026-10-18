<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$snapshot=catalogue_export(catalogue_read(catalogue_root().'/data/state.json'));
echo count($snapshot['products']).' références exportées ; les dates des relevés sont conservées.'.PHP_EOL;

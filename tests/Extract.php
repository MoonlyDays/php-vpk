<?php

use MoonlyDays\VPK\VpkArchive;

require_once __DIR__ . '/../vendor/autoload.php';

$archive = new VpkArchive(__DIR__.'/tf2_misc_dir.vpk');
$archive->extractTo(__DIR__);

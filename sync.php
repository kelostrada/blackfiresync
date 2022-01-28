<?php

define('_PS_MODE_DEV_', true);

require_once __DIR__ . '/../../config/config.inc.php';
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/vendor/autoload.php';

use Blackfire\BlackfireSyncService;

BlackfireSyncService::syncProducts();
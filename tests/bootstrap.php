<?php
// Container environment is also present in $_SERVER; PHPUnit <env> alone does not replace it.
foreach (['APP_ENV'=>'testing','DB_CONNECTION'=>'pgsql','DB_DATABASE'=>'cclub_test','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','TELEGRAM_TRANSPORT'=>'fake'] as $key=>$value) {
    putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
}
require dirname(__DIR__).'/vendor/autoload.php';

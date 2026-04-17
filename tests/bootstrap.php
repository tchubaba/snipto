<?php

// Force APP_ENV=testing into all env superglobals before the app boots.
// Without this, Docker's container-level APP_ENV (e.g. "local") wins over
// phpunit.xml's <env> setting because phpdotenv reads $_SERVER directly.
$_SERVER['APP_ENV'] = 'testing';
$_ENV['APP_ENV']    = 'testing';
putenv('APP_ENV=testing');

require __DIR__ . '/../vendor/autoload.php';

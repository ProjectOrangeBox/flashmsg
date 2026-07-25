<?php

define('__ROOT__', realpath(__DIR__ . '/../'));
define('__WWW__', realpath(__DIR__ . '/../htdocs'));

// two layouts: a standalone clone carries its own vendor/ directory; a clone
// developed in place inside an application's vendor tree finds its siblings
// two directories up (vendor/orange/*) and the autoloader three up
$standalone = is_dir(__DIR__ . '/../vendor');

$frameworkSrc = $standalone
    ? __DIR__ . '/../vendor/orange/framework/src'
    : __DIR__ . '/../../framework/src';

// Flashmsg uses ConfigurationTrait and the framework Input/Output/Data
// services, which call logMsg()/isLogEnabled() - those helpers are normally
// loaded at runtime by Application::preContainer() via dynamic include_once,
// not composer autoload. All are safe without a booted container.
require $frameworkSrc . '/helpers/helpers.php';
require $frameworkSrc . '/helpers/errors.php';
require $frameworkSrc . '/helpers/wrappers.php';

require $standalone
    ? __DIR__ . '/../vendor/autoload.php'
    : __DIR__ . '/../../../autoload.php';

// the tests borrow the framework's real output config - the Output stub's own
// config directory is missing required keys (status codes, mimes, ...)
define('__FRAMEWORK_SRC__', $frameworkSrc);


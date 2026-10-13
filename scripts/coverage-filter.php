<?php

declare(strict_types=1);

// Install before Composer and PHPUnit load: Xdebug filters files when they are compiled.
if (function_exists('xdebug_set_filter')) {
    xdebug_set_filter(XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, [dirname(__DIR__).'/src/']);
}

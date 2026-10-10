<?php

declare(strict_types=1);

use Msstc4Symfony\DtoGeneratorBridge\Test\Support\TestRunTemporaryDirectory;

// Loaded through autoload-dev "files" because the standard's phpunit.xml.dist bootstraps vendor/autoload.php only;
// bin/phpunit defines the constant before it loads the autoloader, so other tools keep the system directory.
if (defined('PHPUNIT_COMPOSER_INSTALL')) {
    TestRunTemporaryDirectory::isolate(__DIR__ . '/../var/tmp');
}

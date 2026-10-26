<?php

namespace Config;

/**
 * This class is loaded before the framework autoloader is available,
 * so it must remain a standalone configuration object.
 */
class Paths
{
    public string $systemDirectory = __DIR__ . '/../../vendor/codeigniter4/framework/system';
    public string $appDirectory = __DIR__ . '/..';
    public string $writableDirectory = __DIR__ . '/../../writable';
    public string $testsDirectory = __DIR__ . '/../../tests';
    public string $viewDirectory = __DIR__ . '/../Views';
    public string $envDirectory = __DIR__ . '/../../';
}

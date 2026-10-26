<?php

namespace Config;

use CodeIgniter\Config\AutoloadConfig;

class Autoload extends AutoloadConfig
{
    public $psr4 = [
        APP_NAMESPACE => APPPATH,
        'App\\Modules' => APPPATH . 'Modules',
    ];

    public $classmap = [];
    public $files = [];
    public $helpers = ['url', 'form', 'text', 'security', 'auth', 'setting'];
}

<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class App extends BaseConfig
{
    public string $baseURL = 'http://localhost:8092/';
    public array $allowedHostnames = [];
    public string $indexPage = '';
    public string $uriProtocol = 'REQUEST_URI';
    public string $permittedURIChars = 'a-z 0-9~%.:_\\-';
    public string $defaultLocale = 'es';
    public bool $negotiateLocale = false;
    public array $supportedLocales = ['es', 'en'];
    public string $appTimezone = 'America/Mexico_City';
    public string $charset = 'UTF-8';
    public bool $forceGlobalSecureRequests = true;
    public array $proxyIPs = [];
    public bool $CSPEnabled = false;
}

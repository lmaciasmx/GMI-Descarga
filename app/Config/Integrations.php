<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Integrations extends BaseConfig
{
    public string $scaleDriver = 'simulator';
    public string $scaleMiddlewareUrl = 'http://127.0.0.1:8787';
    public string $scaleApiKey = '';
    public string $printerDriver = 'pdf';
    public string $printerMiddlewareUrl = 'http://127.0.0.1:8788';
    public string $printerApiKey = '';
    public int $timeoutSeconds = 5;
}

<?php

namespace Config;

use App\Common\Filters\ApiKeyFilter;
use App\Common\Filters\ApiPermissionFilter;
use App\Common\Filters\CsrfResponseHeaderFilter;
use App\Common\Filters\IdempotencyFilter;
use App\Common\Filters\OperationalScopeFilter;
use App\Common\Filters\RateLimitFilter;
use App\Common\Filters\SecurityHeadersFilter;
use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;
use CodeIgniter\Shield\Filters\AuthRates;
use CodeIgniter\Shield\Filters\ChainAuth;
use CodeIgniter\Shield\Filters\GroupFilter;
use CodeIgniter\Shield\Filters\PermissionFilter;
use CodeIgniter\Shield\Filters\SessionAuth;

class Filters extends BaseFilters
{
    public array $aliases = [
        'csrf' => CSRF::class,
        'toolbar' => DebugToolbar::class,
        'honeypot' => Honeypot::class,
        'invalidchars' => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'forcehttps' => ForceHTTPS::class,
        'pagecache' => PageCache::class,
        'performance' => PerformanceMetrics::class,
        'session' => SessionAuth::class,
        'chain' => ChainAuth::class,
        'auth-rates' => AuthRates::class,
        'group' => GroupFilter::class,
        'permission' => PermissionFilter::class,
        'scope' => OperationalScopeFilter::class,
        'apiKey' => ApiKeyFilter::class,
        'apiPermission' => ApiPermissionFilter::class,
        'idempotency' => IdempotencyFilter::class,
        'rateLimit' => RateLimitFilter::class,
        'securityHeaders' => SecurityHeadersFilter::class,
        'csrfResponseHeader' => CsrfResponseHeaderFilter::class,
    ];

    public array $required = [
        'before' => [
            'pagecache',
        ],
        'after' => [
            'pagecache',
            'performance',
            'toolbar',
        ],
    ];

    public array $globals = [
        'before' => [
            'csrf' => [
                'except' => [
                    'api/*',
                ],
            ],
            'invalidchars',
        ],

        'after' => [
            'securityHeaders',
            'csrfResponseHeader',
        ],
    ];

    public array $methods = [];

    public array $filters = [];

    public function __construct()
    {
        parent::__construct();

        // HTTPS obligatorio excepto en desarrollo local.
       /* if (ENVIRONMENT !== 'development') {
            array_unshift($this->required['before'], 'forcehttps');
        }*/
        if (config('App')->forceGlobalSecureRequests) {
            array_unshift($this->required['before'], 'forcehttps');
        }
    }
}

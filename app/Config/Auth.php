<?php

namespace Config;

use CodeIgniter\Shield\Config\Auth as ShieldAuth;

class Auth extends ShieldAuth
{
    public array $views = [
        'login'                       => 'auth/login',
        'register'                    => '\CodeIgniter\Shield\Views\register',
        'layout'                      => 'auth/layout',
        'action_email_2fa'            => '\CodeIgniter\Shield\Views\email_2fa_show',
        'action_email_2fa_verify'     => '\CodeIgniter\Shield\Views\email_2fa_verify',
        'action_email_2fa_email'      => '\CodeIgniter\Shield\Views\Email\email_2fa_email',
        'action_email_activate_show'  => '\CodeIgniter\Shield\Views\email_activate_show',
        'action_email_activate_email' => '\CodeIgniter\Shield\Views\Email\email_activate_email',
        'magic-link-login'            => '\CodeIgniter\Shield\Views\magic_link_form',
        'magic-link-message'          => '\CodeIgniter\Shield\Views\magic_link_message',
        'magic-link-email'            => '\CodeIgniter\Shield\Views\Email\magic_link_email',
    ];

    public array $redirects = [
        'register'          => 'login',
        'login'             => 'home',
        'logout'            => 'login',
        'force_reset'       => 'login/magic-link',
        'permission_denied' => 'home',
        'group_denied'      => 'home',
    ];

    public bool $allowRegistration = false;
    public bool $recordActiveDate = true;
    public bool $allowMagicLinkLogins = true;
    public int $magicLinkLifetime = 1800;
    public int $minimumPasswordLength = 12;
    public array $validFields = ['email', 'username'];

    public array $sessionConfig = [
        'field' => 'user',
        'allowRemembering' => false,
        'rememberCookieName' => 'gmar_remember',
        'rememberLength' => 7 * DAY,
    ];
}
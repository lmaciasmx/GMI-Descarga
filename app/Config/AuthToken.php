<?php

namespace Config;

use CodeIgniter\Shield\Config\Auth as ShieldAuth;
use CodeIgniter\Shield\Config\AuthToken as ShieldAuthToken;

class AuthToken extends ShieldAuthToken
{
    public int $recordLoginAttempt = ShieldAuth::RECORD_LOGIN_ATTEMPT_ALL;
    public int $unusedTokenLifetime = 30 * DAY;
}

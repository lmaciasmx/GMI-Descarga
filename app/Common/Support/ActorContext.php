<?php

namespace App\Common\Support;

use CodeIgniter\HTTP\RequestInterface;

final class ActorContext
{
    public static function id(?RequestInterface $request = null): int
    {
        if (function_exists('auth') && auth()->loggedIn()) {
            return (int) auth()->id();
        }

        // An API client is a service actor. Never trust a caller-supplied user ID
        // without an independently verified identity mapping.
        return 0;
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Security headers (P8-01, PRD §21)
    |--------------------------------------------------------------------------
    | HSTS is only ever sent in production (a local http:// dev host must never be pinned to https).
    */
    'headers' => [
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Login lockout (T9)
    |--------------------------------------------------------------------------
    | On top of Fortify's per-minute `login` throttle: after `max_attempts` failed sign-ins for one
    | e-mail the account refuses even the right password until the window (`lock_minutes`, counted
    | from the first failure) elapses. Cache-based: nothing is stored in the database.
    */
    'login_lockout' => [
        'max_attempts' => (int) env('SECURITY_LOGIN_MAX_ATTEMPTS', 10),
        'lock_minutes' => (int) env('SECURITY_LOGIN_LOCK_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mandatory two-factor (T1)
    |--------------------------------------------------------------------------
    | Roles listed here cannot use the app until two-factor is confirmed. phpunit.xml pins `enforce`
    | off so the rest of the suite does not depend on it; the P8-01 tests turn it on explicitly.
    */
    'two_factor' => [
        'enforce' => (bool) env('SECURITY_ENFORCE_2FA', true),
        'roles' => ['owner', 'manager'],
    ],

];

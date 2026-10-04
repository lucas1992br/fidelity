<?php

use App\Providers\AppServiceProvider;

/**
 * Boot the application service provider again under the given environment.
 */
function bootAppServiceProviderAs(string $environment): void
{
    app()->detectEnvironment(fn (): string => $environment);

    (new AppServiceProvider(app()))->boot();
}

afterEach(function () {
    // Restore the static defaults (password rules, destructive commands) for the next tests.
    bootAppServiceProviderAs('testing');
});

test('urls are generated with https in production', function () {
    bootAppServiceProviderAs('production');

    expect(url('/'))->toStartWith('https://')
        ->and(route('login'))->toStartWith('https://');
});

test('urls keep the configured scheme outside production', function () {
    config(['app.url' => 'http://localhost']);
    bootAppServiceProviderAs('local');

    expect(url('/'))->toStartWith('http://');
});

test('the session cookie is secure when SESSION_SECURE_COOKIE is true', function () {
    putenv('SESSION_SECURE_COOKIE=true');
    $_ENV['SESSION_SECURE_COOKIE'] = $_SERVER['SESSION_SECURE_COOKIE'] = 'true';

    try {
        $sessionConfig = require config_path('session.php');
    } finally {
        putenv('SESSION_SECURE_COOKIE');
        unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
    }

    expect($sessionConfig['secure'])->toBeTrue();
});

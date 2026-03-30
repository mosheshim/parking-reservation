<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Create and bootstrap the Laravel application for the test process.
     *
     * Laravel's base testing classes expect the TestCase to provide a fully bootstrapped
     * application instance so Feature tests can resolve the HTTP kernel, load service
     * providers, configuration, routes, and the container bindings used during requests.
     */
    public function createApplication(): Application
    {
        $this->forceTestingEnvironment();

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Ensure the test process always boots with testing env + test database.
     *
     * This is required because config caching or container-level .env values can otherwise
     * cause PHPUnit to connect to the regular database and mutate real data.
     */
    private function forceTestingEnvironment(): void
    {
        $this->setEnvironmentVariable('APP_ENV', 'testing');
        $this->setEnvironmentVariable('DB_CONNECTION', 'pgsql');
        $this->setEnvironmentVariable('DB_DATABASE', 'parking_test');

        // Laravel loads cached configuration before reading environment variables.
        // Removing it ensures phpunit.xml env overrides are actually applied.
        $cachedConfigPath = __DIR__.'/../bootstrap/cache/config.php';
        if (is_file($cachedConfigPath)) {
            @unlink($cachedConfigPath);
        }
    }

    /**
     * Set an environment variable in all the places Laravel/PHPUnit can read from.
     */
    private function setEnvironmentVariable(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

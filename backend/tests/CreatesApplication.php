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
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}

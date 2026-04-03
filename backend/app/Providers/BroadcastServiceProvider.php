<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Register broadcasting auth routes and load channel authorization rules.
     * Required because this project uses a minimal provider bootstrap.
     */
    public function boot(): void
    {
        Broadcast::routes([
            'middleware' => ['jwt'],
            'prefix' => 'api',
        ]);

        require base_path('routes/channels.php');
    }
}

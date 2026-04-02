<?php

namespace App\Providers;

use App\Listeners\ReverbSlotsMessageListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Laravel\Reverb\Events\MessageReceived;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Map framework events to listeners.
     * Used to react to client-sent Reverb messages (snapshot request).
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        MessageReceived::class => [
            ReverbSlotsMessageListener::class,
        ],
    ];
}

<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\ReleaseStaleReservationsJob;

Schedule::job(new ReleaseStaleReservationsJob())
    ->everyMinute()
    ->withoutOverlapping();

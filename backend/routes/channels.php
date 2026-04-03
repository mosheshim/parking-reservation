<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('parking.slots.{date}', static function (User $user, string $date): bool {
    return true;
});

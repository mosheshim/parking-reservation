<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

//TODO do I need this?
Broadcast::channel('parking.user.{userId}', static function (User $user, string $userId): bool {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('parking.slots.{date}', static function (User $user, string $date): bool {
    return true;
});

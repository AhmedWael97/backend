<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Realtime analytics — private channel per domain
Broadcast::channel('domain.{domainId}', function ($user, $domainId) {
    return $user->domains()->where('id', (int) $domainId)->exists();
});

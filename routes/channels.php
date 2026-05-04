<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel( 'App.Models.User.{id}', function ( $user, $id ) {
    return (int) $user->id === (int) $id;
} );

Broadcast::channel( 'tenant.{tenantId}.chat.{pair}', function ( $user, string $tenantId, string $pair ) {
    if ( !function_exists( 'tenant' ) || !tenant() || (string) tenant()->id !== (string) $tenantId ) {
        return false;
    }
    $parts = explode( '_', $pair, 2 );
    if ( count( $parts ) !== 2 ) {
        return false;
    }
    $a = (int) $parts[0];
    $b = (int) $parts[1];
    $uid = (int) $user->id;

    return $uid === $a || $uid === $b;
} );

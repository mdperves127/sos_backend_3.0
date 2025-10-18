<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Illuminate\Support\Facades\DB;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    // Temporarily remove custom connection logic to isolate memory issues
    // public function getConnectionName()
    // {
    //     // Temporarily always use central database to isolate issues
    //     return env('DB_CONNECTION', 'mysql');
    // }

    // Temporarily disabled to isolate memory issues
    // /**
    //  * Find the token instance matching the given token.
    //  *
    //  * @param  string  $token
    //  * @return static|null
    //  */
    // public static function findToken($token)
    // {
    //     if (strpos($token, '|') === false) {
    //         return static::where('token', hash('sha256', $token))->first();
    //     }

    //     [$id, $token] = explode('|', $token, 2);

    //     if ($instance = static::find($id)) {
    //         return hash_equals($instance->token, hash('sha256', $token)) ? $instance : null;
    //     }
    // }


}

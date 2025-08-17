<?php

use App\Http\Controllers\API\User\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'isUser', 'userOnline'])->prefix('user')->group(function () {

    Route::get('profile', [ProfileController::class, 'index']);
    Route::post('update/profile', [ProfileController::class, 'updateprofile']);

});

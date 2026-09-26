<?php

use App\Http\Controllers\TwoFactorChallengeController;
use App\Http\Controllers\TwoFactorConfirmController;
use App\Http\Controllers\TwoFactorSetupController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Two-factor enrolment — P6-05, exit gate clause 4.
 *
 * Outside the Filament panel deliberately: the middleware that enforces the
 * clause redirects here, and a route living inside the panel it guards would
 * be guarded by it too.
 */
Route::middleware('auth')->group(function (): void {
    Route::get('/two-factor/setup', TwoFactorSetupController::class)
        ->name('two-factor.setup');

    Route::post('/two-factor/confirm', TwoFactorConfirmController::class)
        ->name('two-factor.confirm');

    // P6-05a. The challenge, asked once per session.
    Route::get('/two-factor/challenge', [TwoFactorChallengeController::class, 'show'])
        ->name('two-factor.challenge');

    Route::post('/two-factor/challenge', [TwoFactorChallengeController::class, 'verify'])
        ->name('two-factor.challenge.verify');
});

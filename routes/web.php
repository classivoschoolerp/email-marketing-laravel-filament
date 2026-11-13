<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\EmailChangeController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Site root
|--------------------------------------------------------------------------
| A single-company installation has no public website: the root is a small
| page that links to the workspace and administrator sign-ins.
*/
Route::view('/', 'public.home')
    ->middleware('throttle:60,1')
    ->name('public.home');

/*
|--------------------------------------------------------------------------
| Customer 2FA challenge (spec §1.3 — after the password step)
|--------------------------------------------------------------------------
*/
Route::get('/customer/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])
    ->name('customer.two-factor');
Route::post('/customer/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
    ->middleware('throttle:12,1')
    ->name('customer.two-factor.verify');

/*
|--------------------------------------------------------------------------
| Customer forgot / reset password (spec §1.4)
|--------------------------------------------------------------------------
*/
Route::get('/password/forgot', [PasswordResetLinkController::class, 'create'])->name('password.request');
Route::post('/password/email', [PasswordResetLinkController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('password.email');
Route::get('/password/reset/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
Route::post('/password/reset', [NewPasswordController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('password.update');

/*
|--------------------------------------------------------------------------
| Email change re-verification (spec §2.15.1 F-2.15.1-005)
|--------------------------------------------------------------------------
*/
Route::get('/email-change/confirm/{uid}', [EmailChangeController::class, 'confirm'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('email-change.confirm');

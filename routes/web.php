<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\FileDownloadController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

/* ---------------------------------------------------------------- guest -- */
Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])->middleware('throttle:6,1');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:10,1');

    Route::get('forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:6,1')->name('password.email');

    Route::get('reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:6,1')->name('password.update');
});

/* ------------------------------------------------------------ verifying -- */
Route::middleware('auth')->group(function () {
    Route::get('verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')->name('verification.verify');
    Route::post('verify-email/send', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')->name('verification.send');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

/* ------------------------------------------------------- authenticated -- */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Destinations the navigation points at. Built out in later phases; they
    // exist now so the nav is never a dead link and the responsive gate has
    // real screens to check.
    Route::view('library', 'placeholder', ['heading' => 'Library', 'phase' => 8])->name('library');
    Route::view('images', 'placeholder', ['heading' => 'Images', 'phase' => 8])->name('images');
    Route::view('account', 'account')->name('account');

    // Every uploaded file is served through here — never by direct URL.
    // Owner Addendum H control US-9.
    Route::get('files/{file:uuid}', [FileDownloadController::class, 'show'])->name('files.show');
});

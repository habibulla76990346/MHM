<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\PricingController;
use App\Http\Controllers\Branding\ManifestController;
use App\Http\Controllers\Chat\StreamController;
use App\Http\Controllers\Checkout\CheckoutController;
use App\Http\Controllers\Content\PageController;
use App\Http\Controllers\FileDownloadController;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

// The PWA manifest is generated, not a static file: every value in it is
// administrator-controlled branding (owner decisions D-09 and D-10).
Route::get('manifest.webmanifest', ManifestController::class)
    ->name('manifest');

// Pages written in the Admin Panel. Prefixed with /p/ so an administrator can
// never create a page whose slug shadows an application route — "login" as a
// page slug would otherwise break signing in.
Route::get('p/{slug}', [PageController::class, 'show'])
    ->name('pages.show');

// The public pricing page (§20). Outside the auth group on purpose: someone
// deciding whether to sign up cannot sign in yet.
Route::get('pricing', PricingController::class)->name('pricing');

Route::post('banners/{uuid}/dismiss', [PageController::class, 'dismissBanner'])
    ->name('banners.dismiss');

/**
 * Payment webhooks (Owner Addendum D).
 *
 * Outside every auth group and outside CSRF: a gateway is not a browser. The
 * signature over the raw body is what authenticates it, checked by the
 * adapter for the named gateway, and a delivery that fails it is recorded and
 * alerted rather than quietly dropped.
 */
Route::post('webhooks/payments/{gateway}', PaymentWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.payments');

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
    // Chat IS the dashboard (decision D-11: Chat is the first destination).
    Route::view('dashboard', 'chat')->name('dashboard');

    // Streaming a reply (§15). GET so an EventSource can open it; the browser
    // sends the session cookie, and the policy proves the message belongs to
    // whoever is asking.
    Route::get('chat/{message:uuid}/stream', StreamController::class)
        ->name('chat.stream');

    // The non-streaming path (risk R-01). Same answer, one response — used
    // when the owner has switched streaming off, or the stream never opened.
    Route::post('chat/{message:uuid}/complete', [StreamController::class, 'complete'])
        ->name('chat.complete');

    Route::post('chat/{message:uuid}/stop', [StreamController::class, 'stop'])
        ->name('chat.stop');

    // Destinations the navigation points at. Built out in later phases; they
    // exist now so the nav is never a dead link and the responsive gate has
    // real screens to check.
    Route::view('library', 'placeholder', ['heading' => 'Library', 'phase' => 8])->name('library');
    Route::view('images', 'placeholder', ['heading' => 'Images', 'phase' => 8])->name('images');
    Route::view('account', 'account')->name('account');

    // The customer's own billing: plan, credits, invoices and the details that
    // get copied onto future invoices.
    Route::get('billing', [BillingController::class, 'show'])->name('billing');
    Route::post('billing/details', [BillingController::class, 'updateProfile'])
        ->middleware('throttle:20,1')->name('billing.profile');
    Route::get('billing/invoices/{invoice:uuid}', [BillingController::class, 'invoice'])
        ->name('billing.invoice');

    // Checkout. One branded flow whichever shape the chosen gateway uses.
    Route::get('checkout/{plan:uuid}', [CheckoutController::class, 'start'])
        ->middleware('throttle:20,1')->name('checkout.start');
    Route::match(['get', 'post'], 'checkout/{payment:uuid}/return', [CheckoutController::class, 'return'])
        ->name('checkout.return');
    Route::get('checkout/{payment:uuid}/status', [CheckoutController::class, 'status'])
        ->middleware('throttle:60,1')->name('checkout.status');

    // Every uploaded file is served through here — never by direct URL.
    // Owner Addendum H control US-9.
    Route::get('files/{file:uuid}', [FileDownloadController::class, 'show'])->name('files.show');
});

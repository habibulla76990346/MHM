<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Security\Services\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): Response|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('dashboard')
            : response()->view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request, ActivityLogger $log): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        if ($request->user()->markEmailAsVerified()) {
            // A pending account becomes active once its address is confirmed.
            if ($request->user()->status === User::STATUS_PENDING) {
                $request->user()->forceFill(['status' => User::STATUS_ACTIVE])->save();
            }

            event(new Verified($request->user()));
            $log->log('auth.email_verified', $request->user());
        }

        return redirect()->route('dashboard')->with('status', __('Your email address is confirmed.'));
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', __('A new verification link has been sent.'));
    }
}

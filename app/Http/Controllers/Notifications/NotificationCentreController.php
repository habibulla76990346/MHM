<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What the platform has told this customer (§22, the in-app half).
 *
 * These rows are written by the same `Notifier` that sends the email, from the
 * same template and the same values — so the two can never disagree about what
 * was said, and a customer who did not receive the email can still find out
 * what it was.
 */
class NotificationCentreController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('notifications.index', [
            'notifications' => $user->notifications()->paginate(self::PER_PAGE),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}

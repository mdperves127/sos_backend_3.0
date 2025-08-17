<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function vendorNotification()
    {
        $user = User::find(vendorId());

        $notifications = $user->notifications;

        return response()->json([
            'status' => 200,
            'notification' => $notifications
        ]);
    }


    // Admin And affiliate
    public function notification()
    {
        $user = User::find(Auth::id());
        $notifications = $user->notifications;

        return response()->json([
            'status' => 200,
            'notification' => $notifications
        ]);
    }

    public function markAsRead($notificationId)
    {

        $user = User::find(vendorId());

        $notification = $user->notifications->find($notificationId);

        if ($notification) {
            $notification->markAsRead();
            return response()->json([
                'status' => 200,
            ]);
        } else {
            return response()->json([
                'status' => 401,
            ]);
        }
    }

    public function markAsReadAll()
    {

        $user = User::find(vendorId());

        $user->unreadNotifications->markAsRead();

        return response()->json([
            'status' => 200,
            'message' => 'All Notification marked as read successfully.'
        ]);

    }
}

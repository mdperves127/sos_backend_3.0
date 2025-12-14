<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function tenantNotification()
    {
        $tenantId = tenant()->id;

        $notifications = DB::table('notifications')
            ->where('notifiable_type', 'App\Models\Tenant')
            ->where('notifiable_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 200,
            'notification' => $notifications
        ]);
    }


    // Admin And affiliate
    public function notification()
    {
        $tenantId = tenant()->id;

        $notifications = DB::table('notifications')
            ->where('notifiable_type', 'App\Models\Tenant')
            ->where('notifiable_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 200,
            'notification' => $notifications
        ]);
    }

    public function markAsRead($notificationId)
    {
        $tenantId = tenant()->id;

        $notification = DB::table('notifications')
            ->where('id', $notificationId)
            ->where('notifiable_type', 'App\Models\Tenant')
            ->where('notifiable_id', $tenantId)
            ->first();

        if ($notification) {
            DB::table('notifications')
                ->where('id', $notificationId)
                ->update(['read_at' => now()]);

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
        $tenantId = tenant()->id;

        DB::table('notifications')
            ->where('notifiable_type', 'App\Models\Tenant')
            ->where('notifiable_id', $tenantId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'status' => 200,
            'message' => 'All Notification marked as read successfully.'
        ]);

    }
}

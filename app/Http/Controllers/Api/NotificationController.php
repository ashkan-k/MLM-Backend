<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Notification::query()->where('user_id', $request->user()->id)->latest()->paginate(20)
        );
    }

    public function unreadCount(Request $request)
    {
        return response()->json([
            'unread' => Notification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, Notification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->read_at = now();
        $notification->save();

        return response()->json($notification);
    }

    public function readAll(Request $request)
    {
        Notification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}

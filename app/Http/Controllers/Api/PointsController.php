<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Commission\PointsMonitorService;
use Illuminate\Http\Request;

class PointsController extends Controller
{
    public function monitor(Request $request, PointsMonitorService $points)
    {
        $data = $request->validate([
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'search' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json(
            $points->monitor($request->user(), $data['month'] ?? null, $data['search'] ?? null)
        );
    }

    public function detail(Request $request, PointsMonitorService $points)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role_slug' => ['required', 'string', 'in:representative,sales_manager,development_manager'],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        return response()->json(
            $points->detail(
                $request->user(),
                (int) $data['user_id'],
                $data['role_slug'],
                $data['month'] ?? null,
            )
        );
    }

    public function adjust(Request $request, PointsMonitorService $points)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role_slug' => ['required', 'string', 'in:representative,sales_manager,development_manager'],
            'delta_points' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $row = $points->adjust(
            $request->user(),
            (int) $data['user_id'],
            $data['role_slug'],
            (int) $data['delta_points'],
            $data['month'] ?? null,
            $data['note'] ?? null,
        );

        return response()->json([
            'message' => 'امتیاز با موفقیت به‌روز شد.',
            'adjustment' => [
                'id' => $row->id,
                'delta_points' => $row->delta_points,
                'month_key' => $row->month_key,
                'note' => $row->note,
                'actor' => $row->actor ? [
                    'id' => $row->actor->id,
                    'name' => $row->actor->name,
                ] : null,
                'created_at' => $row->created_at?->toIso8601String(),
            ],
        ]);
    }
}

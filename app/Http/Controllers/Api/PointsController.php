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
            'delta_points' => ['required', 'numeric', 'not_in:0', 'between:-1000000,1000000'],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'delta_points.required' => 'مقدار تغییر امتیاز را وارد کنید.',
            'delta_points.numeric' => 'مقدار تغییر امتیاز باید عدد باشد.',
            'delta_points.not_in' => 'مقدار تغییر امتیاز نمی‌تواند صفر باشد.',
            'delta_points.between' => 'مقدار تغییر امتیاز باید بین ۱٬۰۰۰٬۰۰۰− تا ۱٬۰۰۰٬۰۰۰ باشد.',
            'user_id.required' => 'کاربر مشخص نشده است.',
            'role_slug.required' => 'نقش کاربر مشخص نشده است.',
            'role_slug.in' => 'نقش انتخاب‌شده برای تعدیل امتیاز معتبر نیست.',
            'note.max' => 'یادداشت حداکثر ۵۰۰ کاراکتر باشد.',
        ], [
            'delta_points' => 'مقدار تغییر امتیاز',
            'user_id' => 'کاربر',
            'role_slug' => 'نقش',
            'month' => 'ماه',
            'note' => 'یادداشت',
        ]);

        $delta = (int) round((float) $data['delta_points']);
        if ($delta === 0) {
            return response()->json(['message' => 'مقدار تغییر امتیاز پس از گرد کردن صفر شد؛ عدد بزرگ‌تری وارد کنید.'], 422);
        }

        $row = $points->adjust(
            $request->user(),
            (int) $data['user_id'],
            $data['role_slug'],
            $delta,
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

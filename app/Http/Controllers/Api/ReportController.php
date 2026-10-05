<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Report\SuperuserReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Role-scoped reports for senior managers (subtree). Superusers should use /superuser/reports.
     */
    public function show(Request $request, SuperuserReportService $reports)
    {
        $user = $request->user();
        $active = $request->attributes->get('active_role');
        $activeSlug = is_object($active) ? ($active->slug ?? null) : null;
        $isSenior = $user->hasRole('senior_manager') || $activeSlug === 'senior_manager';

        if (! $isSenior && ! $user->isSuperuser()) {
            abort(403, 'دسترسی به گزارشات فقط برای مدیر ارشد یا مدیر سامانه است.');
        }

        // Senior-manager panel is always subtree-scoped (even if the same account is also a superuser).
        $filters = $isSenior
            ? $reports->constrainForManager($user, $request->all())
            : $request->all();

        return response()->json($reports->build($filters));
    }
}

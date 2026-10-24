<?php

use App\Models\Commission;
use App\Models\Role;
use App\Models\User;
use App\Services\Commission\MonthlyBonusService;
use App\Support\JalaliDate;
use Illuminate\Database\Migrations\Migration;

/**
 * Monthly bonus windows move from Gregorian YYYY-MM to Jalali YYYY-MM.
 * Reverse any already-posted Gregorian-month bonus rows and rebuild the
 * overlapping Jalali months so wallet totals stay consistent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bonuses = app(MonthlyBonusService::class);
        $posted = Commission::query()
            ->where('status', 'posted')
            ->where(function ($q) {
                $q->where('idempotency_key', 'like', 'monthly-bonus:%')
                    ->orWhere('idempotency_key', 'like', 'monthly-bonus-residual:%');
            })
            ->get();

        $rebuild = [];
        foreach ($posted as $row) {
            if (! preg_match('/:(20\d{2}-\d{2})$/', (string) $row->idempotency_key, $m)) {
                continue;
            }
            if (! JalaliDate::isGregorianMonthKey($m[1])) {
                continue;
            }
            $bonuses->reversePosted($row);
            if (str_starts_with((string) $row->idempotency_key, 'monthly-bonus-residual:')) {
                continue;
            }
            if (preg_match('/^monthly-bonus:(\d+):(\d+):/', (string) $row->idempotency_key, $parts)) {
                $rebuild[$parts[1].':'.$parts[2]] = [(int) $parts[1], (int) $parts[2]];
            }
        }

        foreach ($rebuild as [$userId, $roleId]) {
            $user = User::query()->find($userId);
            $role = Role::query()->find($roleId);
            if (! $user || ! $role) {
                continue;
            }
            // Rebuild previous Jalali month (closed) and current Jalali month estimate/post.
            $bonuses->refresh($user, $role->slug, JalaliDate::atMonthEnd(null, JalaliDate::previousMonthKey()));
            $bonuses->refresh($user, $role->slug, now());
        }

        // Residual for previous + current Jalali months
        $bonuses->settleUnpaidToSenior(JalaliDate::atMonthEnd(null, JalaliDate::previousMonthKey()));
        $bonuses->settleUnpaidToSenior(now());
    }

    public function down(): void
    {
    }
};

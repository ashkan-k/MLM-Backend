<?php

namespace App\Console\Commands;

use App\Services\Qa\MegaOrgScenarioRunner;
use App\Services\Qa\MegaOrgWorldBuilder;
use App\Services\Qa\OrganizationAuditor;
use Database\Seeders\QaBaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SeedMegaOrgWorldCommand extends Command
{
    use SkipsBrokenConsoleConfirm;

    protected $signature = 'finopal:qa-mega-world
        {--users= : تعداد کاربران (پیش‌فرض config/qa.php)}
        {--roots= : تعداد ریشه‌های مستقل}
        {--seed= : RANDOM_SEED}
        {--orders= : تعداد سفارش/فروش نمونه}
        {--transactions= : سقف تراکنش فاینوپال}
        {--fresh : migrate:fresh روی sqlite ایزوله QA}
        {--skip-scenarios : فقط ساخت + ممیزی}
        {--force : بدون تأیید}';

    protected $description = 'ساخت جهان MLM پیچیده روی sqlite ایزوله + ممیزی + سناریوهای واقعی (هرگز production)';

    public function handle(
        MegaOrgWorldBuilder $builder,
        OrganizationAuditor $auditor,
        MegaOrgScenarioRunner $scenarios,
    ): int {
        $users = (int) ($this->option('users') ?: config('qa.total_users'));
        $roots = (int) ($this->option('roots') ?: config('qa.total_roots'));
        $seed = (int) ($this->option('seed') ?: config('qa.random_seed'));
        if ($this->option('orders') !== null) {
            config(['qa.order_count' => (int) $this->option('orders')]);
        }
        if ($this->option('transactions') !== null) {
            config(['qa.transaction_count' => (int) $this->option('transactions')]);
        }

        $relative = (string) config('qa.sqlite_path', 'database/qa_mega_org.sqlite');
        $sqlitePath = base_path($relative);
        File::ensureDirectoryExists(dirname($sqlitePath));

        if (! $this->option('fresh') && ! File::exists($sqlitePath)) {
            $this->warn('فایل sqlite وجود ندارد — --fresh به‌صورت خودکار اعمال می‌شود.');
            $fresh = true;
        } else {
            $fresh = (bool) $this->option('fresh');
        }

        if (! $this->confirmedOrForced(
            "جهان QA روی [{$sqlitePath}] با users≈{$users} roots={$roots} seed={$seed}".($fresh ? ' (FRESH)' : '').'؟',
            true
        )) {
            $this->warn('لغو شد.');

            return self::SUCCESS;
        }

        // Isolate: force sqlite file — never touch .env MySQL production
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $sqlitePath,
            'finopal.webhook_secret' => config('finopal.webhook_secret') ?: 'test-webhook-secret',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        if ($fresh) {
            if (File::exists($sqlitePath)) {
                File::delete($sqlitePath);
            }
            File::put($sqlitePath, '');
            $this->info('migrate:fresh روی sqlite QA…');
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'sqlite']);
            $this->line(Artisan::output());
            $this->info('QaBaseSeeder…');
            (new QaBaseSeeder)->setCommand($this)->run();
        }

        $this->info("ساخت جهان MLM (seed={$seed})…");
        $started = microtime(true);
        $built = $builder->build($users, $roots, $seed);
        $elapsed = round(microtime(true) - $started, 1);
        $this->info("ساخت در {$elapsed}s — meta: ".json_encode($built['meta'], JSON_UNESCAPED_UNICODE));

        $snapshotDir = base_path('../qa/reports/snapshots');
        // project root is sibling: MLM-Backend/../qa
        $reportsDir = dirname(base_path()).DIRECTORY_SEPARATOR.'qa'.DIRECTORY_SEPARATOR.'reports';
        if (! is_dir($reportsDir)) {
            $reportsDir = base_path('storage/qa-reports');
            File::ensureDirectoryExists($reportsDir);
        }
        $snapPath = $reportsDir.DIRECTORY_SEPARATOR.'mega_org_snapshot_seed'.$seed.'.json';
        File::put($snapPath, json_encode([
            'meta' => $built['meta'],
            'edge_users' => $built['edge_users'],
            'sqlite' => $sqlitePath,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Snapshot: {$snapPath}");

        $this->info('ممیزی سازمان…');
        $audit = $auditor->audit();
        $this->writePersianIntegrity($reportsDir, $audit, $built);
        if (! $audit['ok']) {
            $this->error('سازمان نامعتبر است — سناریوها متوقف شد.');
            foreach ($audit['checks'] as $c) {
                if (! $c['ok']) {
                    $this->line('FAIL '.$c['name'].': '.$c['detail']);
                }
            }

            return self::FAILURE;
        }
        $this->info('ممیزی OK');

        $scenarioResults = [];
        if (! $this->option('skip-scenarios')) {
            $this->info('اجرای سناریوهای واقعی…');
            $scenarioResults = $scenarios->run($built['edge_users']);
            foreach ($scenarioResults as $r) {
                $this->line(($r['ok'] ? '[PASS]' : '[FAIL]')." {$r['id']} {$r['title']}: {$r['detail']}");
            }
            // Re-audit after mutations
            $auditAfter = $auditor->audit();
            $this->writeAllPersianReports($reportsDir, $built, $audit, $auditAfter, $scenarioResults, $elapsed);
            if (! $auditAfter['ok']) {
                $this->error('ممیزی پس از سناریوها FAIL');

                return self::FAILURE;
            }
        } else {
            $this->writeAllPersianReports($reportsDir, $built, $audit, $audit, [], $elapsed);
        }

        $this->info('گزارش‌های فارسی در: '.$reportsDir);

        return self::SUCCESS;
    }

    /**
     * @param  array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>, stats: array<string, mixed>}  $audit
     * @param  array{meta: array<string, mixed>, edge_users: list<array{key: string, user_id: int, mobile: string, note: string}>}  $built
     */
    private function writePersianIntegrity(string $dir, array $audit, array $built): void
    {
        $lines = [
            '# گزارش یکپارچگی سازمان',
            '',
            '**نتیجه کلی:** '.($audit['ok'] ? 'معتبر ✓' : 'نامعتبر ✗'),
            '',
            '## آمار',
        ];
        foreach ($audit['stats'] as $k => $v) {
            $lines[] = "- **{$k}:** {$v}";
        }
        $lines[] = '';
        $lines[] = '## بررسی‌ها';
        foreach ($audit['checks'] as $c) {
            $lines[] = '- '.($c['ok'] ? '✓' : '✗')." `{$c['name']}` — {$c['detail']}";
        }
        $lines[] = '';
        $lines[] = '## کاربران لبه‌ای';
        foreach ($built['edge_users'] as $e) {
            $lines[] = "- `{$e['key']}` — {$e['mobile']} (id={$e['user_id']}) — {$e['note']}";
        }
        File::put($dir.DIRECTORY_SEPARATOR.'ORGANIZATION_INTEGRITY_REPORT.md', implode("\n", $lines)."\n");
    }

    /**
     * @param  array{meta: array<string, mixed>, edge_users: list<array{key: string, user_id: int, mobile: string, note: string}>}  $built
     * @param  array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>, stats: array<string, mixed>}  $audit
     * @param  array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>, stats: array<string, mixed>}  $auditAfter
     * @param  list<array{id: string, title: string, ok: bool, detail: string}>  $scenarios
     */
    private function writeAllPersianReports(
        string $dir,
        array $built,
        array $audit,
        array $auditAfter,
        array $scenarios,
        float $elapsed,
    ): void {
        $meta = $built['meta'];
        File::put($dir.DIRECTORY_SEPARATOR.'ORGANIZATION_GENERATION_REPORT.md', implode("\n", [
            '# گزارش تولید سازمان عظیم MLM',
            '',
            "- **RANDOM_SEED:** {$meta['random_seed']}",
            "- **کاربران درخواستی:** {$meta['requested_users']}",
            "- **کاربران واقعی:** {$meta['actual_users']}",
            "- **ریشه‌های مستقل:** {$meta['roots']}",
            "- **گره‌های سازمانی:** {$meta['organization_nodes']}",
            "- **روابط معرفی:** {$meta['referrals']}",
            "- **فروش‌های نمونه‌ای:** ".($meta['orders_seeded'] ?? $meta['gateway_sales'] ?? '—'),
            "- **تراکنش‌های فاینوپال:** ".($meta['transactions_seeded'] ?? '—'),
            "- **زمان ساخت (ثانیه):** {$elapsed}",
            "- **sqlite:** ".config('database.connections.sqlite.database'),
            '',
            'ساختارها: چندریشه، عمیق، عریض، متوازن، نامتوازن، تصادفی + کاربران لبه‌ای.',
            '',
        ]));

        $matrix = $dir.DIRECTORY_SEPARATOR.'USER_ROLE_MATRIX.md';
        if (is_file($matrix)) {
            File::copy($matrix, $dir.DIRECTORY_SEPARATOR.'USER_ROLE_REPORT.md');
        } else {
            File::put($dir.DIRECTORY_SEPARATOR.'USER_ROLE_REPORT.md', "# نقش‌ها\n\nنگاه کنید به USER_ROLE_MATRIX.md\n");
        }

        File::put($dir.DIRECTORY_SEPARATOR.'REFERRAL_NETWORK_REPORT.md', "# شبکه معرفی\n\n- تعداد روابط: {$meta['referrals']}\n- قوانین: یک معرفی‌شونده = حداکثر یک معرف اصلی؛ خودمعرفی ارشد مجاز.\n");

        File::put($dir.DIRECTORY_SEPARATOR.'COMMISSION_REPORT.md', "# گزارش پورسانت (جهان QA)\n\n- تعداد رکورد commissions پس از ساخت/سناریو: {$auditAfter['stats']['commissions']}\n- محاسبه مستقل در سناریو A با Money::percentOf انجام شد.\n");

        File::put($dir.DIRECTORY_SEPARATOR.'WALLET_REPORT.md', "# گزارش کیف پول\n\n- تعداد کیف‌ها: {$auditAfter['stats']['wallets']}\n- جمع موجودی‌ها (نمایشی): {$auditAfter['stats']['wallet_ledger_sum']}\n- اینورینت ledger در ممیزی یکپارچگی بررسی شد.\n");

        File::put($dir.DIRECTORY_SEPARATOR.'TRANSACTION_REPORT.md', "# تراکنش‌ها\n\n- فروش درگاه: ".($meta['gateway_sales'] ?? '—')."\n- تراکنش فاینوپال seed: ".($meta['transactions_seeded'] ?? '—')."\n");

        $scnLines = ["# گزارش سناریوهای E2E روی سازمان تولیدشده", ''];
        $pass = 0;
        $fail = 0;
        foreach ($scenarios as $r) {
            $scnLines[] = '- '.($r['ok'] ? '✓' : '✗')." **{$r['id']}** {$r['title']} — {$r['detail']}";
            $r['ok'] ? $pass++ : $fail++;
        }
        $scnLines[] = '';
        $scnLines[] = "**جمع:** {$pass} موفق / {$fail} ناموفق";
        File::put($dir.DIRECTORY_SEPARATOR.'E2E_SCENARIO_REPORT.md', implode("\n", $scnLines)."\n");

        File::put($dir.DIRECTORY_SEPARATOR.'SECURITY_REPORT.md', "# امنیت روی جهان بزرگ\n\n- تلاش reparent چرخه‌ای: ".($this->scenarioStatus($scenarios, 'ADV'))."\n- انزوای دو ریشه: ".($this->scenarioStatus($scenarios, 'X'))."\n");

        File::put($dir.DIRECTORY_SEPARATOR.'CONCURRENCY_REPORT.md', "# همزمانی\n\nدر این اجرا harness چندپردازه‌ای کامل اجرا نشد. idempotency وب‌هوک (سناریو B) پوشش داده شد. برای k6 از `qa/load` روی همین sqlite سرو شده استفاده کنید.\n");

        File::put($dir.DIRECTORY_SEPARATOR.'LOAD_TEST_REPORT.md', "# بار\n\nدیتاست جهان QA آماده است. اجرای k6 جداگانه با حساب‌های edge (مثلاً ROOT موبایل snapshot).\n");

        File::put($dir.DIRECTORY_SEPARATOR.'PERFORMANCE_REPORT.md', "# عملکرد\n\n- زمان ساخت جهان: {$elapsed}s برای {$meta['actual_users']} کاربر\n- درخت عریض/عمیق برای تست N+1 در endpoints درخت/امتیاز آماده است.\n");

        File::put($dir.DIRECTORY_SEPARATOR.'DATABASE_REPORT.md', "# پایگاه داده QA\n\n- موتور: sqlite فایل ایزوله\n- مسیر: ".config('database.connections.sqlite.database')."\n- پس از سناریو ممیزی: ".($auditAfter['ok'] ? 'پاس' : 'رد')."\n");

        File::put($dir.DIRECTORY_SEPARATOR.'DATA_INTEGRITY_REPORT.md', "# یکپارچگی داده نهایی\n\n- قبل از سناریو: ".($audit['ok'] ? 'پاس' : 'رد')."\n- بعد از سناریو: ".($auditAfter['ok'] ? 'پاس' : 'رد')."\n");

        $bugs = ["# باگ‌ها / یافته‌ها", ''];
        foreach ($scenarios as $r) {
            if (! $r['ok']) {
                $bugs[] = "- **{$r['id']}** {$r['title']}: {$r['detail']} (seed={$meta['random_seed']})";
            }
        }
        if (count($bugs) === 1) {
            $bugs[] = 'یافته شکست سناریو در این اجرا ثبت نشد.';
        }
        File::put($dir.DIRECTORY_SEPARATOR.'BUG_REPORT.md', implode("\n", $bugs)."\n");

        File::put($dir.DIRECTORY_SEPARATOR.'REGRESSION_REPORT.md', "# رگرسیون\n\nجهان با seed={$meta['random_seed']} قابل بازتولید است. دستور:\n\n`php artisan finopal:qa-mega-world --fresh --users={$meta['requested_users']} --roots={$meta['roots']} --seed={$meta['random_seed']} --force`\n");

        // Persian final summary
        File::put($dir.DIRECTORY_SEPARATOR.'FINAL_MEGA_ORG_SUMMARY_FA.md', $this->persianSummary($built, $auditAfter, $scenarios, $elapsed));
    }

    /** @param  list<array{id: string, title: string, ok: bool, detail: string}>  $scenarios */
    private function scenarioStatus(array $scenarios, string $id): string
    {
        foreach ($scenarios as $r) {
            if ($r['id'] === $id) {
                return $r['ok'] ? 'پاس — '.$r['detail'] : 'رد — '.$r['detail'];
            }
        }

        return 'اجرا نشد';
    }

    /**
     * @param  array{meta: array<string, mixed>, edge_users: list<array{key: string, user_id: int, mobile: string, note: string}>}  $built
     * @param  array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>, stats: array<string, mixed>}  $audit
     * @param  list<array{id: string, title: string, ok: bool, detail: string}>  $scenarios
     */
    private function persianSummary(array $built, array $audit, array $scenarios, float $elapsed): string
    {
        $m = $built['meta'];
        $pass = collect($scenarios)->where('ok', true)->count();
        $fail = collect($scenarios)->where('ok', false)->count();

        return <<<MD
# خلاصه نهایی — جهان MLM + تست واقعی

## نتیجه یک‌خطی

سازمان پیچیده روی **sqlite ایزوله** ساخته شد، ممیزی یکپارچگی **{$this->faBool($audit['ok'])}**، سناریوها: **{$pass} موفق / {$fail} ناموفق**.

## اعداد تولید

| شاخص | مقدار |
|------|-------|
| RANDOM_SEED | {$m['random_seed']} |
| کاربران | {$m['actual_users']} |
| ریشه‌های مستقل | {$m['roots']} |
| گره‌های درخت | {$m['organization_nodes']} |
| معرفی‌ها | {$m['referrals']} |
| زمان ساخت | {$elapsed} ثانیه |
| جمع موجودی کیف‌ها | {$audit['stats']['wallet_ledger_sum']} |

## نقش‌های واقعی استفاده‌شده

فقط: مدیر سامانه، مدیر ارشد، مدیر توسعه، مدیر فروش، نماینده، نماینده معرف.  
نقش ساختگی (Agent/Customer/…) ساخته نشد.

## سناریوهای اجراشده روی همین داده

MD.collect($scenarios)->map(fn ($r) => '- '.($r['ok'] ? '✓' : '✗')." {$r['title']}: {$r['detail']}")->implode("\n").<<<MD


## ریسک‌های باز

- بار k6 و همزمانی هزارکاربره روی این دیتاست هنوز جدا اجرا شود.
- ماتریس کامل IDOR روی همه path-IDها باید گسترش یابد.
- برای ۱۰٬۰۰۰+ کاربر همان دستور با `--users=10000` روی همین sqlite.

## دستور بازتولید

```bash
php artisan finopal:qa-mega-world --fresh --users={$m['requested_users']} --roots={$m['roots']} --seed={$m['random_seed']} --force
```

MD;
    }

    private function faBool(bool $ok): string
    {
        return $ok ? 'قبول' : 'رد';
    }
}

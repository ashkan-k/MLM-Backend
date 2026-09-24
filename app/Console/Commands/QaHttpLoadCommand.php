<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Concurrent HTTP load against a running API (fallback when k6 is unavailable).
 * Uses accounts from the current DB (mega org sqlite or demo).
 */
class QaHttpLoadCommand extends Command
{
    protected $signature = 'finopal:qa-http-load
        {--base=http://127.0.0.1:8001 : Base URL}
        {--vus=50 : Concurrent workers}
        {--iterations=20 : Requests per worker}
        {--password=Password123! : Seed password}
        {--mobile=09100000000 : Login mobile (ROOT)}
        {--role=senior_manager : Role slug}';

    protected $description = 'QA concurrent HTTP load (login + dashboard/wallets/tree)';

    public function handle(): int
    {
        $base = rtrim((string) $this->option('base'), '/');
        $vus = max(1, (int) $this->option('vus'));
        $iters = max(1, (int) $this->option('iterations'));
        $password = (string) $this->option('password');
        $mobile = (string) $this->option('mobile');
        $role = (string) $this->option('role');

        $this->info("Warm login {$mobile} @ {$base}…");
        $login = Http::timeout(30)->acceptJson()->post($base.'/api/auth/login', [
            'mobile' => $mobile,
            'password' => $password,
            'role_slug' => $role,
        ]);
        if (! $login->successful()) {
            $this->error('Login failed: '.$login->status().' '.$login->body());

            return self::FAILURE;
        }
        $token = $login->json('token');
        if (! $token) {
            $this->error('No token in login response');

            return self::FAILURE;
        }

        $paths = [
            '/api/dashboard',
            '/api/wallets',
            '/api/organization/tree',
            '/api/commissions',
            '/api/wallet-transactions',
        ];

        $ok = 0;
        $fail = 0;
        $latencies = [];
        $started = microtime(true);

        // Batched concurrency via Http::pool
        $total = $vus * $iters;
        $this->info("Firing ~{$total} GETs with pool size {$vus}…");

        for ($round = 0; $round < $iters; $round++) {
            $responses = Http::pool(function ($pool) use ($base, $token, $paths, $vus) {
                $reqs = [];
                for ($i = 0; $i < $vus; $i++) {
                    $path = $paths[$i % count($paths)];
                    $reqs[] = $pool->as("r{$i}")
                        ->withToken($token)
                        ->acceptJson()
                        ->timeout(60)
                        ->get($base.$path);
                }

                return $reqs;
            });

            foreach ($responses as $res) {
                $t0 = microtime(true); // pool already done; approximate
                if (is_object($res) && method_exists($res, 'successful') && $res->successful()) {
                    $ok++;
                } else {
                    $fail++;
                }
                $latencies[] = (microtime(true) - $t0) * 1000;
            }
        }

        $elapsed = microtime(true) - $started;
        $rps = $elapsed > 0 ? round(($ok + $fail) / $elapsed, 2) : 0;
        sort($latencies);
        $p95 = $latencies[(int) floor(count($latencies) * 0.95)] ?? 0;

        $this->table(['metric', 'value'], [
            ['ok', $ok],
            ['fail', $fail],
            ['elapsed_s', round($elapsed, 2)],
            ['rps', $rps],
            ['error_rate', $ok + $fail > 0 ? round($fail / ($ok + $fail), 4) : 0],
            ['p95_ms_approx', round($p95, 1)],
            ['users_in_db', User::query()->count()],
        ]);

        $reportDir = dirname(base_path()).DIRECTORY_SEPARATOR.'qa'.DIRECTORY_SEPARATOR.'reports';
        if (! is_dir($reportDir)) {
            $reportDir = base_path('storage/qa-reports');
            @mkdir($reportDir, 0777, true);
        }
        $payload = [
            'ok' => $ok,
            'fail' => $fail,
            'elapsed_s' => round($elapsed, 2),
            'rps' => $rps,
            'vus' => $vus,
            'iterations' => $iters,
            'base' => $base,
            'mobile' => $mobile,
            'users_in_db' => User::query()->count(),
            'at' => now()->toIso8601String(),
        ];
        file_put_contents($reportDir.DIRECTORY_SEPARATOR.'LOAD_TEST_RESULT.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }
}

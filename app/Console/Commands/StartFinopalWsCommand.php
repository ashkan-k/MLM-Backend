<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * CLI starter for production Node WebSocket (uses shell — run via SSH/cron, not FPM).
 */
class StartFinopalWsCommand extends Command
{
    use SkipsBrokenConsoleConfirm;

    protected $signature = 'app:start-finopal-ws {--force : بدون تأیید}';

    protected $description = 'Start Finopal realtime WebSocket (node realtime/ws-server.mjs) in background';

    public function handle(): int
    {
        if (! $this->confirmedOrForced('WebSocket روی پورت ۶۰۰۱ استارت شود؟', true)) {
            return self::FAILURE;
        }

        $root = base_path();
        $script = $root.DIRECTORY_SEPARATOR.'start-finopal-ws.sh';
        if (! is_file($script)) {
            $this->error('start-finopal-ws.sh یافت نشد.');

            return self::FAILURE;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $this->error('این دستور برای لینوکس سرور است. روی ویندوز: start-finopal-ws.ps1');

            return self::FAILURE;
        }

        @chmod($script, 0755);
        $log = $root.'/storage/logs/ws.log';
        $pidFile = $root.'/storage/logs/ws.pid';
        @mkdir(dirname($log), 0755, true);

        if (is_file($pidFile)) {
            $old = trim((string) @file_get_contents($pidFile));
            if ($old !== '' && preg_match('/^\d+$/', $old)) {
                @exec('kill '.escapeshellarg($old).' 2>/dev/null');
                usleep(400000);
            }
            @unlink($pidFile);
        }
        @exec('fuser -k 6001/tcp 2>/dev/null');
        usleep(400000);

        $cmd = 'cd '.escapeshellarg($root)
            .' && nohup bash '.escapeshellarg($script)
            .' > '.escapeshellarg($log).' 2>&1 & echo $!';

        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $this->line('spawn_code='.$code);
        $this->line('bg='.trim(implode(' ', $out)));

        usleep(1500000);
        $listen = [];
        @exec("(ss -ltn 2>/dev/null || netstat -ltn 2>/dev/null) | grep 6001 || true", $listen);
        if ($listen === []) {
            $this->error('پورت 6001 هنوز listen نیست. لاگ: storage/logs/ws.log');
            if (is_file($log)) {
                $this->line(implode('', array_slice(@file($log) ?: [], -30)));
            }

            return self::FAILURE;
        }

        $this->info('WebSocket فعال است.');
        foreach ($listen as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

/**
 * Windows + Persian project path often crashes Symfony Question / Collision
 * during interactive confirm. Prefer --force, or auto-skip confirm on non-ASCII paths.
 */
trait SkipsBrokenConsoleConfirm
{
    protected function confirmedOrForced(string $question, bool $default = true): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        // Non-ASCII base path (e.g. سازمان فروش) breaks confirm rendering on Windows terminals.
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/[^\x00-\x7F]/', base_path()) === 1) {
            $this->warn('مسیر پروژه شامل کاراکتر غیر ASCII است؛ تأیید تعاملی روی ویندوز ناپایدار است.');
            $this->comment('ادامه مثل --force. برای صریح بودن دفعه بعد: همان دستور + --force');

            return true;
        }

        try {
            return $this->confirm($question, $default);
        } catch (\Throwable $e) {
            $this->error('تأیید تعاملی شکست خورد. دوباره با --force اجرا کنید.');
            $this->line($e->getMessage());

            return false;
        }
    }
}

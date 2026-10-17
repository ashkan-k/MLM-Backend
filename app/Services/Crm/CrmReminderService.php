<?php

namespace App\Services\Crm;

use App\Models\CrmTask;
use App\Models\CrmVisit;
use App\Models\Notification;
use App\Models\User;
use App\Services\Sms\SmsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class CrmReminderService
{
    public function __construct(private readonly SmsService $sms) {}

    public function dispatchDue(?int $userId = null): int
    {
        $sent = 0;

        $visits = CrmVisit::query()
            ->with('user')
            ->whereNull('reminded_at')
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', now())
            ->where(function ($q) {
                $q->where('notify_in_app', true)->orWhere('notify_sms', true);
            })
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($visits as $visit) {
            try {
                if ($this->deliverVisit($visit)) {
                    $sent++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        $tasks = CrmTask::query()
            ->with('user')
            ->whereNull('reminded_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->where(function ($q) {
                $q->where('notify_in_app', true)->orWhere('notify_sms', true);
            })
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($tasks as $task) {
            try {
                if ($this->deliverTask($task)) {
                    $sent++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    private function deliverVisit(CrmVisit $visit): bool
    {
        return DB::transaction(function () use ($visit) {
            $locked = CrmVisit::query()->lockForUpdate()->find($visit->id);
            if (! $locked || $locked->reminded_at || ! $locked->remind_at || $locked->remind_at->isFuture()) {
                return false;
            }
            if (! $locked->notify_in_app && ! $locked->notify_sms) {
                return false;
            }

            $user = $visit->user ?? User::query()->find($locked->user_id);
            if (! $user) {
                return false;
            }

            $body = trim("پیگیری {$locked->person_name} در {$locked->place}".($locked->result ? " — {$locked->result}" : ''));
            $done = $this->notify($user, $locked, 'یادآور ثبت و پیگیری', $body, [
                'kind' => 'visit',
                'id' => $locked->id,
            ]);
            $locked->save();

            return $done;
        });
    }

    private function deliverTask(CrmTask $task): bool
    {
        return DB::transaction(function () use ($task) {
            $locked = CrmTask::query()->lockForUpdate()->find($task->id);
            if (! $locked || $locked->reminded_at || ! $locked->due_at || $locked->due_at->isFuture()) {
                return false;
            }
            if (! $locked->notify_in_app && ! $locked->notify_sms) {
                return false;
            }

            $user = $task->user ?? User::query()->find($locked->user_id);
            if (! $user) {
                return false;
            }

            $body = trim($locked->title.($locked->notes ? " — {$locked->notes}" : ''));
            $done = $this->notify($user, $locked, 'یادآور وظیفه', $body, [
                'kind' => 'task',
                'id' => $locked->id,
            ]);
            $locked->save();

            return $done;
        });
    }

    /**
     * هر کانال جدا تلاش می‌شود. خطای یکی، دیگری را متوقف نمی‌کند
     * و فقط همان کانال ناموفق در نوبت بعد دوباره ارسال می‌شود.
     *
     * @param  array<string, mixed>  $data
     */
    private function notify(User $user, Model $record, string $title, string $body, array $data): bool
    {
        $inAppOk = ! $record->notify_in_app || $record->notified_at !== null;
        $smsOk = ! $record->notify_sms || $record->sms_sent_at !== null;

        if ($record->notify_in_app && $record->notified_at === null) {
            try {
                Notification::query()->create([
                    'user_id' => $user->id,
                    'type' => 'crm_reminder',
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                ]);
                $record->notified_at = now();
                $inAppOk = true;
            } catch (Throwable $e) {
                report($e);
                $inAppOk = false;
            }
        }

        if ($record->notify_sms && $record->sms_sent_at === null) {
            try {
                if ($user->mobile && $this->sms->send((string) $user->mobile, $title.': '.$body)) {
                    $record->sms_sent_at = now();
                    $smsOk = true;
                }
            } catch (Throwable $e) {
                report($e);
                $smsOk = false;
            }
        }

        if ($inAppOk && $smsOk) {
            $record->reminded_at = now();
        }

        return $inAppOk && $smsOk;
    }
}

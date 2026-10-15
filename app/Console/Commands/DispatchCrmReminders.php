<?php

namespace App\Console\Commands;

use App\Services\Crm\CrmReminderService;
use Illuminate\Console\Command;

class DispatchCrmReminders extends Command
{
    protected $signature = 'finopal:dispatch-crm-reminders';

    protected $description = 'Send due CRM visit and task reminders by in-app notification and SMS';

    public function handle(CrmReminderService $reminders): int
    {
        $sent = $reminders->dispatchDue();
        $this->info("crm reminders sent: {$sent}");

        return self::SUCCESS;
    }
}

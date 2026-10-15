<?php

namespace Tests\Feature;

use App\Models\CrmTask;
use App\Models\CrmVisit;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Sms\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_keeps_private_visits_and_tasks_and_reminders_fire_once(): void
    {
        $owner = $this->user('09121110001');
        $other = $this->user('09121110002');
        $token = $this->token($owner);
        $otherToken = $this->token($other);

        $this->mock(SmsService::class, function ($mock) {
            $mock->shouldReceive('send')->twice()->andReturn(true);
        });

        $this->withToken($token)->postJson('/api/crm/visits', [
            'visited_on' => '2026-10-15',
            'place' => 'بدون کانال',
            'person_name' => 'بدون کانال',
            'remind_at' => now()->addHour()->timezone('Asia/Tehran')->format('Y-m-d\TH:i'),
            'notify_in_app' => false,
            'notify_sms' => false,
        ])->assertStatus(422);

        $visit = $this->withToken($token)->postJson('/api/crm/visits', [
            'visited_on' => '2026-10-15',
            'place' => 'پاساژ ملت',
            'person_name' => 'علی رضایی',
            'national_id' => '0012345678',
            'mobile' => '09120001122',
            'result' => 'قرار جلسه هفته بعد',
            'remind_at' => now()->subMinute()->timezone('Asia/Tehran')->format('Y-m-d\TH:i'),
            'notify_in_app' => true,
            'notify_sms' => true,
        ])->assertCreated();

        $task = $this->withToken($token)->postJson('/api/crm/tasks', [
            'title' => 'تماس پیگیری',
            'notes' => 'بعد از جلسه',
            'due_at' => now()->addDay()->timezone('Asia/Tehran')->format('Y-m-d\TH:i'),
            'notify_in_app' => true,
            'notify_sms' => false,
        ])->assertCreated();

        $this->withToken($otherToken)->getJson('/api/crm')
            ->assertOk()
            ->assertJsonCount(0, 'visits')
            ->assertJsonCount(0, 'tasks');

        $this->withToken($otherToken)->putJson('/api/crm/visits/'.$visit->json('id'), [
            'visited_on' => '2026-10-15',
            'place' => 'نفوذ',
            'person_name' => 'نفوذ',
        ])->assertNotFound();

        $this->artisan('finopal:dispatch-crm-reminders')->assertSuccessful();

        $this->assertNotNull(CrmVisit::query()->find($visit->json('id'))->reminded_at);
        $this->assertSame(1, Notification::query()->where('user_id', $owner->id)->where('type', 'crm_reminder')->count());
        $this->assertSame('یادآور فعالیت', Notification::query()->where('user_id', $owner->id)->value('title'));
        $this->assertNull(CrmTask::query()->where('user_id', $owner->id)->value('reminded_at'));

        $this->artisan('finopal:dispatch-crm-reminders')->assertSuccessful();
        $this->assertSame(1, Notification::query()->where('user_id', $owner->id)->where('type', 'crm_reminder')->count());

        $this->withToken($token)->putJson('/api/crm/visits/'.$visit->json('id'), [
            'visited_on' => '2026-10-15',
            'place' => 'پاساژ ملت',
            'person_name' => 'علی رضایی',
            'remind_at' => now()->subMinutes(2)->timezone('Asia/Tehran')->format('Y-m-d\TH:i'),
            'notify_in_app' => true,
            'notify_sms' => true,
        ])->assertOk()->assertJsonPath('reminded_at', null);

        $this->artisan('finopal:dispatch-crm-reminders')->assertSuccessful();
        $this->assertSame(2, Notification::query()->where('user_id', $owner->id)->where('type', 'crm_reminder')->count());

        $listed = $this->withToken($token)->getJson('/api/crm?q=ملت')->assertOk();
        $listed->assertJsonPath('visits.0.person_name', 'علی رضایی');
        $this->withToken($token)->getJson('/api/crm')->assertOk()->assertJsonPath('open_tasks', 1);

        $doneVisit = $this->withToken($token)->postJson('/api/crm/visits', [
            'visited_on' => '2026-10-15',
            'place' => 'انجام‌شده',
            'person_name' => 'مخاطب بسته‌شده',
            'status' => 'done',
            'remind_at' => now()->subMinute()->timezone('Asia/Tehran')->format('Y-m-d\TH:i'),
            'notify_in_app' => true,
            'notify_sms' => false,
        ])->assertCreated();
        $doneTask = $this->withToken($token)->postJson('/api/crm/tasks', [
            'title' => 'وظیفه بسته‌شده',
            'due_at' => now()->subMinute()->timezone('Asia/Tehran')->format('Y-m-d\TH:i'),
            'notify_in_app' => true,
            'notify_sms' => false,
        ])->assertCreated();
        $this->withToken($token)->putJson('/api/crm/tasks/'.$doneTask->json('id'), [
            'title' => 'وظیفه بسته‌شده',
            'due_at' => now()->subMinute()->timezone('Asia/Tehran')->format('Y-m-d\TH:i'),
            'notify_in_app' => true,
            'notify_sms' => false,
            'completed' => true,
        ])->assertOk();
        $this->artisan('finopal:dispatch-crm-reminders')->assertSuccessful();
        $this->assertNotNull(CrmVisit::query()->find($doneVisit->json('id'))->reminded_at);
        $this->assertNotNull(CrmTask::query()->find($doneTask->json('id'))->reminded_at);

        $this->withToken($token)->putJson('/api/crm/tasks/'.$task->json('id'), [
            'title' => 'تماس پیگیری',
            'completed' => true,
        ])->assertOk()->assertJsonPath('completed_at', fn ($value) => is_string($value));
    }

    public function test_a_failed_channel_does_not_skip_the_other(): void
    {
        $owner = $this->user('09121110003');
        $token = $this->token($owner);
        $when = now()->subMinute()->timezone('Asia/Tehran')->format('Y-m-d\TH:i');

        $this->mock(SmsService::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('sms down'));
        });

        $smsFails = $this->withToken($token)->postJson('/api/crm/visits', [
            'visited_on' => '2026-10-15',
            'place' => 'پیامک خراب',
            'person_name' => 'علی',
            'remind_at' => $when,
            'notify_in_app' => true,
            'notify_sms' => true,
        ])->assertCreated();

        $this->artisan('finopal:dispatch-crm-reminders')->assertSuccessful();
        $this->assertSame(1, Notification::query()->where('user_id', $owner->id)->count());
        $visit = CrmVisit::query()->find($smsFails->json('id'));
        $this->assertNotNull($visit->notified_at);
        $this->assertNull($visit->sms_sent_at);
        $this->assertNull($visit->reminded_at);

        $this->mock(SmsService::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andReturn(true);
        });
        $this->artisan('finopal:dispatch-crm-reminders')->assertSuccessful();
        $visit = $visit->fresh();
        $this->assertNotNull($visit->sms_sent_at);
        $this->assertNotNull($visit->reminded_at);
        $this->assertSame(1, Notification::query()->where('user_id', $owner->id)->count());

        $blockNotification = true;
        Notification::creating(function () use (&$blockNotification) {
            if ($blockNotification) {
                throw new \RuntimeException('notification down');
            }
        });
        $this->mock(SmsService::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andReturn(true);
        });
        $notificationFails = $this->withToken($token)->postJson('/api/crm/tasks', [
            'title' => 'اعلان خراب',
            'due_at' => $when,
            'notify_in_app' => true,
            'notify_sms' => true,
        ])->assertCreated();

        $this->artisan('finopal:dispatch-crm-reminders')->assertSuccessful();
        $task = CrmTask::query()->find($notificationFails->json('id'));
        $this->assertNull($task->notified_at);
        $this->assertNotNull($task->sms_sent_at);
        $this->assertNull($task->reminded_at);
        $this->assertSame(1, Notification::query()->where('user_id', $owner->id)->count());

        $blockNotification = false;
        $this->artisan('finopal:dispatch-crm-reminders')->assertSuccessful();
        $task = $task->fresh();
        $this->assertNotNull($task->notified_at);
        $this->assertNotNull($task->reminded_at);
        $this->assertSame(2, Notification::query()->where('user_id', $owner->id)->count());
    }

    private function user(string $mobile): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => 'representative'],
            ['name' => 'نماینده', 'hierarchy_level' => 5, 'is_organizational' => true, 'is_active' => true]
        );
        $user = User::factory()->create(['mobile' => $mobile, 'is_active' => true]);
        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'effective_from' => now()->toDateString(),
            'is_primary' => true,
            'is_active' => true,
        ]);
        $user->setRelation('crmRole', $role);

        return $user;
    }

    private function token(User $user): string
    {
        $roleId = UserRole::query()->where('user_id', $user->id)->value('role_id');
        [, $plain] = $user->createApiToken($roleId);

        return $plain;
    }
}

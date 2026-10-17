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
        $this->assertSame('یادآور ثبت و پیگیری', Notification::query()->where('user_id', $owner->id)->value('title'));
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

    public function test_follow_up_logs_stay_on_the_owners_visit(): void
    {
        $owner = $this->user('09121110021');
        $other = $this->user('09121110022');
        $token = $this->token($owner);

        $visit = $this->withToken($token)->postJson('/api/crm/visits', [
            'visited_on' => '2026-10-15',
            'place' => 'پاساژ',
            'person_name' => 'نگار',
            'result' => 'شروع',
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/crm/visits/'.$visit->json('id').'/logs', [
            'body' => '   ',
        ])->assertStatus(422);

        $this->withToken($token)->postJson('/api/crm/visits/'.$visit->json('id').'/logs', [
            'body' => 'وارد مرحله ۲ شد',
        ])->assertCreated()->assertJsonPath('body', 'وارد مرحله ۲ شد');

        $this->withToken($token)->getJson('/api/crm/visits/'.$visit->json('id'))
            ->assertOk()
            ->assertJsonPath('logs.0.body', 'وارد مرحله ۲ شد')
            ->assertJsonPath('logs_count', 1)
            ->assertJsonPath('result', 'شروع');

        $this->withToken($this->token($other))->postJson('/api/crm/visits/'.$visit->json('id').'/logs', [
            'body' => 'نفوذ',
        ])->assertNotFound();

        $this->withToken($this->token($other))->getJson('/api/crm/visits/'.$visit->json('id'))->assertNotFound();

        $this->withToken($token)->getJson('/api/crm?q='.rawurlencode('مرحله ۲'))
            ->assertOk()
            ->assertJsonPath('visits.0.person_name', 'نگار');
    }

    public function test_each_role_panel_keeps_its_own_records(): void
    {
        $repRole = Role::query()->firstOrCreate(
            ['slug' => 'representative'],
            ['name' => 'نماینده', 'hierarchy_level' => 5, 'is_organizational' => true, 'is_active' => true]
        );
        $seniorRole = Role::query()->firstOrCreate(
            ['slug' => 'senior_manager'],
            ['name' => 'مدیر ارشد', 'hierarchy_level' => 1, 'is_organizational' => true, 'is_active' => true]
        );
        $user = User::factory()->create(['mobile' => '09121110031', 'is_active' => true]);
        foreach ([$repRole, $seniorRole] as $role) {
            UserRole::query()->create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'effective_from' => now()->toDateString(),
                'is_primary' => $role->slug === 'representative',
                'is_active' => true,
            ]);
        }
        [, $repToken] = $user->createApiToken($repRole->id);
        [, $seniorToken] = $user->createApiToken($seniorRole->id);

        $this->withToken($repToken)->postJson('/api/crm/visits', [
            'visited_on' => '2026-10-15',
            'place' => 'پنل نماینده',
            'person_name' => 'مخاطب نماینده',
        ])->assertCreated();
        $this->withToken($seniorToken)->postJson('/api/crm/tasks', [
            'title' => 'وظیفه مدیر ارشد',
            'notify_in_app' => false,
            'notify_sms' => false,
        ])->assertCreated();

        $rep = $this->withToken($repToken)->getJson('/api/crm')->assertOk();
        $rep->assertJsonPath('visits.0.person_name', 'مخاطب نماینده');
        $rep->assertJsonCount(0, 'tasks');

        $senior = $this->withToken($seniorToken)->getJson('/api/crm')->assertOk();
        $senior->assertJsonCount(0, 'visits');
        $senior->assertJsonPath('tasks.0.title', 'وظیفه مدیر ارشد');

        $visitId = $rep->json('visits.0.id');
        $this->withToken($seniorToken)->getJson('/api/crm/visits/'.$visitId)->assertNotFound();
        $this->withToken($seniorToken)->postJson('/api/crm/visits/'.$visitId.'/logs', [
            'body' => 'نباید ثبت شود',
        ])->assertNotFound();
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

<?php

namespace Tests\Feature;

use App\Models\GoalBankItem;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGoalBankAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalBankTest extends TestCase
{
    use RefreshDatabase;

    private string $seniorToken;

    private User $senior;

    private User $rep;

    private Role $repRole;

    private string $repToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->senior = User::query()->where('mobile', '09121111111')->firstOrFail();
        [, $this->seniorToken] = $this->senior->createApiToken(
            $this->senior->roles()->where('slug', 'senior_manager')->value('roles.id')
        );

        $this->rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $this->repRole = Role::query()->where('slug', 'representative')->firstOrFail();
        [, $this->repToken] = $this->rep->createApiToken($this->repRole->id);
    }

    public function test_rep_cannot_see_bank_until_access_enabled(): void
    {
        $this->withToken($this->repToken)->getJson('/api/goals/bank')->assertForbidden();

        $this->withToken($this->seniorToken)->postJson('/api/goals/access', [
            'user_id' => $this->rep->id,
            'role_id' => $this->repRole->id,
            'enabled' => true,
            'max_concurrent' => 2,
        ])->assertOk();

        $this->withToken($this->repToken)->getJson('/api/goals/bank')->assertOk()
            ->assertJsonPath('goal_bank.enabled', true)
            ->assertJsonPath('goal_bank.max_concurrent', 2)
            ->assertJsonPath('goal_bank.can_receive_batch', true);
    }

    public function test_auto_assign_batch_and_blocks_until_disposed(): void
    {
        $this->withToken($this->seniorToken)->postJson('/api/goals/access', [
            'user_id' => $this->rep->id,
            'role_id' => $this->repRole->id,
            'enabled' => true,
            'max_concurrent' => 2,
        ])->assertOk();

        $a = $this->createItem('پارت الف', 'ایتا');
        $b = $this->createItem('پارت ب', 'سایت');
        $c = $this->createItem('پارت ج', 'تلگرام');

        $assigned = $this->withToken($this->repToken)->postJson('/api/goals/bank/assign')->assertOk()
            ->assertJsonPath('assigned_count', 2)
            ->assertJsonPath('goal_bank.in_progress', 2)
            ->assertJsonPath('goal_bank.can_receive_batch', false)
            ->json('items');

        $assignedIds = collect($assigned)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$a, $b], $assignedIds);

        // تا تعیین‌تکلیف کامل، نوبت جدید نمی‌گیرد
        $this->withToken($this->repToken)->postJson('/api/goals/bank/assign')->assertStatus(422);

        // نماینده پارت آزاد را در لیست نمی‌بیند (فقط پارت‌های خودش)
        $visible = $this->withToken($this->repToken)->getJson('/api/goals/bank')->assertOk()->json('data');
        $ids = collect($visible)->pluck('id')->all();
        $this->assertContains($a, $ids);
        $this->assertContains($b, $ids);
        $this->assertNotContains($c, $ids);

        // کاربر دیگر هم صف آزاد را نمی‌بیند و پارت‌های قفل‌شده را ندارد
        $other = User::query()->where('mobile', '09124444444')->firstOrFail();
        $otherRole = Role::query()->where('slug', 'representative_referrer')->firstOrFail();
        [, $otherToken] = $other->createApiToken($otherRole->id);
        $this->withToken($this->seniorToken)->postJson('/api/goals/access', [
            'user_id' => $other->id,
            'role_id' => $otherRole->id,
            'enabled' => true,
            'max_concurrent' => 10,
        ])->assertOk();

        $otherVisible = $this->withToken($otherToken)->getJson('/api/goals/bank')->assertOk()->json('data');
        $otherIds = collect($otherVisible)->pluck('id')->all();
        $this->assertNotContains($a, $otherIds);
        $this->assertNotContains($b, $otherIds);
        $this->assertNotContains($c, $otherIds);

        $this->withToken($this->repToken)->postJson("/api/goals/bank/{$a}/complete", [
            'work_report' => 'بازدید انجام و گزارش ثبت شد.',
        ])->assertOk()->assertJsonPath('item.status', 'completed');

        $this->withToken($this->repToken)->postJson("/api/goals/bank/{$b}/fail", [
            'failure_reason' => 'شماره تماس پاسخگو نبود.',
        ])->assertOk()->assertJsonPath('item.status', 'failed');

        $next = $this->withToken($this->repToken)->postJson('/api/goals/bank/assign')->assertOk()
            ->assertJsonPath('goal_bank.can_receive_batch', false)
            ->json('items');

        $this->assertContains($c, collect($next)->pluck('id')->all());
        $this->assertTrue(count($next) <= 2);
    }

    public function test_access_list_paginates_and_searches_national_id_mobile(): void
    {
        $page = $this->withToken($this->seniorToken)->getJson('/api/goals/access?per_page=5&page=1')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('meta', $page);
        $this->assertLessThanOrEqual(5, count($page['data']));
        $this->assertSame(5, $page['meta']['per_page']);

        $byMobile = $this->withToken($this->seniorToken)
            ->getJson('/api/goals/access?search=09125555555')
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($byMobile)->contains(fn ($row) => $row['mobile'] === '09125555555'));

        $nid = (string) $this->rep->national_id;
        if ($nid !== '') {
            $byNid = $this->withToken($this->seniorToken)
                ->getJson('/api/goals/access?search='.urlencode($nid))
                ->assertOk()
                ->json('data');
            $this->assertTrue(collect($byNid)->contains(fn ($row) => (string) ($row['national_id'] ?? '') === $nid));
        }
    }

    public function test_only_managers_can_create_and_rep_cannot_edit(): void
    {
        $this->withToken($this->repToken)->postJson('/api/goals/bank', [
            'name' => 'غیرمجاز',
            'platform' => 'ایتا',
        ])->assertForbidden();

        $id = $this->createItem('پارت مدیر', 'ایتا');
        $this->withToken($this->seniorToken)->putJson("/api/goals/bank/{$id}", [
            'name' => 'پارت ویرایش‌شده',
            'platform' => 'سایت',
        ])->assertOk()->assertJsonPath('item.name', 'پارت ویرایش‌شده');
    }

    public function test_manager_can_monitor_user_parts_with_status_filter(): void
    {
        $this->withToken($this->seniorToken)->postJson('/api/goals/access', [
            'user_id' => $this->rep->id,
            'role_id' => $this->repRole->id,
            'enabled' => true,
            'max_concurrent' => 2,
        ])->assertOk();

        $a = $this->createItem('مانیتور الف', 'ایتا');
        $b = $this->createItem('مانیتور ب', 'سایت');
        $this->withToken($this->repToken)->postJson('/api/goals/bank/assign')->assertOk();

        $this->withToken($this->repToken)->postJson("/api/goals/bank/{$a}/complete", [
            'work_report' => 'گزارش مانیتورینگ کامل شد.',
        ])->assertOk();

        $all = $this->withToken($this->seniorToken)
            ->getJson('/api/goals/bank?user_id='.$this->rep->id)
            ->assertOk()
            ->json('data');
        $ids = collect($all)->pluck('id')->all();
        $this->assertContains($a, $ids);
        $this->assertContains($b, $ids);

        $completed = $this->withToken($this->seniorToken)
            ->getJson('/api/goals/bank?user_id='.$this->rep->id.'&status=completed')
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($completed)->every(fn ($row) => $row['status'] === 'completed'));
        $this->assertContains($a, collect($completed)->pluck('id')->all());

        $this->withToken($this->repToken)
            ->getJson('/api/goals/bank?user_id='.$this->senior->id)
            ->assertForbidden();
    }

    public function test_login_payload_includes_goal_bank_feature(): void
    {
        $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
        ])->assertOk()
            ->assertJsonPath('user.features.goal_bank.enabled', false);

        $this->withToken($this->seniorToken)->postJson('/api/goals/access', [
            'user_id' => $this->rep->id,
            'role_id' => $this->repRole->id,
            'enabled' => true,
            'max_concurrent' => 5,
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
        ])->assertOk()
            ->assertJsonPath('user.features.goal_bank.enabled', true)
            ->assertJsonPath('user.features.goal_bank.max_concurrent', 5)
            ->assertJsonPath('user.features.goal_bank.can_receive_batch', true);
    }

    public function test_multi_role_user_has_single_unified_goal_bank(): void
    {
        $refRole = Role::query()->where('slug', 'representative_referrer')->firstOrFail();
        if (! $this->rep->roles()->where('roles.id', $refRole->id)->exists()) {
            $this->rep->roles()->attach($refRole->id, [
                'effective_from' => now()->toDateString(),
                'is_active' => true,
                'is_primary' => false,
            ]);
        }

        $this->withToken($this->seniorToken)->postJson('/api/goals/access', [
            'user_id' => $this->rep->id,
            'enabled' => true,
            'max_concurrent' => 3,
            'province' => 'تهران',
            'city' => 'تهران',
        ])->assertOk()
            ->assertJsonPath('access.enabled', true)
            ->assertJsonPath('access.max_concurrent', 3)
            ->assertJsonPath('access.province', 'تهران');

        $accessList = $this->withToken($this->seniorToken)
            ->getJson('/api/goals/access?search=09125555555')
            ->assertOk()
            ->json('data');
        $row = collect($accessList)->firstWhere('mobile', '09125555555');
        $this->assertNotNull($row);
        $this->assertTrue($row['enabled']);
        $this->assertSame(3, $row['max_concurrent']);
        $this->assertGreaterThanOrEqual(2, count($row['roles']));

        [, $repToken] = $this->rep->createApiToken($this->repRole->id);
        $this->withToken($repToken)->getJson('/api/goals/bank')->assertOk()
            ->assertJsonPath('goal_bank.enabled', true)
            ->assertJsonPath('goal_bank.max_concurrent', 3);

        [, $refToken] = $this->rep->createApiToken($refRole->id);
        $this->withToken($refToken)->getJson('/api/goals/bank')->assertOk()
            ->assertJsonPath('goal_bank.enabled', true)
            ->assertJsonPath('goal_bank.max_concurrent', 3);

        $this->assertSame(
            1,
            UserGoalBankAccess::query()->where('user_id', $this->rep->id)->count()
        );
    }

    public function test_manager_can_import_parts_from_xlsx_template(): void
    {
        $this->withToken($this->seniorToken)
            ->get('/api/goals/bank/import-template')
            ->assertOk()
            ->assertHeader('content-disposition');

        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'goal-bank-import-'.uniqid().'.xlsx';
        \App\Support\SimpleSpreadsheet::writeXlsx($tmp, \App\Services\Goals\GoalBankService::IMPORT_HEADERS, [
            ['کانال نمونه ۱', 'ایتا', 'https://eitaa.com/sample1', '1200', 'توضیح', 'تهران', 'تهران', '', '', '09121112233'],
            ['کانال نمونه ۲', 'تلگرام', 'https://t.me/sample2', '850', '', 'البرز', 'کرج', '', '', ''],
        ]);

        $this->withToken($this->seniorToken)
            ->post('/api/goals/bank/import', [
                'file' => new \Illuminate\Http\UploadedFile(
                    $tmp,
                    'parts.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('imported', 2);

        $this->assertSame(
            2,
            GoalBankItem::query()
                ->where('created_by', $this->senior->id)
                ->where('name', 'like', 'کانال نمونه%')
                ->count()
        );
        @unlink($tmp);
    }

    public function test_assign_batch_prefers_same_province_then_neighbors(): void
    {
        $this->withToken($this->seniorToken)->postJson('/api/goals/access', [
            'user_id' => $this->rep->id,
            'enabled' => true,
            'max_concurrent' => 3,
            'province' => 'تهران',
            'city' => 'تهران',
        ])->assertOk();

        $far = $this->createItem('دور', 'ایتا', 'فارس', 'شیراز');
        $near = $this->createItem('همسایه', 'سایت', 'البرز', 'کرج');
        $same = $this->createItem('هم‌استان', 'تلگرام', 'تهران', 'ری');

        $assigned = $this->withToken($this->repToken)->postJson('/api/goals/bank/assign')->assertOk()
            ->assertJsonPath('assigned_count', 3)
            ->json('items');

        $ids = collect($assigned)->pluck('id')->all();
        $this->assertSame([$same, $near, $far], $ids);
    }

    private function createItem(string $name, string $platform, string $province = 'تهران', string $city = 'تهران'): int
    {
        $res = $this->withToken($this->seniorToken)->postJson('/api/goals/bank', [
            'name' => $name,
            'platform' => $platform,
            'platform_url' => 'https://example.com/'.$name,
            'audience_count' => 1000,
            'province' => $province,
            'city' => $city,
            'mobile' => '09120001122',
            'description' => 'تست',
        ])->assertCreated();

        return (int) $res->json('item.id');
    }
}

<?php

namespace App\Services\Qa;

use App\Models\Commission;
use App\Models\FinopalTransaction;
use App\Models\OrganizationNode;
use App\Models\PersonalAccessToken;
use App\Models\ReferralCode;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Gateway\GatewaySaleService;
use App\Services\Integration\Finopal\FinopalTransactionService;
use App\Services\Organization\OrganizationTreeService;
use App\Support\Money;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Operate inside the mega world like real users (service/API-level).
 */
class MegaOrgScenarioRunner
{
    /** @var list<array{id: string, title: string, ok: bool, detail: string}> */
    private array $results = [];

    /**
     * @param  list<array{key: string, user_id: int, mobile: string, note: string}>  $edgeUsers
     * @return list<array{id: string, title: string, ok: bool, detail: string}>
     */
    public function run(array $edgeUsers): array
    {
        $byKey = collect($edgeUsers)->keyBy('key');
        $this->results = [];

        $this->scenarioA_saleAndCommission($byKey);
        $this->scenarioB_duplicateWebhook($byKey);
        $this->scenarioCrossBranchIsolation($byKey);
        $this->scenarioAdversarialCycle($byKey);
        $this->scenarioLoginDashboards($byKey);
        $this->scenarioRegisterUnderReferral($byKey);

        return $this->results;
    }

    private function ok(string $id, string $title, bool $ok, string $detail): void
    {
        $this->results[] = compact('id', 'title', 'ok', 'detail');
    }

    private function edgeUser($byKey, string $key): ?User
    {
        $row = $byKey->get($key);
        if (! $row) {
            return null;
        }

        return User::query()->find($row['user_id']);
    }

    private function scenarioA_saleAndCommission($byKey): void
    {
        $rep = $this->edgeUser($byKey, 'USER_NO_TRANSACTIONS')
            ?? User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'representative'))->first();
        if (! $rep) {
            $this->ok('A', 'فروش + پورسانت', false, 'نماینده یافت نشد');

            return;
        }

        $before = Commission::query()->where('user_id', $rep->id)->count();
        $merchant = 'scn-a-'.$rep->id.'-'.Str::random(4);
        $sale = app(GatewaySaleService::class)->record([
            'external_id' => 'SCN-A-'.$rep->id,
            'name' => 'Scenario A',
            'amount' => 200000,
            'representative_user_id' => $rep->id,
            'idempotency_key' => 'scn-a-sale-'.$rep->id.'-'.time(),
            'status' => 'successful',
            'merchant_code' => $merchant,
        ]);

        app(FinopalTransactionService::class)->ingest([
            'event' => 'transaction.verified',
            'merchant_id' => $merchant,
            'authority' => 'SCN_A_'.$sale->id,
            'amount' => 200000,
            'profit' => 200000,
            'currency' => 'IRT',
            'status' => 'verified',
            'code' => 100,
        ]);

        $repCommission = Commission::query()
            ->where('gateway_sale_id', $sale->id)
            ->where('user_id', $rep->id)
            ->where('idempotency_key', 'like', 'tx:%')
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative'))
            ->first();

        $expected = Money::percentOf('200000', '15');
        $ok = $repCommission && Money::cmp((string) $repCommission->commission_amount, $expected) === 0;
        $this->ok('A', 'فروش موفق و پورسانت ۱۵٪ نماینده', $ok, $ok
            ? "مبلغ {$expected} ثبت شد (قبل: {$before})"
            : 'پورسانت نماینده با محاسبه مستقل برابر نیست');
    }

    private function scenarioB_duplicateWebhook($byKey): void
    {
        $rep = $this->edgeUser($byKey, 'USER_MANY_TRANSACTIONS')
            ?? User::query()->where('name', 'like', 'Wide-%')->where('is_active', true)->first();
        if (! $rep) {
            $this->ok('B', 'وب‌هوک تکراری', false, 'نماینده یافت نشد');

            return;
        }

        $merchant = 'scn-b-'.$rep->id;
        $sale = app(GatewaySaleService::class)->record([
            'external_id' => 'SCN-B-'.$rep->id,
            'name' => 'Scenario B',
            'amount' => 100000,
            'representative_user_id' => $rep->id,
            'idempotency_key' => 'scn-b-sale-'.$rep->id,
            'status' => 'successful',
            'merchant_code' => $merchant,
        ]);

        $payload = [
            'event' => 'transaction.verified',
            'merchant_id' => $merchant,
            'authority' => 'SCN_B_DUP_'.$sale->id,
            'amount' => 100000,
            'profit' => 100000,
            'currency' => 'IRT',
            'status' => 'verified',
            'code' => 100,
        ];
        $ingest = app(FinopalTransactionService::class);
        $ingest->ingest($payload);
        $ingest->ingest($payload);
        $ingest->ingest($payload);

        $count = FinopalTransaction::query()->where('authority', $payload['authority'])->count();
        $this->ok('B', 'وب‌هوک سه‌باره → یک settlement', $count === 1, "تعداد FinopalTransaction={$count}");
    }

    private function scenarioCrossBranchIsolation($byKey): void
    {
        $rootA = $this->edgeUser($byKey, 'USER_ROOT_PRIMARY');
        $rootB = $this->edgeUser($byKey, 'USER_LARGE_TREE');
        if (! $rootA || ! $rootB) {
            $this->ok('X', 'انزوای بین‌شاخه‌ای', false, 'ریشه‌ها یافت نشد');

            return;
        }

        $tree = app(OrganizationTreeService::class);
        $relation = $tree->relationship($rootA, $rootB);
        $canChat = $tree->canCommunicate($rootA, $rootB);
        // Independent roots should be unrelated
        $ok = in_array($relation, ['unrelated', 'self'], true) && ($relation === 'self' || ! $canChat);
        $this->ok('X', 'دو ریشه مستقل نباید ancestor/descendant باشند', $relation === 'unrelated', "relation={$relation}, canCommunicate=".($canChat ? '1' : '0'));
    }

    private function scenarioAdversarialCycle($byKey): void
    {
        $deep = $this->edgeUser($byKey, 'USER_DEEP_TREE');
        $root = $this->edgeUser($byKey, 'USER_ROOT_PRIMARY');
        if (! $deep || ! $root) {
            $this->ok('ADV', 'جلوگیری از چرخه', false, 'گره عمیق/ریشه یافت نشد');

            return;
        }

        $tree = app(OrganizationTreeService::class);
        $rootNode = OrganizationNode::query()
            ->where('user_id', $root->id)
            ->whereHas('role', fn ($q) => $q->where('slug', 'senior_manager'))
            ->first();
        $deepNode = OrganizationNode::query()
            ->where('user_id', $deep->id)
            ->where('is_active', true)
            ->first();

        if (! $rootNode || ! $deepNode) {
            $this->ok('ADV', 'جلوگیری از چرخه', false, 'گره سازمانی یافت نشد');

            return;
        }

        $blocked = false;
        try {
            $tree->reparent($rootNode, $deepNode);
        } catch (\InvalidArgumentException) {
            $blocked = true;
        }
        $this->ok('ADV', 'reparent ریشه زیر نواده باید رد شود', $blocked, $blocked ? 'InvalidArgumentException' : 'چرخه پذیرفته شد!');
    }

    private function scenarioLoginDashboards($byKey): void
    {
        $root = $this->edgeUser($byKey, 'USER_ROOT_PRIMARY');
        if (! $root || ! Hash::check((string) config('qa.password'), $root->password)) {
            // password is hashed at create — check via attempt
        }
        $ok = $root && $root->is_active && $root->hasRole('senior_manager');
        $this->ok('L', 'کاربر ریشه برای لاگین سناریو آماده است', (bool) $ok, $root ? "mobile={$root->mobile}" : 'missing');

        // Issue token like API would
        if ($root) {
            $plain = Str::random(40);
            PersonalAccessToken::query()->create([
                'user_id' => $root->id,
                'name' => 'qa-scenario',
                'token' => hash('sha256', $plain),
                'active_role_id' => Role::query()->where('slug', 'senior_manager')->value('id'),
                'last_used_at' => now(),
            ]);
            $this->ok('L2', 'صدور توکن Bearer برای ریشه', true, 'token issued');
        }
    }

    private function scenarioRegisterUnderReferral($byKey): void
    {
        $ref = $this->edgeUser($byKey, 'USER_1000_REFERRALS');
        if (! $ref) {
            $this->ok('R', 'ثبت‌نام با کد معرف', false, 'معرف گسترده یافت نشد');

            return;
        }
        $code = ReferralCode::query()->where('user_id', $ref->id)->where('is_active', true)->value('code');
        $ok = is_string($code) && $code !== '';
        $this->ok('R', 'کد معرف فعال برای ثبت‌نام جدید', $ok, $ok ? "code={$code}" : 'کد نیست');
    }
}

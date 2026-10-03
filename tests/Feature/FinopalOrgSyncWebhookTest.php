<?php

namespace Tests\Feature;

use App\Models\OrganizationNode;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Sms\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FinopalOrgSyncWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['finopal.webhook_secret' => 'test-webhook-secret']);
    }

    public function test_org_structure_webhook_creates_full_tree_solo_rep(): void
    {
        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('sendWelcome')->times(4)->andReturn(true);
        $this->app->instance(SmsService::class, $sms);

        $payload = [
            'event' => 'mlm.structure.sync',
            'MLM_Structure' => [
                'Senior' => [
                    'Farasof_User_ID' => 'FS-SENIOR-1',
                    'NationalCode' => '0011111111',
                    'PhoneNumber' => '09121000001',
                    'BirthDate' => '1990-01-01',
                    'ShebaNumber' => 'IR120170000000123456789001',
                ],
                'DM' => [
                    'Farasof_User_ID' => 'FS-DM-1',
                    'NationalCode' => '0022222222',
                    'PhoneNumber' => '09121000002',
                    'BirthDate' => '1991-02-02',
                    'ShebaNumber' => 'IR120170000000123456789002',
                ],
                'SM' => [
                    'Farasof_User_ID' => 'FS-SM-1',
                    'NationalCode' => '0033333333',
                    'PhoneNumber' => '09121000003',
                    'BirthDate' => '1992-03-03',
                    'ShebaNumber' => 'IR120170000000123456789003',
                ],
                'REPS' => [
                    'Farasof_User_ID' => 'FS-REP-1',
                    'MLM_Commission' => '100',
                    'NationalCode' => '0044444444',
                    'PhoneNumber' => '09121000004',
                    'BirthDate' => '1993-04-04',
                    'ShebaNumber' => 'IR120170000000123456789004',
                ],
            ],
        ];

        $res = $this->postJson('/api/webhooks/finopal/org-structure', $payload, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()->json();

        $this->assertTrue($res['success']);
        $this->assertSame(4, $res['created_count']);
        $this->assertSame(0, $res['skipped_count']);
        $this->assertSame(4, $res['welcome_sms_queued']);

        $senior = User::query()->where('national_id', '0011111111')->first();
        $dm = User::query()->where('national_id', '0022222222')->first();
        $sm = User::query()->where('national_id', '0033333333')->first();
        $rep = User::query()->where('national_id', '0044444444')->first();

        $this->assertNotNull($senior);
        $this->assertTrue($senior->hasRole('senior_manager'));
        $this->assertTrue($dm->hasRole('development_manager'));
        $this->assertTrue($sm->hasRole('sales_manager'));
        $this->assertTrue($rep->hasRole('representative'));

        $seniorNode = OrganizationNode::query()->where('user_id', $senior->id)
            ->whereHas('role', fn ($q) => $q->where('slug', 'senior_manager'))->first();
        $dmNode = OrganizationNode::query()->where('user_id', $dm->id)
            ->whereHas('role', fn ($q) => $q->where('slug', 'development_manager'))->first();
        $smNode = OrganizationNode::query()->where('user_id', $sm->id)
            ->whereHas('role', fn ($q) => $q->where('slug', 'sales_manager'))->first();
        $repNode = OrganizationNode::query()->where('user_id', $rep->id)
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative'))->first();

        $this->assertSame((int) $seniorNode->id, (int) $dmNode->parent_node_id);
        $this->assertSame((int) $dmNode->id, (int) $smNode->parent_node_id);
        $this->assertSame((int) $smNode->id, (int) $repNode->parent_node_id);

        $this->assertTrue(
            Wallet::query()
                ->where('user_id', $senior->id)
                ->where('kind', Wallet::KIND_BONUS_RESIDUAL)
                ->exists()
        );
    }

    public function test_org_structure_skips_existing_national_code_and_creates_shared_reps(): void
    {
        $first = [
            'event' => 'mlm.structure.sync',
            'MLM_Structure' => [
                'Senior' => [
                    'Farasof_User_ID' => 'FS-SENIOR-2',
                    'NationalCode' => '0055555555',
                    'PhoneNumber' => '09121000011',
                ],
                'DM' => [
                    'Farasof_User_ID' => 'FS-DM-2',
                    'NationalCode' => '0066666666',
                    'PhoneNumber' => '09121000012',
                ],
                'SM' => [
                    'Farasof_User_ID' => 'FS-SM-2',
                    'NationalCode' => '0077777777',
                    'PhoneNumber' => '09121000013',
                ],
                'REPS' => [
                    [
                        'Farasof_User_ID' => 'FS-REP-A',
                        'MLM_Commission' => '50',
                        'NationalCode' => '0088888888',
                        'PhoneNumber' => '09121000014',
                    ],
                    [
                        'Farasof_User_ID' => 'FS-REP-B',
                        'MLM_Commission' => '50',
                        'NationalCode' => '0099999999',
                        'PhoneNumber' => '09121000015',
                    ],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/finopal/org-structure', $first, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()->assertJsonPath('created_count', 5);

        $again = $this->postJson('/api/webhooks/finopal/org-structure', $first, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()->json();

        $this->assertSame(0, $again['created_count']);
        $this->assertSame(5, $again['skipped_count']);
        $this->assertTrue($again['structure']['shared']);
    }

    public function test_org_structure_rejects_bad_secret(): void
    {
        $this->postJson('/api/webhooks/finopal/org-structure', [
            'MLM_Structure' => ['Senior' => ['NationalCode' => '0012345678', 'PhoneNumber' => '09121234567']],
        ], [
            'X-Finopal-Webhook-Secret' => 'wrong',
        ])->assertUnauthorized();
    }
}

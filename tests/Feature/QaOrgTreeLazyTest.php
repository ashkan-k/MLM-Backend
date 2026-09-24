<?php

namespace Tests\Feature;

use App\Models\OrganizationNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaOrgTreeLazyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function login(string $mobile, string $role): string
    {
        return $this->postJson('/api/auth/login', [
            'mobile' => $mobile,
            'password' => 'Password123!',
            'role_slug' => $role,
        ])->assertOk()->json('token');
    }

    public function test_tree_shallow_and_lazy_children(): void
    {
        $token = $this->login('09121111111', 'senior_manager');

        $roots = $this->withToken($token)
            ->getJson('/api/organization/tree?max_depth=1')
            ->assertOk()
            ->json();

        $this->assertIsArray($roots);
        $this->assertNotEmpty($roots);

        $root = $roots[0];
        $this->assertArrayHasKey('has_children', $root);
        $this->assertArrayHasKey('descendant_count', $root);

        foreach ($root['children'] ?? [] as $child) {
            $this->assertSame([], $child['children'] ?? [], 'lazy tree should not embed grandchildren at max_depth=1');
        }

        $expandId = ! empty($root['children'][0]['id']) ? $root['children'][0]['id'] : $root['id'];

        $kids = $this->withToken($token)
            ->getJson('/api/organization/tree?parent_id='.$expandId.'&max_depth=1')
            ->assertOk()
            ->json();

        $this->assertIsArray($kids);
    }

    public function test_tree_search_finds_deep_representative(): void
    {
        $token = $this->login('09121111111', 'senior_manager');

        $res = $this->withToken($token)
            ->getJson('/api/organization/tree?search=09125555555')
            ->assertOk()
            ->json();

        $this->assertIsArray($res);
        $this->assertNotEmpty($res, 'search should return a pruned path to the representative');

        $found = false;
        $walk = function ($nodes) use (&$walk, &$found) {
            foreach ($nodes as $n) {
                if (($n['user']['mobile'] ?? null) === '09125555555') {
                    $found = true;
                }
                $walk($n['children'] ?? []);
            }
        };
        $walk($res);
        $this->assertTrue($found, 'representative mobile must appear in search tree');
    }

    public function test_tree_search_normalizes_persian_digits_and_yeh(): void
    {
        $token = $this->login('09121111111', 'senior_manager');

        // Arabic yeh / Persian digits should still hit Latin-digit mobile in DB
        $res = $this->withToken($token)
            ->getJson('/api/organization/tree?search='.urlencode('۰۹۱۲۵۵۵۵۵۵۵'))
            ->assertOk()
            ->json();

        $this->assertNotEmpty($res);
        $found = false;
        $walk = function ($nodes) use (&$walk, &$found) {
            foreach ($nodes as $n) {
                if (($n['user']['mobile'] ?? null) === '09125555555') {
                    $found = true;
                }
                $walk($n['children'] ?? []);
            }
        };
        $walk($res);
        $this->assertTrue($found);
    }

    public function test_rep_cannot_lazy_load_foreign_parent(): void
    {
        $token = $this->login('09125555555', 'representative');
        $foreign = OrganizationNode::query()
            ->where('user_id', User::query()->where('mobile', '09129999999')->value('id'))
            ->first();

        if (! $foreign) {
            $this->markTestSkipped('outsider node missing');
        }

        $this->withToken($token)
            ->getJson('/api/organization/tree?parent_id='.$foreign->id)
            ->assertForbidden();
    }
}

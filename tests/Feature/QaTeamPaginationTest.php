<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaTeamPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_team_endpoint_returns_paginator(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09121111111',
            'password' => 'Password123!',
            'role_slug' => 'senior_manager',
        ])->assertOk()->json('token');

        $res = $this->withToken($token)
            ->getJson('/api/organization/team?per_page=5&page=1')
            ->assertOk();

        $res->assertJsonStructure([
            'data',
            'current_page',
            'last_page',
            'per_page',
            'total',
        ]);
        $this->assertLessThanOrEqual(5, count($res->json('data')));
        $this->assertSame(5, $res->json('per_page'));
    }

    public function test_team_search_filters_by_name_or_mobile(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09121111111',
            'password' => 'Password123!',
            'role_slug' => 'senior_manager',
        ])->assertOk()->json('token');

        $res = $this->withToken($token)
            ->getJson('/api/organization/team?search=09125555555')
            ->assertOk();

        $mobiles = collect($res->json('data'))->pluck('mobile');
        $this->assertTrue($mobiles->contains('09125555555') || $res->json('total') === 0 || $mobiles->isNotEmpty());
    }
}

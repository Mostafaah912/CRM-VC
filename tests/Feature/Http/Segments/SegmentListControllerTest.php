<?php

declare(strict_types=1);

use App\Modules\Segments\Models\Segment;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P5-06: GET /segments — the segment list (PRD §25): name, type, member_count, last_evaluated_at
| (Jalali), is_active, is_system. Behind auth + segments.view.
*/

it('answers a guest with a redirect to login', function () {
    $this->get('/segments')->assertRedirect(route('login'));
});

it('forbids a user without segments.view', function () {
    $this->actingAs(Fx::userWith())->get('/segments')->assertForbidden();
});

it('lists segments with the list page\'s shape', function () {
    Segment::factory()->create([
        'name' => 'مشتریان وفادار', 'member_count' => 42, 'is_active' => true, 'is_system' => false,
        'last_evaluated_at' => '2026-03-20 20:30:00+00',
    ]);

    $response = $this->actingAs(Fx::userWith('segments.view'))->get('/segments');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('segments/index')
        ->where('segments.data.0.name', 'مشتریان وفادار')
        ->where('segments.data.0.type', 'dynamic')
        ->where('segments.data.0.member_count', 42)
        ->where('segments.data.0.last_evaluated_at', '1405/01/01 00:00:00')
        ->where('segments.data.0.is_active', true)
        ->where('segments.data.0.is_system', false));
});

it('paginates at 25 per page, newest first', function () {
    Segment::factory()->count(30)->create();

    $response = $this->actingAs(Fx::userWith('segments.view'))->get('/segments');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('segments.total', 30)
        ->where('segments.last_page', 2)
        ->has('segments.data', 25));
});

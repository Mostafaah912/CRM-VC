<?php

declare(strict_types=1);

use App\Modules\Segments\Jobs\EvaluateSegmentJob;
use App\Modules\Segments\Models\Segment;
use Illuminate\Support\Facades\Bus;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P5-06: POST /segments/{segment}/evaluate — queues EvaluateSegmentJob rather than running evaluate()
| inline (see the Job's docblock: measured ~2.2-2.5s on dev data for the broadest rule). Behind auth +
| segments.edit.
*/

it('answers a guest with a redirect to login and queues nothing', function () {
    Bus::fake();
    $segment = Segment::factory()->create();

    $this->post("/segments/{$segment->id}/evaluate")->assertRedirect(route('login'));

    Bus::assertNotDispatched(EvaluateSegmentJob::class);
});

it('forbids a user without segments.edit and queues nothing', function () {
    Bus::fake();
    $segment = Segment::factory()->create();

    $this->actingAs(Fx::userWith('segments.view'))
        ->post("/segments/{$segment->id}/evaluate")
        ->assertForbidden();

    Bus::assertNotDispatched(EvaluateSegmentJob::class);
});

it('queues EvaluateSegmentJob for this segment and redirects back', function () {
    Bus::fake();
    $segment = Segment::factory()->create();

    $response = $this->actingAs(Fx::userWith('segments.edit'))->post("/segments/{$segment->id}/evaluate");

    $response->assertRedirect();
    Bus::assertDispatched(EvaluateSegmentJob::class, fn (EvaluateSegmentJob $job) => $job->segmentId === $segment->id);
});

it('answers 404 for a missing segment and a non-number, and queues nothing', function () {
    Bus::fake();
    $user = Fx::userWith('segments.edit');

    $this->actingAs($user)->post('/segments/987654321/evaluate')->assertNotFound();
    $this->actingAs($user)->post('/segments/abc/evaluate')->assertNotFound();

    Bus::assertNotDispatched(EvaluateSegmentJob::class);
});

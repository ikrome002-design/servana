<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scheduling', 'local-uat-001', 'query-boolean');

/*
 | LOCAL-UAT-001, defect LUAT-D02 — query-string boolean filters.
 |
 | The OpenAPI contract declares these list filters `in: query, schema: {type: boolean}`, whose
 | standard serialisation is `?active=true` / `?active=false`; it is exactly what the SPA's HTTP client
 | sends. Laravel's `boolean` rule accepts only true/false/1/0/"1"/"0", so the real browser received
 | 422 "The active field must be true or false." and the Branch queue, Front Office queue, Front Office
 | sessions and Personnel work-queue screens showed no data. Feature tests never saw it because the test
 | client serialises PHP `true` as `1`. These cases send the RAW query string the browser sends.
 */

it('accepts ?active=true and ?active=false on the branch queue list', function (): void {
    $scn = queueScenario();

    foreach (['true', 'false', 'TRUE', '1', '0'] as $value) {
        $this->actingAs($scn['frontOffice'], 'sanctum')
            ->getJson("/api/v1/queue-entries?active={$value}&sort=position")
            ->assertOk();
    }
});

it('accepts ?active=true on the service-session list', function (): void {
    $scn = queueScenario();

    $this->actingAs($scn['frontOffice'], 'sanctum')
        ->getJson('/api/v1/service-sessions?sort=-created_at&active=true')
        ->assertOk();
});

it('accepts ?active=true on the Personnel own queue and own sessions', function (): void {
    $scn = queueScenario();

    $this->actingAs($scn['staffUser'], 'sanctum')
        ->getJson('/api/v1/personnel/me/queue?active=true&sort=position')
        ->assertOk();
    $this->actingAs($scn['staffUser'], 'sanctum')
        ->getJson('/api/v1/personnel/me/sessions?active=false')
        ->assertOk();
});

it('accepts ?has_scheduled_plan_change=true on the platform subscription list', function (): void {
    $admin = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($admin);

    $this->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/platform/subscriptions?has_scheduled_plan_change=true')
        ->assertOk();
});

it('still rejects a value that is not a boolean (negative control)', function (): void {
    $scn = queueScenario();

    $this->actingAs($scn['frontOffice'], 'sanctum')
        ->getJson('/api/v1/queue-entries?active=maybe')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
    $this->actingAs($scn['frontOffice'], 'sanctum')
        ->getJson('/api/v1/queue-entries?active=yes')
        ->assertStatus(422);
});

it('filters to the live board for ?active=true and not for ?active=false', function (): void {
    $scn = queueScenario();
    $entry = createWalkIn($scn)->assertCreated()->json('data.id');

    $live = $this->actingAs($scn['frontOffice'], 'sanctum')->getJson('/api/v1/queue-entries?active=true')->assertOk();
    expect(collect($live->json('data'))->pluck('id')->all())->toContain($entry);

    $all = $this->actingAs($scn['frontOffice'], 'sanctum')->getJson('/api/v1/queue-entries?active=false')->assertOk();
    expect(collect($all->json('data'))->pluck('id')->all())->toContain($entry);
});

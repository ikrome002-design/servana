<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('generates a correlation id when none is provided', function (): void {
    $response = $this->get('/health');

    $id = $response->headers->get('X-Correlation-ID');

    expect($id)->not->toBeNull()
        ->and(strlen((string) $id))->toBeLessThanOrEqual(64)
        ->and($id)->toMatch('/^[A-Za-z0-9._-]+$/');
});

it('propagates a safe inbound correlation id unchanged', function (): void {
    $response = $this->get('/health', ['X-Correlation-ID' => 'trace-abc_123']);

    expect($response->headers->get('X-Correlation-ID'))->toBe('trace-abc_123');
});

it('replaces an over-long inbound correlation id', function (): void {
    $tooLong = str_repeat('a', 200);

    $response = $this->get('/health', ['X-Correlation-ID' => $tooLong]);
    $id = $response->headers->get('X-Correlation-ID');

    expect($id)->not->toBe($tooLong)
        ->and(strlen((string) $id))->toBeLessThanOrEqual(64);
});

it('replaces an inbound correlation id containing unsafe characters', function (): void {
    $response = $this->get('/health', ['X-Correlation-ID' => 'bad value !@# <script>']);
    $id = (string) $response->headers->get('X-Correlation-ID');

    expect($id)->not->toContain(' ')
        ->and($id)->not->toContain('<')
        ->and($id)->toMatch('/^[A-Za-z0-9._-]+$/');
});

it('includes the correlation id in 5xx envelopes and echoes the safe inbound id', function (): void {
    Route::get('/api/v1/__test/explode', fn () => throw new RuntimeException('x'));

    $response = $this->getJson('/api/v1/__test/explode', ['X-Correlation-ID' => 'trace-xyz']);

    $response->assertStatus(500)
        ->assertJsonPath('error.meta.correlation_id', 'trace-xyz');

    expect($response->headers->get('X-Correlation-ID'))->toBe('trace-xyz');
});

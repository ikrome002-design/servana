<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Integrations\ReferEarn\Actions\DeliverProductEvent;
use App\Domain\Integrations\ReferEarn\Clients\Dto\EventDeliveryResult;
use App\Domain\Integrations\ReferEarn\Clients\FakeReferEarnClient;
use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryResponseClass;
use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryStatus;
use App\Domain\Integrations\ReferEarn\Exceptions\ReferEarnSigningException;
use App\Domain\Integrations\ReferEarn\Models\ReEventDelivery;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use App\Domain\Integrations\ReferEarn\Support\CanonicalJson;
use App\Domain\Integrations\ReferEarn\Support\CitrusEventSigner;
use App\Domain\Integrations\ReferEarn\Support\DeliveryResponseRedactor;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class)->group('referearn', 'phase21ra', 'phase21ra-delivery');

/*
 | Signed outbound delivery — Plan §58A.2, §25.6, §9 rule 22, §24.5; ADR-015; §58B.5 R-06…R-09, R-21.
 |
 | Proves the exact canonical signing string, the complete X-Citrus-* header set, fail-closed
 | behaviour on an unpinned algorithm, retry with the SAME event id and body hash, the response-class
 | routing (409 / 422 / 5xx / 401), backoff caps, per-merchant ordering, and redaction of every
 | stored attempt.
 */

beforeEach(function (): void {
    $this->fake = app(FakeReferEarnClient::class);

    // A test-only signing contract. It is nothing like a production credential and never leaves
    // this process; CI configures none of this, which is why the fake client is bound (Plan §81
    // rule 21 — never call live partner systems from tests).
    config()->set('refer-earn.signing.algorithm', 'hmac-sha256');
    config()->set('refer-earn.signing.key_id', 'test-key-1');
    config()->set('refer-earn.signing.secret', 'test-secret');
    config()->set('refer-earn.product_code', 'SRV');
});

function pendingEvent(array $overrides = []): ReOutboundEvent
{
    return ReOutboundEvent::factory()->create($overrides);
}

it('signs the exact Plan §9 rule 22 canonical string', function (): void {
    $event = pendingEvent();
    $signer = app(CitrusEventSigner::class);

    $canonical = $signer->canonicalString('post', '/api/v1/integrations/products/SRV/events', '2026-07-22T04:00:00Z', 'NONCE1', 'abc123', $event);

    expect($canonical)->toBe(implode("\n", [
        'POST',
        '/api/v1/integrations/products/SRV/events',
        '2026-07-22T04:00:00Z',
        'NONCE1',
        'abc123',
        $event->event_id,
        $event->event_type->value,
        $event->event_version,
    ]));

    // Fixed signing vector: the same inputs must always produce the same signature.
    expect($signer->sign("POST\n/p\n2026-01-01T00:00:00Z\nN\nH\nE\nT\n1", 'hmac-sha256', 'secret'))
        ->toBe(hash_hmac('sha256', "POST\n/p\n2026-01-01T00:00:00Z\nN\nH\nE\nT\n1", 'secret'));
});

it('emits the complete X-Citrus header set plus the event-id idempotency key', function (): void {
    $event = pendingEvent();
    $body = CanonicalJson::encode($event->payload);

    $headers = app(CitrusEventSigner::class)->headers($event, 'POST', '/api/v1/integrations/products/SRV/events', $body, Carbon::parse('2026-07-22T04:00:00Z'));

    expect(array_keys($headers))->toBe([
        'X-Citrus-Key-Id', 'X-Citrus-Event-Id', 'X-Citrus-Event-Type', 'X-Citrus-Event-Version',
        'X-Citrus-Timestamp', 'X-Citrus-Nonce', 'X-Citrus-Content-SHA256', 'X-Citrus-Signature',
        'Idempotency-Key',
    ])
        ->and($headers['X-Citrus-Event-Id'])->toBe($event->event_id)
        ->and($headers['Idempotency-Key'])->toBe($event->event_id)
        ->and($headers['X-Citrus-Content-SHA256'])->toBe($event->content_sha256)
        // The signing timestamp is DELIVERY time, not the event's business occurred_at (R-21).
        ->and($headers['X-Citrus-Timestamp'])->toBe('2026-07-22T04:00:00Z')
        ->and($headers['X-Citrus-Nonce'])->toHaveLength(26);
});

it('fails closed when the signing algorithm is unpinned or unknown', function (?string $algorithm): void {
    config()->set('refer-earn.signing.algorithm', $algorithm);

    $event = pendingEvent();
    $body = CanonicalJson::encode($event->payload);

    expect(fn () => app(CitrusEventSigner::class)->headers($event, 'POST', '/p', $body))
        ->toThrow(ReferEarnSigningException::class);
})->with([
    'unpinned' => [null],
    'unknown identifier' => ['rsa-pss-sha512'],
    'blank' => [''],
]);

it('fails closed when the signing key id or secret is missing', function (string $key): void {
    config()->set($key, null);

    $event = pendingEvent();

    expect(fn () => app(CitrusEventSigner::class)->headers($event, 'POST', '/p', CanonicalJson::encode($event->payload)))
        ->toThrow(ReferEarnSigningException::class);
})->with(['refer-earn.signing.key_id', 'refer-earn.signing.secret']);

it('refuses to sign a body whose hash does not match the stored content_sha256', function (): void {
    $event = pendingEvent();

    expect(fn () => app(CitrusEventSigner::class)->headers($event, 'POST', '/p', '{"tampered":true}'))
        ->toThrow(ReferEarnSigningException::class, 'content_sha256');
});

it('delivers a pending event and marks it delivered on 202', function (): void {
    $event = pendingEvent();

    $result = app(DeliverProductEvent::class)->handle($event);

    expect($result?->isAccepted())->toBeTrue();

    $event->refresh();

    expect($event->delivery_status)->toBe(ReDeliveryStatus::Delivered)
        ->and($event->delivered_at)->not->toBeNull()
        ->and($event->attempt_count)->toBe(1)
        ->and($event->next_attempt_at)->toBeNull()
        ->and($event->last_response_status)->toBe(202)
        ->and($event->deliveries()->count())->toBe(1);

    // The bytes sent are the canonical encoding of the stored payload, and their hash matches.
    expect($this->fake->deliveredEvents[0]['content_sha256'])->toBe($event->content_sha256)
        ->and($this->fake->deliveredEvents[0]['body'])->toBe(CanonicalJson::encode($event->payload));
});

it('retries a 5xx with the same event id and the same body hash (R-06)', function (): void {
    $event = pendingEvent();

    $this->fake->queueDeliveryResult(
        new EventDeliveryResult(ReDeliveryResponseClass::ServerError, 503, 'UPSTREAM', null, 12),
    );

    app(DeliverProductEvent::class)->handle($event);

    $event->refresh();

    expect($event->delivery_status)->toBe(ReDeliveryStatus::Pending)
        ->and($event->attempt_count)->toBe(1)
        ->and($event->next_attempt_at?->isFuture())->toBeTrue()
        ->and($event->last_error_code)->toBe('UPSTREAM');

    // Second attempt succeeds — same id, same bytes.
    Carbon::setTestNow(now()->addHours(2));
    app(DeliverProductEvent::class)->handle($event->refresh());
    Carbon::setTestNow();

    $event->refresh();

    expect($event->delivery_status)->toBe(ReDeliveryStatus::Delivered)
        ->and($event->attempt_count)->toBe(2)
        ->and($this->fake->deliveredEvents)->toHaveCount(2)
        ->and($this->fake->deliveredEvents[0]['event_id'])->toBe($this->fake->deliveredEvents[1]['event_id'])
        ->and($this->fake->deliveredEvents[0]['content_sha256'])->toBe($this->fake->deliveredEvents[1]['content_sha256'])
        // BOTH attempts are on record — the trail is the only evidence of what happened.
        ->and($event->deliveries()->count())->toBe(2);
});

it('dead-letters permanently on a 409 payload mismatch and audits it (R-07)', function (): void {
    $event = pendingEvent();

    $this->fake->queueDeliveryResult(
        new EventDeliveryResult(ReDeliveryResponseClass::PayloadMismatch, 409, 'EVENT_ID_PAYLOAD_MISMATCH', null, 8),
    );

    app(DeliverProductEvent::class)->handle($event);

    $event->refresh();

    expect($event->delivery_status)->toBe(ReDeliveryStatus::DeadLetter)
        ->and($event->next_attempt_at)->toBeNull();

    $audit = AuditLog::query()->where('action', AuditEvent::ReEventDeadLettered->value)->sole();

    expect($audit->severity->value)->toBe('high')
        ->and($audit->context['event_id'])->toBe($event->event_id)
        ->and($audit->context['response_class'])->toBe('payload_mismatch');

    // A dead-lettered event is never picked up again — never mutate-and-resend.
    expect(app(DeliverProductEvent::class)->handle($event->refresh()))->toBeNull()
        ->and($this->fake->deliveredEvents)->toHaveCount(1);
});

it('dead-letters on a 422 schema rejection (R-08)', function (): void {
    $event = pendingEvent();

    $this->fake->queueDeliveryResult(
        new EventDeliveryResult(ReDeliveryResponseClass::SchemaRejected, 422, 'SCHEMA_INVALID', null, 5),
    );

    app(DeliverProductEvent::class)->handle($event);

    expect($event->refresh()->delivery_status)->toBe(ReDeliveryStatus::DeadLetter);
});

it('keeps a 401 retriable while flagging the credential problem', function (): void {
    $event = pendingEvent();

    $this->fake->queueDeliveryResult(
        new EventDeliveryResult(ReDeliveryResponseClass::Unauthorized, 401, 'BAD_KEY', null, 4),
    );

    app(DeliverProductEvent::class)->handle($event);

    // The event is not at fault; the credential is. It stays retriable (Plan §58A.2).
    expect($event->refresh()->delivery_status)->toBe(ReDeliveryStatus::Pending)
        ->and($event->attempt_count)->toBe(1);
});

it('caps the backoff and dead-letters past the max age', function (): void {
    $event = pendingEvent(['attempt_count' => 12]);

    $this->fake->queueDeliveryResult(
        new EventDeliveryResult(ReDeliveryResponseClass::ServerError, 503, null, null, 3),
    );

    app(DeliverProductEvent::class)->handle($event);
    $event->refresh();

    $cap = (int) config('refer-earn.delivery.backoff_cap_seconds');

    // Exponential growth is capped (plus at most 20% jitter).
    expect($event->next_attempt_at?->diffInSeconds(now()))->toBeLessThanOrEqual($cap * 1.2 + 5);

    // An event older than the max age stops for good, whatever the response class. `created_at` is
    // frozen by the append-only trigger, so the age is set at INSERT — which is also the only way it
    // could ever legitimately happen.
    $stale = pendingEvent(['created_at' => now()->subDays((int) config('refer-earn.delivery.max_age_days') + 1)]);

    $this->fake->queueDeliveryResult(
        new EventDeliveryResult(ReDeliveryResponseClass::ServerError, 503, null, null, 3),
    );

    app(DeliverProductEvent::class)->handle($stale);

    expect($stale->refresh()->delivery_status)->toBe(ReDeliveryStatus::DeadLetter);
});

it('preserves per-merchant ordering by refusing to skip an earlier undelivered event', function (): void {
    $merchant = Merchant::factory()->create();

    $first = pendingEvent(['merchant_id' => $merchant->id, 'merchant_public_id' => $merchant->ulid, 'sequence_no' => 1]);
    $second = pendingEvent(['merchant_id' => $merchant->id, 'merchant_public_id' => $merchant->ulid, 'sequence_no' => 2]);

    // The later event cannot jump the queue.
    expect(app(DeliverProductEvent::class)->handle($second))->toBeNull()
        ->and($second->refresh()->delivery_status)->toBe(ReDeliveryStatus::Pending)
        ->and($this->fake->deliveredEvents)->toBe([]);

    // Once the earlier one lands, the later one is free.
    app(DeliverProductEvent::class)->handle($first);
    app(DeliverProductEvent::class)->handle($second->refresh());

    expect($first->refresh()->delivery_status)->toBe(ReDeliveryStatus::Delivered)
        ->and($second->refresh()->delivery_status)->toBe(ReDeliveryStatus::Delivered);
});

it('does not hold a merchant behind another merchant', function (): void {
    $a = Merchant::factory()->create();
    $b = Merchant::factory()->create();

    pendingEvent(['merchant_id' => $a->id, 'merchant_public_id' => $a->ulid, 'sequence_no' => 1]);
    $other = pendingEvent(['merchant_id' => $b->id, 'merchant_public_id' => $b->ulid, 'sequence_no' => 1]);

    app(DeliverProductEvent::class)->handle($other);

    expect($other->refresh()->delivery_status)->toBe(ReDeliveryStatus::Delivered);
});

it('does not deliver an event whose next attempt is in the future', function (): void {
    $event = pendingEvent(['next_attempt_at' => now()->addHour()]);

    expect(app(DeliverProductEvent::class)->handle($event))->toBeNull()
        ->and($this->fake->deliveredEvents)->toBe([]);
});

it('stores a redacted, bounded response body on every attempt', function (): void {
    $event = pendingEvent();

    $this->fake->queueDeliveryResult(new EventDeliveryResult(
        ReDeliveryResponseClass::ServerError,
        503,
        'UPSTREAM',
        app(DeliveryResponseRedactor::class)->redact('{"signature":"deadbeefdeadbeefdeadbeefdeadbeef","email":"owner@example.com","referral_code":"SERVANA-X8T2K","detail":"upstream down"}'),
        7,
    ));

    app(DeliverProductEvent::class)->handle($event);

    $body = (string) ReEventDelivery::query()->sole()->response_body_truncated_redacted;

    expect($body)->not->toContain('deadbeef')
        ->not->toContain('owner@example.com')
        ->not->toContain('SERVANA-X8T2K')
        ->toContain('upstream down')
        ->and(mb_strlen($body))->toBeLessThanOrEqual(512);
});

it('redacts and bounds any partner body before persistence', function (): void {
    $redactor = app(DeliveryResponseRedactor::class);

    expect($redactor->redact('api_key=abc123secret&status=fail'))->toContain('api_key=[redacted]')
        ->and($redactor->redact('{"token": "xyz"}'))->toContain('[redacted]')
        ->and($redactor->redact('call 0712345678 now'))->toContain('[redacted-msisdn]')
        // A long hex-looking run is itself redacted (it is what a bare signature looks like), so the
        // truncation bound is proven with a non-hex filler.
        ->and($redactor->redact(str_repeat('z', 900)))->toHaveLength(512)
        ->and($redactor->redact(str_repeat('a', 64)))->toBe('[redacted]')
        ->and($redactor->redact('   '))->toBeNull()
        ->and($redactor->redact(null))->toBeNull();
});

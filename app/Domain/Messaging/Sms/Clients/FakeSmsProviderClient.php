<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Clients;

use App\Domain\Messaging\Sms\Clients\Dto\SmsSendResult;
use Illuminate\Support\Str;

/**
 * Deterministic in-process stand-in for an SMS provider (Plan §17.1 "sandbox fakes are used in CI
 * so no real credential ever reaches test environments"; §81 rule 21; §80 Phase 21S; Phase 21S).
 *
 * This is the bound implementation whenever the integration is disabled or incompletely
 * configured — which is every test run and every local environment — so no test can accidentally
 * reach a live provider. It performs NO network I/O.
 *
 * Tests script outcomes with {@see queueResult()}; anything unscripted takes the documented happy
 * path. Every call is recorded so a test can assert what Servana *would* have sent — including
 * that a suppressed or opted-out recipient was never sent at all.
 *
 * CONTACT PROTECTION IN THE FAKE ITSELF: {@see $sentReferences} records only the opaque
 * correlation reference. The destination number and the body are recorded ONLY in
 * {@see $sentPhoneDigestsForAssertions}, as a SHA-256 digest, so a test can prove "this number was
 * (or was not) sent to" without a plaintext number ever entering a test artefact, a failure diff,
 * or a Playwright trace.
 */
final class FakeSmsProviderClient implements SmsProviderClientInterface
{
    /** @var list<SmsSendResult> */
    private array $queue = [];

    /** @var list<array{reference: string, phone_digest: string, body_digest: string, segments_hint: int}> */
    public array $sentReferences = [];

    /** @var list<string> SHA-256 digests of every destination handed to this client. */
    public array $sentPhoneDigestsForAssertions = [];

    public function queueResult(SmsSendResult ...$results): void
    {
        foreach ($results as $result) {
            $this->queue[] = $result;
        }
    }

    public function reset(): void
    {
        $this->queue = [];
        $this->sentReferences = [];
        $this->sentPhoneDigestsForAssertions = [];
    }

    /** Whether a given plaintext number was submitted, asserted through its digest only. */
    public function hasSentTo(string $phone): bool
    {
        return in_array(hash('sha256', $phone), $this->sentPhoneDigestsForAssertions, true);
    }

    public function send(string $phone, string $body, string $reference): SmsSendResult
    {
        $digest = hash('sha256', $phone);

        $this->sentReferences[] = [
            'reference' => $reference,
            'phone_digest' => $digest,
            'body_digest' => hash('sha256', $body),
            'segments_hint' => 0,
        ];
        $this->sentPhoneDigestsForAssertions[] = $digest;

        return array_shift($this->queue)
            ?? SmsSendResult::accepted('FAKE-'.strtoupper(substr((string) Str::ulid(), -12)), null, 1);
    }

    public function providerSlug(): string
    {
        return 'fake';
    }
}

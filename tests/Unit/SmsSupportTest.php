<?php

declare(strict_types=1);

use App\Domain\Messaging\Sms\Support\PhoneNumberDisplayMasker;
use App\Domain\Messaging\Sms\Support\SmsCostCalculator;
use App\Domain\Messaging\Sms\Support\SmsMessageSegmentCalculator;
use App\Domain\Messaging\Sms\Support\SmsProviderPayloadRedactor;

uses()->group('messaging', 'sms', 'phase21s', 'unit');

/*
 | The four pure helpers behind the SMS surface. They are unit-tested because their correctness is
 | what makes the cost preview honest and the redaction trustworthy — neither should need a database
 | or an HTTP request to prove.
 */

/*
 |--------------------------------------------------------------------------
 | Segment arithmetic (GSM 03.38 / UCS-2)
 |--------------------------------------------------------------------------
 */

it('counts GSM-7 segments at the standard 160 / 153 boundaries', function (int $length, int $segments): void {
    $measurement = (new SmsMessageSegmentCalculator)->measure(str_repeat('a', $length));

    expect($measurement->characterCount)->toBe($length)
        ->and($measurement->segmentCount)->toBe($segments)
        ->and($measurement->requiresUnicode)->toBeFalse();
})->with([
    'one character' => [1, 1],
    'exactly one segment' => [160, 1],
    'one over' => [161, 2],
    'exactly two segments' => [306, 2],
    'one over two' => [307, 3],
]);

it('counts UCS-2 segments at the standard 70 / 67 boundaries once a non-GSM character appears', function (): void {
    $calculator = new SmsMessageSegmentCalculator;

    $single = $calculator->measure(str_repeat('a', 69).'☺');
    expect($single->requiresUnicode)->toBeTrue()->and($single->segmentCount)->toBe(1);

    $spill = $calculator->measure(str_repeat('a', 70).'☺');
    expect($spill->requiresUnicode)->toBeTrue()->and($spill->segmentCount)->toBe(2);
});

it('charges GSM extension-table characters as two, and does NOT treat `$` as unicode', function (): void {
    $calculator = new SmsMessageSegmentCalculator;

    // { } [ ] ~ ^ \ | € are escape + character in GSM-7.
    $measurement = $calculator->measure('cost is $5 {ok}');
    expect($measurement->requiresUnicode)->toBeFalse()
        // 15 visible characters, of which { and } cost two each.
        ->and($measurement->characterCount)->toBe(17);

    expect($calculator->isGsmEncodable('Thanks! Visit us again @ 10:00 (KES 500)'))->toBeTrue()
        ->and($calculator->isGsmEncodable('Asante ☺'))->toBeFalse();
});

it('measures an empty body as zero segments rather than one', function (): void {
    $measurement = (new SmsMessageSegmentCalculator)->measure('');

    expect($measurement->characterCount)->toBe(0)->and($measurement->segmentCount)->toBe(0);
});

/*
 |--------------------------------------------------------------------------
 | Cost arithmetic — integer minor units only (ADR-005)
 |--------------------------------------------------------------------------
 */

it('multiplies recipients by segments by the unit price, in integer minor units', function (): void {
    config()->set('sms.pricing.unit_cost_minor', 100);
    config()->set('sms.pricing.currency', 'KES');

    $calculator = new SmsCostCalculator;

    expect($calculator->quantity(3, 2))->toBe(6)
        ->and($calculator->totalMinor(3, 2))->toBe(600)
        ->and($calculator->total(3, 2)->currency->value)->toBe('KES')
        ->and($calculator->totalMinor(0, 2))->toBe(0)
        ->and($calculator->totalMinor(5, 0))->toBe(0);

    // The Money value object carries integers only — never a float.
    expect($calculator->total(3, 2)->minorUnits)->toBeInt();
});

it('honours a re-priced tariff without a code change', function (): void {
    config()->set('sms.pricing.unit_cost_minor', 250);

    expect((new SmsCostCalculator)->totalMinor(4, 1))->toBe(1000);
});

it('fails closed on a negative tariff or a negative count', function (): void {
    config()->set('sms.pricing.unit_cost_minor', -1);
    expect(fn () => (new SmsCostCalculator)->unitCostMinor())->toThrow(RuntimeException::class);

    config()->set('sms.pricing.unit_cost_minor', 100);
    expect(fn () => (new SmsCostCalculator)->quantity(-1, 1))->toThrow(RuntimeException::class);
});

/*
 |--------------------------------------------------------------------------
 | Masking — four digits, never more
 |--------------------------------------------------------------------------
 */

it('never reveals more than the last four digits, whatever it is given', function (string $input): void {
    $masked = PhoneNumberDisplayMasker::mask($input);

    expect(preg_replace('/\D/', '', $masked))->toHaveLength(4)
        ->and($masked)->toStartWith('••• ••• ');
})->with([
    '+254712345678',
    '0712345678',
    '254712345678',
    '+1 (415) 555-0142',
]);

it('masks consistently from a stored last-four', function (): void {
    expect(PhoneNumberDisplayMasker::maskFromLastFour('5678'))->toBe('••• ••• 5678')
        // A short or malformed stored value is padded, never widened.
        ->and(PhoneNumberDisplayMasker::maskFromLastFour('78'))->toBe('••• ••• 0078');
});

/*
 |--------------------------------------------------------------------------
 | Provider redaction — three layers
 |--------------------------------------------------------------------------
 */

it('strips labelled credentials, senders, bodies, emails and numbers from a provider response', function (): void {
    config()->set('sms.delivery.response_body_max_chars', 512);

    $redacted = (string) (new SmsProviderPayloadRedactor)->redact(
        '{"to":"+254712345678","from":"SERVANA","text":"Hi Amina","api_key":"PROVIDERKEYFIXTUREabcdef","email":"a@b.com","error":"failed for 0712345678"}'
    );

    expect($redacted)
        ->not->toContain('712345678')
        ->not->toContain('PROVIDERKEYFIXTUREabcdef')
        ->not->toContain('Hi Amina')
        ->not->toContain('a@b.com')
        // the shape stays useful for diagnosis
        ->toContain('error');
});

it('removes ANY run of seven or more digits, in any punctuation', function (string $input): void {
    $redacted = (string) (new SmsProviderPayloadRedactor)->redact("delivery failed for {$input}");

    expect(preg_match('/\d{7,}/', $redacted))->toBe(0)
        ->and($redacted)->not->toContain('1234567');
})->with([
    '+254 712 345 678',
    '254-712-345-678',
    '(0712) 345678',
    '00441234567890',
    '1234567',
]);

it('leaves a short number alone so diagnostics stay readable', function (): void {
    $redacted = (string) (new SmsProviderPayloadRedactor)->redact('http 503 after 2 retries');

    expect($redacted)->toContain('503')->toContain('2 retries');
});

it('bounds the redacted message to the column width, redacting BEFORE truncating', function (): void {
    config()->set('sms.delivery.response_body_max_chars', 64);

    // The secret sits well past the cut: truncation alone would not have removed it.
    $long = str_repeat('x', 400).' api_key=PROVIDERKEYFIXTUREsecret to=+254712345678';
    $redacted = (string) (new SmsProviderPayloadRedactor)->redact($long);

    expect(strlen($redacted))->toBeLessThanOrEqual(64)
        ->and($redacted)->not->toContain('PROVIDERKEYFIXTUREsecret')
        ->not->toContain('712345678');
});

it('returns null for an empty or whitespace-only provider message', function (): void {
    $redactor = new SmsProviderPayloadRedactor;

    expect($redactor->redact(null))->toBeNull()
        ->and($redactor->redact(''))->toBeNull()
        ->and($redactor->redact('   '))->toBeNull();
});

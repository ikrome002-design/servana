<?php

declare(strict_types=1);

use App\Support\Logging\RedactionProcessor;
use App\Support\Redaction\Redactor;
use Monolog\Level;
use Monolog\LogRecord;

it('redacts sensitive keys anywhere in the structure', function (): void {
    $redactor = new Redactor;

    $out = $redactor->redactArray([
        'token' => 'abc',
        'magic_link' => 'https://servana.test/verify?token=xyz',
        'password' => 'p@ss',
        'authorization' => 'Bearer zzz',
        'api_key' => 'k-123',
        'secret' => 's-123',
        'payment_reference' => 'MPESA-QWE123',
        'nested' => ['access_token' => 'deep', 'client_secret' => 'deeper'],
        'safe' => 'keep-me',
    ]);

    expect($out['token'])->toBe('[redacted]')
        ->and($out['magic_link'])->toBe('[redacted]')
        ->and($out['password'])->toBe('[redacted]')
        ->and($out['authorization'])->toBe('[redacted]')
        ->and($out['api_key'])->toBe('[redacted]')
        ->and($out['secret'])->toBe('[redacted]')
        ->and($out['payment_reference'])->toBe('[redacted]')
        ->and($out['nested']['access_token'])->toBe('[redacted]')
        ->and($out['nested']['client_secret'])->toBe('[redacted]')
        ->and($out['safe'])->toBe('keep-me');
});

it('masks emails and phone numbers in free-form strings', function (): void {
    $redactor = new Redactor;

    expect($redactor->redactString('reach jane.doe@example.com or +254712345678 today'))
        ->not->toContain('jane.doe@example.com')
        ->not->toContain('254712345678')
        ->toContain('[redacted]');

    $masked = $redactor->redactArray(['note' => 'call 0712 345 678 / mail a@b.co']);

    expect($masked['note'])
        ->not->toContain('0712 345 678')
        ->not->toContain('a@b.co');
});

it('never lets a Magic Link token reach a log record (CLAUDE.md §6.9)', function (): void {
    $processor = new RedactionProcessor(new Redactor);

    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Info,
        message: 'magic link issued to user@example.com',
        context: [
            'magic_link' => 'https://servana.test/verify?token=supersecrettoken',
            'token' => 'supersecrettoken',
        ],
        extra: ['authorization' => 'Bearer leaked'],
    );

    $out = $processor($record);

    expect($out->context['magic_link'])->toBe('[redacted]')
        ->and($out->context['token'])->toBe('[redacted]')
        ->and($out->extra['authorization'])->toBe('[redacted]')
        ->and($out->message)->not->toContain('user@example.com');

    // The literal token must not survive anywhere in the serialized record.
    expect(json_encode($out->toArray()))->not->toContain('supersecrettoken');
});

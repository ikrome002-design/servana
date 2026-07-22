<?php

declare(strict_types=1);

use App\Domain\Integrations\ReferEarn\Actions\EnqueueProductEvent;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Merchants\Models\Merchant;

uses()->group('referearn', 'phase21ra', 'phase21ra-outbox');

/*
 | The outbox transaction guard (Plan §58A.2), proven WITHOUT RefreshDatabase on purpose.
 |
 | RefreshDatabase wraps every test in a transaction, so inside it `DB::transactionLevel()` is
 | never 0 and the guard is unreachable — a test there would silently assert nothing. This file
 | therefore uses no database trait at all, which is safe because the guard throws BEFORE any query
 | runs: an unsaved model never reaches the database.
 |
 | What is being protected: an event enqueued outside its source fact's transaction could survive a
 | rolled-back fact, and Servana would then have told a partner about something that never happened.
 */

it('refuses to enqueue outside a database transaction', function (): void {
    $merchant = new Merchant;
    $merchant->id = 1;
    $merchant->ulid = '01JQZX0000000000000000000A';

    expect(fn () => app(EnqueueProductEvent::class)->handle(ReOutboundEventType::MerchantRegistrationStarted, $merchant))
        ->toThrow(RuntimeException::class, 'outbox pattern');
});

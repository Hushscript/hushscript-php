<?php

declare(strict_types=1);

namespace Hushscript\Resource;

use Hushscript\Card;
use Hushscript\Cards;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\PaymentMethodRequiredError;
use Hushscript\Exception\PreflightError;
use Hushscript\Hold;
use Hushscript\Http\BadBody;
use Hushscript\Http\Body;
use Hushscript\Http\Exchange;
use Hushscript\LedgerEntry;
use Hushscript\Pack;
use Hushscript\Page;
use Hushscript\Purchase;
use Hushscript\Transaction;
use Hushscript\Transcribe\Options;
use Hushscript\Transcribe\Shape;

/** Packs, saved cards, purchases and the billing history (spec 6.5). */
final class Billing
{
    public function __construct(private readonly Exchange $exchange)
    {
    }

    /**
     * The minute packs the account can buy, priced in its own currency. Needs the billing scope.
     *
     * @return list<Pack>
     *
     * @throws HushscriptException
     */
    public function packs(): array
    {
        return $this->exchange->fetch('GET', '/v1/billing/packs', [], null, static fn (#[\SensitiveParameter] Body $body): array => array_map(Pack::fromApi(...), $body->list('packs')));
    }

    /**
     * The saved payment methods and the default one.
     *
     * @throws HushscriptException
     */
    public function cards(): Cards
    {
        return $this->exchange->fetch('GET', '/v1/billing/cards', [], null, static fn (#[\SensitiveParameter] Body $body): Cards => Cards::fromApi($body));
    }

    /**
     * Makes a saved card the default one.
     *
     * @throws HushscriptException
     */
    public function setDefaultCard(string $cardId): void
    {
        Shape::segment($cardId, 'card_id');
        $this->exchange->request('POST', '/v1/billing/cards/default', [], ['payment_method_id' => $cardId]);
    }

    /**
     * The hosted page where a card is saved: open it in a browser. The request carries a key, so its one retry
     * after a 502 lands on the same page.
     *
     * @throws HushscriptException
     */
    public function addCardUrl(): string
    {
        return $this->exchange->fetch('POST', '/v1/billing/setup-session', [], ['idempotency_key' => Options::uuid4()], static function (#[\SensitiveParameter] Body $body): string {
            $url = $body->string('checkout_url');
            if ($url === '') {
                throw new BadBody('setup session response (checkout_url: expected a URL)');
            }

            return $url;
        });
    }

    /**
     * Buys a pack with the default card, or the only card that can be charged when there is no default, and
     * returns the balance the purchase answer reports. It makes no request after the charge, so nothing can fail
     * once the money has moved. Every error raised after the purchase request was sent carries `idempotencyKey`:
     * pass it back to ask again with no chance of a second charge. `$acknowledgeNoRefund` records that the
     * no-refund notice was shown to the buyer; it is evidence for disputes and never gates the charge.
     *
     * @throws HushscriptException
     */
    public function buy(string $pack, bool $acknowledgeNoRefund = false, #[\SensitiveParameter] ?string $idempotencyKey = null): Purchase
    {
        if ($pack === '') {
            throw new PreflightError('pack is a pack id such as 300min.', 'invalid_option');
        }
        $key = self::purchaseKey($idempotencyKey);
        $card = self::chooseCard($this->cards());
        $body = ['pack' => $pack, 'idempotency_key' => $key, 'quick_payment_method_id' => $card, 'policy_ack' => $acknowledgeNoRefund];

        try {
            return $this->exchange->fetch('POST', '/v1/billing/purchase', [], $body, static fn (#[\SensitiveParameter] Body $answer): Purchase => Purchase::fromApi($answer));
        } catch (HushscriptException $failure) {
            // From the moment the request went out the card may have been charged, so the key rides on every error.
            $failure->idempotencyKey = $key;
            throw $failure;
        }
    }

    /**
     * Every purchase, newest first, fetching pages as it goes.
     *
     * @return \Generator<int, Transaction>
     */
    public function transactions(): \Generator
    {
        return $this->walk('/v1/billing/transactions', 'transactions', Transaction::fromApi(...));
    }

    /**
     * Every movement of the credit balance, newest first: holds, settles, releases, purchases and refunds.
     *
     * @return \Generator<int, LedgerEntry>
     */
    public function ledger(): \Generator
    {
        return $this->walk('/v1/billing/ledger', 'entries', LedgerEntry::fromApi(...));
    }

    /**
     * The seconds reserved by work in flight, newest first.
     *
     * @return \Generator<int, Hold>
     */
    public function holds(): \Generator
    {
        return $this->walk('/v1/billing/holds', 'holds', Hold::fromApi(...));
    }

    /**
     * @template T
     *
     * @param  \Closure(Body): T  $row
     * @return \Generator<int, T>
     */
    private function walk(#[\SensitiveParameter] string $path, string $key, \Closure $row): \Generator
    {
        return Paginate::walk(fn (?string $cursor): Page => $this->exchange->fetch(
            'GET',
            $path,
            $cursor === null ? [] : ['cursor' => $cursor],
            null,
            static fn (#[\SensitiveParameter] Body $body): Page => new Page(array_map($row, $body->list($key)), $body->nullableString('next_cursor')),
        ));
    }

    /** The key a purchase goes out with: the caller's (1 to 128 of `A-Za-z0-9_-`, spec 7.3) or a fresh one. */
    private static function purchaseKey(#[\SensitiveParameter] ?string $key): string
    {
        if ($key === null) {
            return Options::uuid4();
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $key) !== 1) {
            throw new PreflightError('idempotency_key must be 1 to 128 characters from A-Z, a-z, 0-9, _ and -.', 'invalid_option');
        }

        return $key;
    }

    /** The card `buy()` charges: the default, else the only one that can be charged. With none usable it raises before anything is sent. */
    private static function chooseCard(Cards $cards): string
    {
        if ($cards->defaultId !== null && $cards->defaultId !== '') {
            return $cards->defaultId;
        }
        $eligible = array_values(array_map(
            static fn (Card $card): string => $card->id,
            array_filter($cards->cards, static fn (Card $card): bool => $card->fastCheckoutEligible),
        ));
        if (count($eligible) === 1) {
            return $eligible[0];
        }
        if ($eligible === []) {
            throw new PaymentMethodRequiredError('The account has no saved card that can be charged.', 'no_default_card');
        }

        throw new PaymentMethodRequiredError('The account has no default card and several that can be charged.', 'default_card_required');
    }
}

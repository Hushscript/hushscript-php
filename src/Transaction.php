<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

/** One purchase, as the API reports it. */
final readonly class Transaction
{
    use RawBody;

    /** @param ?string $receiptNumber the public receipt reference, when there is one */
    private function __construct(
        public int $id,
        public int $seconds,
        public int $amountCents,
        public int $amountRefundedCents,
        public string $currency,
        public string $status,
        public \DateTimeImmutable $createdAt,
        public ?string $paymentMethodType,
        public ?string $paymentMethodBrand,
        public ?string $paymentMethodLast4,
        public ?string $receiptNumber,
        public ?string $initiatedVia,
        /** @var array<mixed> */
        #[\SensitiveParameter] private array $raw,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $transaction): self
    {
        return new self(
            $transaction->integer('id'),
            $transaction->integer('seconds'),
            $transaction->integer('amount_cents'),
            $transaction->integer('amount_refunded_cents'),
            $transaction->string('currency'),
            $transaction->string('status'),
            $transaction->timestamp('created_at'),
            $transaction->nullableString('payment_method_type'),
            $transaction->nullableString('payment_method_brand'),
            $transaction->nullableString('payment_method_last4'),
            $transaction->nullableString('receipt_number'),
            $transaction->nullableString('initiated_via'),
            $transaction->raw(),
        );
    }
}

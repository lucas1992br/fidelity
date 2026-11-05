<?php

namespace App\Actions\Billing;

use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentCard;
use Illuminate\Validation\ValidationException;

/**
 * Remember the credit card a holder or a company paid with, by its first 6
 * and last 4 digits only, so the next statements reconcile automatically.
 * A card already remembered for the payer is kept (its brand filled in when
 * it was unknown).
 */
class RememberPaymentCard
{
    public function handle(Customer|Company $payer, string $bin, string $last4, ?string $brand = null): PaymentCard
    {
        $card = $payer->paymentCards()->firstOrCreate(
            ['card_bin' => $bin, 'card_last4' => $last4],
            ['brand' => $brand],
        );

        if ($card->brand === null && $brand !== null) {
            $card->update(['brand' => $brand]);
        }

        return $card;
    }

    /**
     * The first 6 and last 4 digits typed with a payment ("498407-9593"), or
     * null when none were typed. Only card payments take them.
     *
     * @return array{string, string}|null
     *
     * @throws ValidationException
     */
    public static function typedDigits(mixed $value, PaymentMethodEnum $method): ?array
    {
        if (blank($value)) {
            return null;
        }

        if ($method !== PaymentMethodEnum::Card) {
            throw ValidationException::withMessages(['card_digits' => 'Os dígitos do cartão só valem para pagamento com cartão.']);
        }

        return PaymentCard::parseDigits(is_scalar($value) ? (string) $value : null)
            ?? throw ValidationException::withMessages(['card_digits' => 'Informe os 6 primeiros e os 4 últimos dígitos do cartão.']);
    }
}

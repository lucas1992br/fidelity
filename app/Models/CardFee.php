<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Card machine fee of one modality: debit, or credit in 1 to 12
 * installments. The same rate applies to every brand and already includes
 * the anticipation, since the clinic receives each sale at once.
 *
 * @property int $id
 * @property bool $is_debit
 * @property int $installments
 * @property numeric-string $rate Percentage of the sale, 0–100.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CardFee extends Model
{
    /**
     * Name of the acquirer that pays the card sales.
     */
    public const string Acquirer = 'Granito';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'is_debit',
        'installments',
        'rate',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_debit' => 'boolean',
            'installments' => 'integer',
            'rate' => 'decimal:2',
        ];
    }

    /**
     * Label of the modality, e.g. "Débito", "Crédito à vista" or "Crédito 3x".
     */
    public function label(): string
    {
        return self::modalityLabel($this->is_debit, $this->installments);
    }

    public static function modalityLabel(bool $isDebit, int $installments): string
    {
        return match (true) {
            $isDebit => 'Débito',
            $installments === 1 => 'Crédito à vista',
            default => "Crédito {$installments}x",
        };
    }

    /**
     * Rate and fee of a card sale of the given amount, rounded to cents
     * (zero when the modality has no rate).
     *
     * @return array{rate: string, amount: string}
     */
    public static function feeFor(string $amount, bool $isDebit, int $installments): array
    {
        $rate = self::rates()[self::key($isDebit, $installments)] ?? '0.00';

        return [
            'rate' => $rate,
            'amount' => bcadd(bcdiv(bcmul($amount, $rate, 4), '100', 6), '0.005', 2),
        ];
    }

    /**
     * Rates by modality, loaded once per request.
     *
     * @return array<string, string>
     */
    protected static function rates(): array
    {
        return once(fn (): array => self::query()
            ->get()
            ->mapWithKeys(fn (self $fee): array => [self::key($fee->is_debit, $fee->installments) => $fee->rate])
            ->all());
    }

    protected static function key(bool $isDebit, int $installments): string
    {
        return $isDebit ? 'debit' : "credit-{$installments}";
    }
}

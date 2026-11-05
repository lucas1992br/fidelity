<?php

namespace App\Models;

use Database\Factories\PaymentCardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Credit card a payer (a holder or a company, never both) has paid with,
 * remembered by its first 6 and last 4 digits only (never the full number),
 * so the acquirer statement can be reconciled automatically.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $company_id
 * @property string $card_bin
 * @property string $card_last4
 * @property string|null $brand
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $masked_number
 */
class PaymentCard extends Model
{
    /** @use HasFactory<PaymentCardFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'company_id',
        'card_bin',
        'card_last4',
        'brand',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * Cards with exactly these first 6 and last 4 digits.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithDigits(Builder $query, string $bin, string $last4): void
    {
        $query->where('card_bin', $bin)->where('card_last4', $last4);
    }

    /**
     * Card as printed in the statement, e.g. "498407******9593".
     *
     * @return Attribute<string, never>
     */
    protected function maskedNumber(): Attribute
    {
        return Attribute::get(fn (): string => self::mask($this->card_bin, $this->card_last4));
    }

    public static function mask(string $bin, string $last4): string
    {
        return "{$bin}******{$last4}";
    }

    /**
     * Split typed card digits ("498407-9593", "498407 9593" or the full mask
     * "4984 07** **** 9593") into the first 6 and last 4, or null when they
     * are not exactly 10 digits or carry anything but digits, spaces, dots,
     * dashes and asterisks.
     *
     * @return array{string, string}|null
     */
    public static function parseDigits(?string $value): ?array
    {
        if (preg_match('/^[\d\s.*\-]+$/', trim((string) $value)) !== 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', (string) $value);

        return strlen($digits) === 10 ? [substr($digits, 0, 6), substr($digits, 6)] : null;
    }
}

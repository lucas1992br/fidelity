<?php

namespace App\Models;

use App\Enums\CardTransactionResult;
use Database\Factories\CardTransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Card sale read from the acquirer statement; unique by the acquirer id, so
 * a file imported twice settles nothing twice.
 *
 * @property int $id
 * @property int $card_import_id
 * @property string $external_id
 * @property Carbon $occurred_at
 * @property string $type
 * @property int $installments
 * @property string|null $brand
 * @property string $card_bin
 * @property string $card_last4
 * @property string|null $authorization_code
 * @property string|null $transaction_code
 * @property numeric-string $amount
 * @property string $status
 * @property CardTransactionResult $result
 * @property string|null $message Why the transaction is pending or ignored.
 * @property int|null $payment_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CardTransaction extends Model
{
    /** @use HasFactory<CardTransactionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'card_import_id',
        'external_id',
        'occurred_at',
        'type',
        'installments',
        'brand',
        'card_bin',
        'card_last4',
        'authorization_code',
        'transaction_code',
        'amount',
        'status',
        'result',
        'message',
        'payment_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'installments' => 'integer',
            'amount' => 'decimal:2',
            'result' => CardTransactionResult::class,
        ];
    }

    /**
     * @return BelongsTo<CardImport, $this>
     */
    public function cardImport(): BelongsTo
    {
        return $this->belongsTo(CardImport::class);
    }

    /**
     * Payment registered from the transaction.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeWithResult(Builder $query, CardTransactionResult $result): void
    {
        $query->where('result', $result->value);
    }

    public function maskedNumber(): string
    {
        return PaymentCard::mask($this->card_bin, $this->card_last4);
    }
}

<?php

namespace App\Models;

use Database\Factories\CardImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One acquirer statement file imported in the card reconciliation.
 *
 * @property int $id
 * @property string $file_name
 * @property int $transactions_count New transactions stored from the file.
 * @property int $duplicates_count Lines skipped because the transaction was already imported.
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CardImport extends Model
{
    /** @use HasFactory<CardImportFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'file_name',
        'transactions_count',
        'duplicates_count',
        'user_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transactions_count' => 'integer',
            'duplicates_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<CardTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CardTransaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

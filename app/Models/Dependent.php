<?php

namespace App\Models;

use Database\Factories\DependentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property int $kinship_id
 * @property string $name
 * @property string|null $cpf
 * @property Carbon $birth_date
 * @property int $sequence
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string|null $identification
 */
class Dependent extends Model
{
    /** @use HasFactory<DependentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'kinship_id',
        'name',
        'cpf',
        'birth_date',
        'sequence',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Only dependents still covered by the plan.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Kinship, $this>
     */
    public function kinship(): BelongsTo
    {
        return $this->belongsTo(Kinship::class);
    }

    /**
     * Card identification, e.g. "000123-02": the holder's current card number
     * (or the last card when none is current) plus the two-digit sequence.
     * Always calculated, never stored, so it follows card replacements.
     *
     * @return Attribute<string|null, never>
     */
    protected function identification(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->resolveIdentification());
    }

    protected function resolveIdentification(): ?string
    {
        $customer = $this->customer;

        $card = $customer->currentCard
            ?? $customer->cards()->latest('assigned_at')->latest('id')->first();

        if ($card === null) {
            return null;
        }

        return $card->number.'-'.str_pad((string) $this->sequence, 2, '0', STR_PAD_LEFT);
    }
}

<?php

namespace App\Models;

use App\Enums\CardStatus as CardStatusEnum;
use App\Support\Format;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Card holder (titular).
 *
 * @property int $id
 * @property int $plan_id
 * @property string $name
 * @property string $cpf
 * @property Carbon $birth_date
 * @property string|null $email
 * @property string $phone
 * @property string|null $phone_secondary
 * @property string $zip_code
 * @property string $street
 * @property string $number
 * @property string|null $complement
 * @property string $district
 * @property string $city
 * @property string $state
 * @property int $due_day
 * @property Carbon $joined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $masked_cpf
 * @property-read string $formatted_cpf
 * @property-read int $overdue_invoices_count Aggregate loaded by DelinquentsQuery.
 * @property-read numeric-string $overdue_amount Aggregate loaded by DelinquentsQuery.
 * @property-read Carbon $oldest_due_date Aggregate loaded by DelinquentsQuery.
 * @property-read int $max_days_overdue Aggregate loaded by DelinquentsQuery.
 * @property-read int|null $card_status_id Current (or last) card status loaded by DelinquentsQuery.
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'plan_id',
        'name',
        'cpf',
        'birth_date',
        'email',
        'phone',
        'phone_secondary',
        'zip_code',
        'street',
        'number',
        'complement',
        'district',
        'city',
        'state',
        'due_day',
        'joined_at',
    ];

    /**
     * Audit changes to the fillable attributes in the "customers" log; the causer
     * is the authenticated user (none when run by the scheduled routines).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('customers')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'joined_at' => 'date',
            'due_day' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    /**
     * @return HasMany<Dependent, $this>
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(Dependent::class)->orderBy('sequence');
    }

    /**
     * Dependents still covered by the plan (active and not soft-deleted).
     *
     * @return HasMany<Dependent, $this>
     */
    public function activeDependents(): HasMany
    {
        return $this->dependents()->where('is_active', true);
    }

    /**
     * Every card ever linked to the customer.
     *
     * @return HasMany<Card, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    /**
     * The card in force (active or suspended); at most one per customer.
     *
     * @return HasOne<Card, $this>
     */
    public function currentCard(): HasOne
    {
        return $this->hasOne(Card::class)->where('is_current', true);
    }

    /**
     * The most recently assigned card, current or not; shows the holder's
     * status when no card is in force.
     *
     * @return HasOne<Card, $this>
     */
    public function lastCard(): HasOne
    {
        return $this->hasOne(Card::class)->ofMany(['assigned_at' => 'max', 'id' => 'max']);
    }

    /**
     * Cards no longer in force (lost, discarded, cancelled), most recent first.
     *
     * @return HasMany<Card, $this>
     */
    public function previousCards(): HasMany
    {
        return $this->cards()->where('is_current', false)->latest('status_changed_at')->latest('id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasManyThrough<Payment, Invoice, $this>
     */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Invoice::class);
    }

    /**
     * @return HasMany<PlanChange, $this>
     */
    public function planChanges(): HasMany
    {
        return $this->hasMany(PlanChange::class);
    }

    /**
     * Notes, most recent first.
     *
     * @return HasMany<CustomerNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class)->latest()->latest('id');
    }

    /**
     * Holders whose name contains the term, ignoring case and accents
     * (served by the `customers_name_trgm` index).
     *
     * @param  Builder<self>  $query
     */
    public function scopeWhereNameMatches(Builder $query, string $term): void
    {
        $query->whereRaw('f_unaccent(name) ILIKE f_unaccent(?)', ['%'.addcslashes(trim($term), '%_\\').'%']);
    }

    /**
     * Holders with the given CPF, typed with or without the mask.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWhereCpf(Builder $query, string $cpf): void
    {
        $query->where('cpf', preg_replace('/\D/', '', $cpf));
    }

    /**
     * Holders that own (or owned) a card with exactly this number.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWhereCardNumber(Builder $query, string $number): void
    {
        $query->whereHas('cards', fn (Builder $cards) => $cards->where('number', trim($number)));
    }

    /**
     * Holders whose status is the given one: the current card's, or the last
     * card's when none is in force (same rule as the customer list).
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithCardStatus(Builder $query, CardStatusEnum $status): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereHas('currentCard', fn (Builder $cards) => $cards->withStatus($status))
            ->orWhere(fn (Builder $query) => $query
                ->doesntHave('currentCard')
                ->whereHas('lastCard', fn (Builder $cards) => $cards->withStatus($status))));
    }

    /**
     * Free-text listing search: name, CPF (11 digits, masked or not) or card number.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->whereNameMatches($term)->orWhere(fn (Builder $query) => $query->whereCardNumber($term));

            if (self::looksLikeCpf($term)) {
                $query->orWhere(fn (Builder $query) => $query->whereCpf($term));
            }
        });
    }

    /**
     * Whether the term is a CPF: 11 digits, optionally with the dots, dash or spaces of the mask.
     */
    public static function looksLikeCpf(string $term): bool
    {
        return preg_match('/^[\d.\-\s]+$/', trim($term)) === 1
            && strlen(preg_replace('/\D/', '', $term)) === 11;
    }

    /**
     * CPF masked for listings, e.g. "***.456.789-**".
     *
     * @return Attribute<string, never>
     */
    protected function maskedCpf(): Attribute
    {
        return Attribute::get(fn (): string => Format::maskCpf($this->cpf));
    }

    /**
     * CPF formatted as "123.456.789-09".
     *
     * @return Attribute<string, never>
     */
    protected function formattedCpf(): Attribute
    {
        return Attribute::get(fn (): string => Format::cpf($this->cpf));
    }
}

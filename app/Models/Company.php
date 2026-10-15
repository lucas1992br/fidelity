<?php

namespace App\Models;

use App\Support\Format;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Company with a corporate card contract: its employees are holders with
 * their own card, dependents and monthly amount, all due on the company's
 * `due_day`, and the company pays one consolidated bill per month.
 *
 * @property int $id
 * @property string $name
 * @property string|null $trade_name
 * @property string $cnpj
 * @property string|null $contact_name
 * @property string|null $email
 * @property string $phone
 * @property int $due_day
 * @property string|null $notes
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $formatted_cnpj
 * @property-read string $display_name
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'trade_name',
        'cnpj',
        'contact_name',
        'email',
        'phone',
        'due_day',
        'notes',
        'is_active',
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
            'due_day' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Employees (holders) of the company.
     *
     * @return HasMany<Customer, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Employee invoices issued while they belonged to the company.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Consolidated payments of the company bills, including refunded ones.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Only companies that may receive new employees.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Free-text listing search: corporate or trade name (ignoring case and
     * accents) or CNPJ digits.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $query) use ($term, $like): void {
            $query->whereRaw('f_unaccent(name) ILIKE f_unaccent(?)', [$like])
                ->orWhereRaw('f_unaccent(trade_name) ILIKE f_unaccent(?)', [$like]);

            $digits = preg_replace('/\D/', '', $term);

            if ($digits !== '' && preg_match('/^[\d.\/\-\s]+$/', $term) === 1) {
                $query->orWhere('cnpj', 'like', "%{$digits}%");
            }
        });
    }

    /**
     * CNPJ formatted as "12.345.678/0001-95".
     *
     * @return Attribute<string, never>
     */
    protected function formattedCnpj(): Attribute
    {
        return Attribute::get(fn (): string => Format::cnpj($this->cnpj));
    }

    /**
     * Trade name when there is one, otherwise the corporate name.
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => $this->trade_name ?: $this->name);
    }
}

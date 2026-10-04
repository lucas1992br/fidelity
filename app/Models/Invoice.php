<?php

namespace App\Models;

use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use Carbon\CarbonInterface;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Monthly fee (mensalidade) of a customer; the amount is frozen on generation.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $plan_id
 * @property Carbon $reference_month
 * @property string $amount
 * @property Carbon $due_date
 * @property int $invoice_status_id
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $days_overdue
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'plan_id',
        'reference_month',
        'amount',
        'due_date',
        'invoice_status_id',
        'paid_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'due_date' => 'date',
            'paid_at' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * Plan in force when the invoice was generated.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    /**
     * @return BelongsTo<InvoiceStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(InvoiceStatus::class, 'invoice_status_id');
    }

    /**
     * Every payment, including refunded ones.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The non-refunded payment; at most one per invoice.
     *
     * @return HasOne<Payment, $this>
     */
    public function activePayment(): HasOne
    {
        return $this->hasOne(Payment::class)->whereNull('refunded_at');
    }

    /**
     * Days past the due date while the invoice is overdue (or open and
     * already due); 0 otherwise.
     *
     * @return Attribute<int, never>
     */
    protected function daysOverdue(): Attribute
    {
        return Attribute::get(fn (): int => $this->resolveDaysOverdue());
    }

    protected function resolveDaysOverdue(): int
    {
        $isLate = $this->hasStatus(InvoiceStatusEnum::Overdue)
            || ($this->hasStatus(InvoiceStatusEnum::Open) && $this->due_date->lt(today()));

        if (! $isLate) {
            return 0;
        }

        return max(0, (int) $this->due_date->startOfDay()->diffInDays(today()));
    }

    public function hasStatus(InvoiceStatusEnum $status): bool
    {
        return $this->invoice_status_id === $status->id();
    }

    /**
     * Invoices that can still be paid (open or overdue).
     *
     * @param  Builder<self>  $query
     */
    public function scopePayable(Builder $query): void
    {
        $query->whereIn('invoice_status_id', [InvoiceStatusEnum::Open->id(), InvoiceStatusEnum::Overdue->id()]);
    }

    /**
     * Payable invoices past their due date, whether already flagged overdue or still open.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->payable()->whereDate('due_date', '<', today());
    }

    /**
     * Invoices of the given reference month ("2026-10" or any date in the month).
     *
     * @param  Builder<self>  $query
     */
    public function scopeForMonth(Builder $query, CarbonInterface|string $month): void
    {
        $query->whereDate('reference_month', self::referenceMonth($month));
    }

    /**
     * First day of the given month, as stored in reference_month.
     */
    public static function referenceMonth(CarbonInterface|string $month): string
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month .= '-01';
        }

        return Carbon::parse($month)->startOfMonth()->toDateString();
    }
}

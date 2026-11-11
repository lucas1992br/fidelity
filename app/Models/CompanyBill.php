<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\CompanyBillFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A company's consolidated bill of one reference month, created with the
 * first employee invoice of that month. It freezes the company's fixed
 * monthly amount; the employee invoices (zero since the fixed amount) only
 * track each card. The bill's status follows its employee invoices.
 *
 * @property int $id
 * @property int $company_id
 * @property Carbon $reference_month
 * @property numeric-string $amount Fixed amount of the month; zero on months billed before the fixed amount.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CompanyBill extends Model
{
    /** @use HasFactory<CompanyBillFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'reference_month',
        'amount',
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
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * Bills with at least one employee invoice in one of the given statuses.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithInvoiceStatus(Builder $query, InvoiceStatus ...$statuses): void
    {
        $query->whereExists(fn (QueryBuilder $invoices) => $invoices
            ->from('invoices')
            ->whereColumn('invoices.company_id', 'company_bills.company_id')
            ->whereColumn('invoices.reference_month', 'company_bills.reference_month')
            ->whereIn('invoices.invoice_status_id', array_map(fn (InvoiceStatus $status): int => $status->id(), $statuses)));
    }

    /**
     * Fixed amounts of the bills with an employee invoice in one of the given statuses.
     */
    public static function amountWithInvoiceStatus(InvoiceStatus ...$statuses): string
    {
        return (string) self::query()
            ->withInvoiceStatus(...$statuses)
            ->toBase()
            ->selectRaw('coalesce(sum(amount), 0)::numeric(14, 2) as amount')
            ->value('amount');
    }

    /**
     * Amount of a bill made of the given employee invoices: the fixed amount
     * plus the invoices (zero since the fixed amount); nothing without invoices.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    public static function totalOf(?self $bill, Collection $invoices): string
    {
        if ($invoices->isEmpty()) {
            return '0.00';
        }

        return $invoices->reduce(fn (string $sum, Invoice $invoice): string => bcadd($sum, $invoice->amount, 2), $bill->amount ?? '0.00');
    }
}

<?php

namespace App\Models;

use Database\Factories\PlanChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable log of a customer's plan changes; the sign-up has no "from" plan.
 *
 * @property int $id
 * @property int $customer_id
 * @property int|null $from_plan_id
 * @property int $to_plan_id
 * @property string|null $from_price
 * @property string $to_price
 * @property string|null $enrollment_fee Fee charged at a sign-up; null on other changes.
 * @property string|null $commission_percent Seller's commission on the enrollment fee, as set at the sale.
 * @property string|null $reason
 * @property int $user_id
 * @property Carbon|null $created_at
 */
class PlanChange extends Model
{
    /** @use HasFactory<PlanChangeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'from_plan_id',
        'to_plan_id',
        'from_price',
        'to_price',
        'enrollment_fee',
        'commission_percent',
        'reason',
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
            'from_price' => 'decimal:2',
            'to_price' => 'decimal:2',
            'enrollment_fee' => 'decimal:2',
            'commission_percent' => 'decimal:2',
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
     * @return BelongsTo<Plan, $this>
     */
    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id')->withTrashed();
    }

    /**
     * User who made the change.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

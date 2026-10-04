<?php

use App\Enums\CardStatus as CardStatusEnum;
use App\Enums\EntryStatus as EntryStatusEnum;
use App\Enums\EntryType as EntryTypeEnum;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Models\Card;
use App\Models\CardBatch;
use App\Models\CardStatusLog;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Dependent;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanChange;
use Illuminate\Support\Carbon;

/**
 * Whether the 11 digits form a CPF with valid check digits.
 */
function isValidCpf(string $cpf): bool
{
    if (! preg_match('/^\d{11}$/', $cpf) || count(array_unique(str_split($cpf))) === 1) {
        return false;
    }

    foreach ([9, 10] as $length) {
        $sum = 0;

        foreach (str_split(substr($cpf, 0, $length)) as $index => $digit) {
            $sum += (int) $digit * ($length + 1 - $index);
        }

        $remainder = $sum % 11;

        if ((int) $cpf[$length] !== ($remainder < 2 ? 0 : 11 - $remainder)) {
            return false;
        }
    }

    return true;
}

test('every domain factory creates a valid row with its defaults', function (string $model) {
    expect($model::factory()->create()->exists)->toBeTrue();
})->with([
    Plan::class, Customer::class, Dependent::class, PlanChange::class, CustomerNote::class, CardBatch::class,
    Card::class, CardStatusLog::class, Invoice::class, Payment::class, FinancialCategory::class, FinancialEntry::class,
]);

test('customer factory generates valid cpfs', function () {
    $cpfs = Customer::factory()->count(20)->create()->pluck('cpf');

    expect($cpfs->every(fn (string $cpf): bool => isValidCpf($cpf)))->toBeTrue()
        ->and($cpfs->unique())->toHaveCount(20);
});

test('card states keep is_current and the customer coherent with the status', function (string $state, CardStatusEnum $status, bool $isCurrent, bool $hasCustomer) {
    $card = Card::factory()->{$state}()->create();

    expect($card->hasStatus($status))->toBeTrue()
        ->and($card->is_current)->toBe($isCurrent)
        ->and($card->customer_id !== null)->toBe($hasCustomer);
})->with([
    ['inStock', CardStatusEnum::InStock, false, false],
    ['active', CardStatusEnum::Active, true, true],
    ['suspended', CardStatusEnum::Suspended, true, true],
    ['cancelled', CardStatusEnum::Cancelled, false, true],
    ['lost', CardStatusEnum::Lost, false, false],
    ['discarded', CardStatusEnum::Discarded, false, false],
]);

test('card states keep the customer given with for', function () {
    $customer = Customer::factory()->create();

    expect(Card::factory()->for($customer)->suspended()->create()->customer_id)->toBe($customer->id);
});

test('customer with card gets a current card in the given status', function () {
    $active = Customer::factory()->withCard()->create();
    $suspended = Customer::factory()->withCard(CardStatusEnum::Suspended)->create();

    expect($active->currentCard->hasStatus(CardStatusEnum::Active))->toBeTrue()
        ->and($suspended->currentCard->hasStatus(CardStatusEnum::Suspended))->toBeTrue();
});

test('plan states set availability and the dependents limit', function () {
    $plan = Plan::factory()->inactive()->withDependents(4)->create();

    expect($plan->is_active)->toBeFalse()
        ->and($plan->max_dependents)->toBe(4);
});

test('dependents of the same customer get increasing sequences', function () {
    $customer = Customer::factory()->create();

    $sequences = Dependent::factory()->count(3)->for($customer)->create()->pluck('sequence')->all();

    expect($sequences)->toBe([1, 2, 3]);
});

test('invoice states set status, dates and amount from the plan', function () {
    Carbon::setTestNow('2026-10-04');

    $plan = Plan::factory()->create(['price' => '89.90']);
    $customer = Customer::factory()->for($plan)->create();

    $open = Invoice::factory()->for($customer)->open()->create();
    $overdue = Invoice::factory()->for($customer)->overdue(32)->create();
    $paid = Invoice::factory()->for($customer)->forMonth('2026-07')->paid()->create();
    $cancelled = Invoice::factory()->for($customer)->forMonth('2026-06')->cancelled()->create();

    expect($open->hasStatus(InvoiceStatusEnum::Open))->toBeTrue()
        ->and($open->amount)->toBe('89.90')
        ->and($open->plan_id)->toBe($plan->id)
        ->and($overdue->hasStatus(InvoiceStatusEnum::Overdue))->toBeTrue()
        ->and($overdue->due_date->toDateString())->toBe('2026-09-02')
        ->and($paid->hasStatus(InvoiceStatusEnum::Paid))->toBeTrue()
        ->and($paid->reference_month->toDateString())->toBe('2026-07-01')
        ->and($paid->paid_at->toDateString())->toBe('2026-07-10')
        ->and($cancelled->hasStatus(InvoiceStatusEnum::Cancelled))->toBeTrue();
});

test('payments take the receipt number from the sequence and can be refunded', function () {
    $payments = Payment::factory()->count(2)->create()->each->refresh();
    $refunded = Payment::factory()->refunded()->create();

    expect($payments[1]->receipt_number)->toBe($payments[0]->receipt_number + 1)
        ->and($payments[0]->isRefunded())->toBeFalse()
        ->and($refunded->isRefunded())->toBeTrue()
        ->and($refunded->refunded_by_user_id)->not->toBeNull();
});

test('financial entry states set type, status and automatic origin', function () {
    $income = FinancialEntry::factory()->income()->paid()->create();
    $expense = FinancialEntry::factory()->expense()->pending()->create();
    $automatic = FinancialEntry::factory()->automatic()->create();

    expect($income->entry_type_id)->toBe(EntryTypeEnum::Income->id())
        ->and($income->entry_status_id)->toBe(EntryStatusEnum::Paid->id())
        ->and($income->paid_at)->not->toBeNull()
        ->and($expense->entry_type_id)->toBe(EntryTypeEnum::Expense->id())
        ->and($expense->entry_status_id)->toBe(EntryStatusEnum::Pending->id())
        ->and($expense->isAutomatic())->toBeFalse()
        ->and($automatic->isAutomatic())->toBeTrue()
        ->and($automatic->financial_category_id)->toBe(FinancialCategory::monthlyFees()->id);
});

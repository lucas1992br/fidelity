<?php

use App\Enums\EntryType as EntryTypeEnum;
use App\Models\Card;
use App\Models\Customer;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Run the statement in a savepoint, so the PostgreSQL error does not abort
 * the test transaction and the test can keep querying afterwards.
 */
function inSavepoint(Closure $statement): Closure
{
    return fn () => DB::transaction($statement);
}

test('a customer cannot have a second current card', function () {
    $customer = Customer::factory()->withCard()->create();

    expect(inSavepoint(fn () => Card::factory()->for($customer)->suspended()->create()))
        ->toThrow(UniqueConstraintViolationException::class, 'cards_one_current_per_customer');

    expect($customer->cards()->current()->count())->toBe(1);
});

test('a returning customer gets a new active card next to the cancelled one', function () {
    $customer = Customer::factory()->create();
    Card::factory()->for($customer)->cancelled()->create();

    $newCard = Card::factory()->for($customer)->active()->create();

    expect($customer->currentCard->is($newCard))->toBeTrue()
        ->and($customer->cards)->toHaveCount(2);
});

test('an invoice accepts a new payment only after the active one is refunded', function () {
    $invoice = Invoice::factory()->create();
    $first = Payment::factory()->for($invoice)->create();

    expect(inSavepoint(fn () => Payment::factory()->for($invoice)->create()))
        ->toThrow(UniqueConstraintViolationException::class, 'payments_one_active_per_invoice');

    $first->update(['refunded_at' => now(), 'refund_reason' => 'Pagamento em duplicidade']);
    $second = Payment::factory()->for($invoice)->create();

    expect($invoice->activePayment->is($second))->toBeTrue()
        ->and($invoice->payments)->toHaveCount(2);
});

test('payments get consecutive receipt numbers from the sequence', function () {
    $first = Payment::factory()->create()->refresh();
    $second = Payment::factory()->create()->refresh();

    expect($first->receipt_number)->toBeInt()
        ->and($second->receipt_number)->toBe($first->receipt_number + 1);
});

test('a customer cannot get two invoices for the same month', function () {
    $invoice = Invoice::factory()->forMonth('2026-10')->create();

    expect(inSavepoint(fn () => Invoice::factory()->for($invoice->customer)->forMonth('2026-10')->create()))
        ->toThrow(UniqueConstraintViolationException::class, 'invoices_customer_id_reference_month_unique');

    expect(Invoice::forMonth('2026-10')->count())->toBe(1);
});

test('check constraints reject out of range values', function (Closure $create, string $constraint) {
    expect($create)->toThrow(QueryException::class, $constraint);
})->with([
    'due day 29' => [fn () => Customer::factory()->create(['due_day' => 29]), 'customers_due_day_range'],
    'due day 0' => [fn () => Customer::factory()->create(['due_day' => 0]), 'customers_due_day_range'],
    'negative price' => [fn () => Plan::factory()->create(['price' => -0.01]), 'plans_price_non_negative'],
    'negative dependents limit' => [fn () => Plan::factory()->create(['max_dependents' => -1]), 'plans_max_dependents_non_negative'],
    'zero payment' => [fn () => Payment::factory()->create(['amount' => 0]), 'payments_amount_positive'],
]);

test('an expense cannot be linked to an income category', function () {
    $incomeCategory = FinancialCategory::factory()->income()->create();

    expect(fn () => FinancialEntry::factory()->for($incomeCategory, 'category')->create(['entry_type_id' => EntryTypeEnum::Expense->id()]))
        ->toThrow(QueryException::class, 'financial_entries_category_entry_type_foreign');
});

test('a category with entries cannot change its type', function () {
    $category = FinancialCategory::factory()->expense()->create();
    FinancialEntry::factory()->for($category, 'category')->create();

    expect(fn () => $category->update(['entry_type_id' => EntryTypeEnum::Income->id()]))
        ->toThrow(QueryException::class, 'financial_entries_category_entry_type_foreign');
});

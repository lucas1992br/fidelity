<?php

use App\Enums\CardStatus as CardStatusEnum;
use App\Enums\EntryStatus as EntryStatusEnum;
use App\Enums\EntryType as EntryTypeEnum;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Enums\Kinship as KinshipEnum;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\Card;
use App\Models\CardBatch;
use App\Models\CardStatus;
use App\Models\CardStatusLog;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Dependent;
use App\Models\EntryStatus;
use App\Models\EntryType;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\Kinship;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\User;
use Illuminate\Support\Carbon;

test('customer current card returns only the card in force', function () {
    $customer = Customer::factory()->create();
    Card::factory()->for($customer)->lost()->create();
    $current = Card::factory()->for($customer)->active()->create();

    expect($customer->currentCard->is($current))->toBeTrue()
        ->and(Customer::factory()->create()->currentCard)->toBeNull();
});

test('customer previous cards list the lost, discarded and cancelled ones', function () {
    $customer = Customer::factory()->create();
    Card::factory()->for($customer)->suspended()->create();
    $previous = collect([
        Card::factory()->for($customer)->lost()->create(),
        Card::factory()->for($customer)->discarded()->create(),
        Card::factory()->for($customer)->cancelled()->create(),
    ]);

    expect($customer->previousCards->pluck('id')->sort()->values()->all())->toBe($previous->pluck('id')->sort()->values()->all())
        ->and($customer->cards)->toHaveCount(4);
});

test('invoice active payment ignores the refunded payment', function () {
    $invoice = Invoice::factory()->paid()->create();
    $refunded = Payment::factory()->for($invoice)->refunded()->create();
    $active = Payment::factory()->for($invoice)->create();

    expect($invoice->activePayment->is($active))->toBeTrue()
        ->and($invoice->payments->pluck('id')->sort()->values()->all())->toBe([$refunded->id, $active->id])
        ->and($refunded->invoice->is($invoice))->toBeTrue();
});

test('customer active dependents ignore inactive and soft deleted ones', function () {
    $customer = Customer::factory()->create();
    $active = Dependent::factory()->for($customer)->create();
    Dependent::factory()->for($customer)->inactive()->create();
    Dependent::factory()->for($customer)->create()->delete();

    expect($customer->activeDependents->pluck('id')->all())->toBe([$active->id])
        ->and($customer->dependents)->toHaveCount(2)
        ->and(Dependent::active()->pluck('id')->all())->toBe([$active->id]);
});

test('monthly fees returns the system income category', function () {
    FinancialCategory::factory()->income()->create(['name' => 'Mensalidades']);

    $monthlyFees = FinancialCategory::monthlyFees();

    expect($monthlyFees->name)->toBe('Mensalidades')
        ->and($monthlyFees->is_system)->toBeTrue()
        ->and($monthlyFees->entryType->slug)->toBe(EntryTypeEnum::Income);
});

test('customer relationships reach plan, invoices, payments, plan changes and notes', function () {
    $plan = Plan::factory()->create();
    $customer = Customer::factory()->for($plan)->create();
    $invoice = Invoice::factory()->for($customer)->create();
    $payment = Payment::factory()->for($invoice)->create();
    $planChange = PlanChange::factory()->for($customer)->create();
    $olderNote = CustomerNote::factory()->for($customer)->create(['created_at' => now()->subDay()]);
    $newerNote = CustomerNote::factory()->for($customer)->create();

    expect($customer->plan->is($plan))->toBeTrue()
        ->and($plan->customers->pluck('id')->all())->toBe([$customer->id])
        ->and($plan->invoices->pluck('id')->all())->toBe([$invoice->id])
        ->and($customer->invoices->pluck('id')->all())->toBe([$invoice->id])
        ->and($customer->payments->pluck('id')->all())->toBe([$payment->id])
        ->and($customer->planChanges->pluck('id')->all())->toBe([$planChange->id])
        ->and($customer->notes->pluck('id')->all())->toBe([$newerNote->id, $olderNote->id]);
});

test('soft deleted plans stay reachable from their customers', function () {
    $customer = Customer::factory()->create();
    $customer->plan->delete();

    expect($customer->refresh()->plan)->not->toBeNull()
        ->and(Plan::count())->toBe(0);
});

test('dependent, plan change and note belong to their customer and author', function () {
    $user = User::factory()->create();
    $from = Plan::factory()->create();
    $customer = Customer::factory()->create();
    $dependent = Dependent::factory()->for($customer)->create(['kinship_id' => KinshipEnum::Spouse->id()]);
    $planChange = PlanChange::factory()->for($customer)->for($user)->from($from)->create();
    $note = CustomerNote::factory()->for($customer)->for($user)->create();

    expect($dependent->customer->is($customer))->toBeTrue()
        ->and($dependent->kinship->slug)->toBe(KinshipEnum::Spouse)
        ->and(Kinship::find(KinshipEnum::Spouse->id())->dependents->pluck('id')->all())->toBe([$dependent->id])
        ->and($planChange->customer->is($customer))->toBeTrue()
        ->and($planChange->fromPlan->is($from))->toBeTrue()
        ->and($planChange->toPlan->is($customer->plan))->toBeTrue()
        ->and($planChange->user->is($user))->toBeTrue()
        ->and($planChange->updated_at)->toBeNull()
        ->and($note->customer->is($customer))->toBeTrue()
        ->and($note->user->is($user))->toBeTrue()
        ->and($user->planChanges->pluck('id')->all())->toBe([$planChange->id])
        ->and($user->customerNotes->pluck('id')->all())->toBe([$note->id]);
});

test('card relationships reach batch, customer, status and history', function () {
    $user = User::factory()->create();
    $batch = CardBatch::factory()->for($user)->create();
    $customer = Customer::factory()->create();
    $card = Card::factory()->for($batch, 'batch')->for($customer)->active()->create();
    $entry = CardStatusLog::factory()->for($card)->transition(null, CardStatusEnum::InStock)->create(['created_at' => now()->subDay()]);
    $activation = CardStatusLog::factory()->for($card)->for($user)->transition(CardStatusEnum::InStock, CardStatusEnum::Active)->create();
    $suspension = CardStatusLog::factory()->for($card)->bySystem()->transition(CardStatusEnum::Active, CardStatusEnum::Suspended)->create();

    expect($card->batch->is($batch))->toBeTrue()
        ->and($batch->cards->pluck('id')->all())->toBe([$card->id])
        ->and($batch->user->is($user))->toBeTrue()
        ->and($user->cardBatches->pluck('id')->all())->toBe([$batch->id])
        ->and($card->customer->is($customer))->toBeTrue()
        ->and($card->status->slug)->toBe(CardStatusEnum::Active)
        ->and(CardStatus::find(CardStatusEnum::Active->id())->cards->pluck('id')->all())->toBe([$card->id])
        ->and($card->statusLogs->pluck('id')->all())->toBe([$entry->id, $activation->id, $suspension->id])
        ->and($activation->card->is($card))->toBeTrue()
        ->and($activation->fromStatus->slug)->toBe(CardStatusEnum::InStock)
        ->and($activation->toStatus->slug)->toBe(CardStatusEnum::Active)
        ->and($activation->user->is($user))->toBeTrue()
        ->and($suspension->user)->toBeNull()
        ->and($entry->fromStatus)->toBeNull()
        ->and($user->cardStatusLogs->pluck('id')->all())->toBe([$activation->id]);
});

test('card scopes filter stock, current and given statuses', function () {
    $inStock = Card::factory()->inStock()->create();
    $active = Card::factory()->active()->create();
    $suspended = Card::factory()->suspended()->create();
    $lost = Card::factory()->lost()->create();

    expect(Card::inStock()->pluck('id')->all())->toBe([$inStock->id])
        ->and(Card::current()->orderBy('id')->pluck('id')->all())->toBe([$active->id, $suspended->id])
        ->and(Card::withStatus(CardStatusEnum::Suspended, CardStatusEnum::Lost)->orderBy('id')->pluck('id')->all())->toBe([$suspended->id, $lost->id])
        ->and($lost->hasStatus(CardStatusEnum::Lost))->toBeTrue()
        ->and($lost->hasStatus(CardStatusEnum::Active))->toBeFalse();
});

test('invoice relationships and scopes', function () {
    Carbon::setTestNow('2026-10-04');

    $customer = Customer::factory()->create();
    $open = Invoice::factory()->for($customer)->open()->create();
    $openAndLate = Invoice::factory()->for($customer)->forMonth('2026-08')->create();
    $overdue = Invoice::factory()->for($customer)->overdue(32)->create();
    $paid = Invoice::factory()->for($customer)->forMonth('2026-07')->paid()->create();

    expect($open->customer->is($customer))->toBeTrue()
        ->and($open->plan->is($customer->plan))->toBeTrue()
        ->and($open->status->slug)->toBe(InvoiceStatusEnum::Open)
        ->and(InvoiceStatus::find(InvoiceStatusEnum::Paid->id())->invoices->pluck('id')->all())->toBe([$paid->id])
        ->and(Invoice::payable()->orderBy('id')->pluck('id')->all())->toBe([$open->id, $openAndLate->id, $overdue->id])
        ->and(Invoice::overdue()->orderBy('id')->pluck('id')->all())->toBe([$openAndLate->id, $overdue->id])
        ->and(Invoice::forMonth('2026-07')->pluck('id')->all())->toBe([$paid->id])
        ->and(Invoice::forMonth(Carbon::parse('2026-08-15'))->pluck('id')->all())->toBe([$openAndLate->id]);
});

test('payment relationships reach method, users and its automatic entry', function () {
    $registeredBy = User::factory()->create();
    $refundedBy = User::factory()->create();
    $payment = Payment::factory()->for($registeredBy)->method(PaymentMethodEnum::Cash)->create();
    $refunded = Payment::factory()->refunded()->create(['refunded_by_user_id' => $refundedBy->id]);
    $entry = FinancialEntry::factory()->automatic($payment)->create();

    expect($payment->refresh()->receipt_number)->toBeInt()
        ->and($payment->method->slug)->toBe(PaymentMethodEnum::Cash)
        ->and(PaymentMethod::find(PaymentMethodEnum::Cash->id())->payments->pluck('id')->all())->toBe([$payment->id])
        ->and(PaymentMethod::find(PaymentMethodEnum::Cash->id())->financialEntries->pluck('id')->all())->toBe([$entry->id])
        ->and($payment->user->is($registeredBy))->toBeTrue()
        ->and($registeredBy->payments->pluck('id')->all())->toBe([$payment->id])
        ->and($refunded->refundedBy->is($refundedBy))->toBeTrue()
        ->and($refundedBy->refundedPayments->pluck('id')->all())->toBe([$refunded->id])
        ->and($payment->financialEntries->pluck('id')->all())->toBe([$entry->id])
        ->and($entry->payment->is($payment))->toBeTrue()
        ->and($entry->user->is($registeredBy))->toBeTrue()
        ->and($registeredBy->financialEntries->pluck('id')->all())->toBe([$entry->id]);
});

test('financial category and entry relationships and scopes', function () {
    $category = FinancialCategory::factory()->expense()->create();
    $inactive = FinancialCategory::factory()->expense()->inactive()->create();
    $entry = FinancialEntry::factory()->for($category, 'category')->paid()->create();

    expect($entry->category->is($category))->toBeTrue()
        ->and($entry->type->slug)->toBe(EntryTypeEnum::Expense)
        ->and($entry->status->slug)->toBe(EntryStatusEnum::Paid)
        ->and($entry->method->slug)->toBe(PaymentMethodEnum::Pix)
        ->and($entry->payment)->toBeNull()
        ->and($category->entries->pluck('id')->all())->toBe([$entry->id])
        ->and($category->entryType->slug)->toBe(EntryTypeEnum::Expense)
        ->and(EntryType::find(EntryTypeEnum::Expense->id())->entries->pluck('id')->all())->toBe([$entry->id])
        ->and(EntryType::find(EntryTypeEnum::Expense->id())->categories->pluck('id'))->toContain($category->id, $inactive->id)
        ->and(EntryStatus::find(EntryStatusEnum::Paid->id())->entries->pluck('id')->all())->toBe([$entry->id])
        ->and(FinancialCategory::active()->pluck('id'))->toContain($category->id)->not->toContain($inactive->id)
        ->and(FinancialCategory::ofType(EntryTypeEnum::Income)->pluck('name')->all())->toBe(['Mensalidades', 'Outras receitas']);
});

test('soft deleted financial entries leave the default queries', function () {
    $entry = FinancialEntry::factory()->create();

    $entry->delete();

    expect(FinancialEntry::count())->toBe(0)
        ->and(FinancialEntry::withTrashed()->find($entry->id))->not->toBeNull();
});

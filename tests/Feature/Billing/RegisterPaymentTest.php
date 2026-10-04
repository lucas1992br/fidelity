<?php

use App\Actions\Billing\PaymentWillReactivateCard;
use App\Actions\Billing\RegisterPayment;
use App\Enums\CardStatus;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Card;
use App\Models\Customer;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
    settings(['suspend_after_days' => 30]);
    $this->user = User::factory()->create();
});

/**
 * Pay the invoice through the action with the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 */
function registerPayment(Invoice $invoice, array $overrides = []): Payment
{
    return app(RegisterPayment::class)->handle($invoice, [
        'paid_at' => '2026-10-15',
        'amount' => $invoice->amount,
        'payment_method' => PaymentMethod::Pix->value,
        'notes' => null,
        ...$overrides,
    ], test()->user);
}

/**
 * Validation message of the given key raised by the callback.
 */
function paymentError(Closure $callback, string $key): ?string
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors()[$key][0] ?? null;
    }

    return null;
}

test('the payment creates the receipt, pays the invoice and books one income in Mensalidades', function () {
    $customer = Customer::factory()->withCard()->create(['name' => 'Maria Aparecida Souza']);
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => '89.90']);

    $payment = registerPayment($invoice, ['payment_method' => PaymentMethod::Cash->value, 'notes' => 'Pago no balcão']);

    expect($payment->receipt_number)->toBeInt()->toBeGreaterThan(0)
        ->and($payment->paid_at->toDateString())->toBe('2026-10-15')
        ->and($payment->amount)->toBe('89.90')
        ->and($payment->payment_method_id)->toBe(PaymentMethod::Cash->id())
        ->and($payment->notes)->toBe('Pago no balcão')
        ->and($payment->user_id)->toBe($this->user->id);

    $invoice->refresh();
    expect($invoice->hasStatus(InvoiceStatus::Paid))->toBeTrue()
        ->and($invoice->paid_at->toDateString())->toBe('2026-10-15');

    $entry = $payment->financialEntry()->sole();
    expect($entry->entry_type_id)->toBe(EntryType::Income->id())
        ->and($entry->financial_category_id)->toBe(FinancialCategory::monthlyFees()->id)
        ->and($entry->entry_status_id)->toBe(EntryStatus::Paid->id())
        ->and($entry->amount)->toBe('89.90')
        ->and($entry->paid_at->toDateString())->toBe('2026-10-15')
        ->and($entry->due_date->toDateString())->toBe($invoice->due_date->toDateString())
        ->and($entry->payment_method_id)->toBe(PaymentMethod::Cash->id())
        ->and($entry->description)->toBe('Mensalidade 10/2026 - Maria Aparecida Souza');
});

test('an amount below the invoice is rejected and a higher one is accepted', function () {
    $invoice = Invoice::factory()->create(['amount' => '89.90']);

    expect(paymentError(fn () => registerPayment($invoice, ['amount' => '89.89']), 'amount'))
        ->toBe('O valor recebido não pode ser menor que R$ 89,90.')
        ->and(Payment::count())->toBe(0);

    $payment = registerPayment($invoice, ['amount' => '95.00']);

    expect($payment->amount)->toBe('95.00')
        ->and($payment->financialEntry->amount)->toBe('95.00');
});

test('paid and cancelled invoices are rejected', function (string $state, string $message) {
    $invoice = Invoice::factory()->{$state}()->create();

    expect(paymentError(fn () => registerPayment($invoice), 'invoice'))->toBe($message)
        ->and(Payment::count())->toBe(0);
})->with([
    'paid' => ['paid', 'Esta mensalidade já foi paga.'],
    'cancelled' => ['cancelled', 'Esta mensalidade está cancelada.'],
]);

test('paying the same invoice twice results in a single payment', function () {
    $invoice = Invoice::factory()->create();

    registerPayment($invoice);

    expect(paymentError(fn () => registerPayment($invoice), 'invoice'))->toBe('Esta mensalidade já foi paga.')
        ->and($invoice->payments()->count())->toBe(1)
        ->and(FinancialEntry::count())->toBe(1);
});

test('receipt numbers are sequential', function () {
    $first = registerPayment(Invoice::factory()->create());
    $second = registerPayment(Invoice::factory()->create());

    expect($second->receipt_number)->toBe($first->receipt_number + 1);
});

test('a suspended card whose only overdue invoice is paid is reactivated', function () {
    $card = Card::factory()->suspended()->create();
    $invoice = Invoice::factory()->overdue(35)->create(['customer_id' => $card->customer_id]);

    expect(app(PaymentWillReactivateCard::class)->handle($invoice))->toBeTrue();

    registerPayment($invoice);

    $log = $card->statusLogs()->latest('id')->first();

    expect($card->refresh()->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($log->reason)->toBe('Reativação por pagamento')
        ->and($log->to_card_status_id)->toBe(CardStatus::Active->id());
});

test('a suspended card stays suspended while another invoice is late 30 days or more', function () {
    $card = Card::factory()->suspended()->create();
    $paid = Invoice::factory()->overdue(10)->create(['customer_id' => $card->customer_id]);
    Invoice::factory()->overdue(30)->create(['customer_id' => $card->customer_id]);

    expect(app(PaymentWillReactivateCard::class)->handle($paid))->toBeFalse();

    registerPayment($paid);

    expect($card->refresh()->hasStatus(CardStatus::Suspended))->toBeTrue()
        ->and($card->statusLogs()->count())->toBe(0);
});

test('a cancelled card is never reactivated by a payment', function () {
    $card = Card::factory()->cancelled()->create();
    $invoice = Invoice::factory()->overdue(35)->create(['customer_id' => $card->customer_id]);

    expect(app(PaymentWillReactivateCard::class)->handle($invoice))->toBeFalse();

    registerPayment($invoice);

    expect($card->refresh()->hasStatus(CardStatus::Cancelled))->toBeTrue()
        ->and($card->statusLogs()->count())->toBe(0);
});

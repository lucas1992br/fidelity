<?php

use App\Actions\Billing\RefundPayment;
use App\Actions\Billing\RegisterPayment;
use App\Enums\CardStatus;
use App\Enums\EntryStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Card;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

/**
 * Invoice paid through RegisterPayment by the admin.
 */
function paidInvoicePayment(Invoice $invoice): Payment
{
    return app(RegisterPayment::class)->handle($invoice, [
        'paid_at' => '2026-10-15',
        'amount' => $invoice->amount,
        'payment_method' => PaymentMethod::Pix->value,
    ], test()->admin);
}

test('refunding returns the invoice to open or overdue by the due date and clears paid_at', function (int $daysFromToday, InvoiceStatus $expected) {
    $invoice = Invoice::factory()->create(['due_date' => today()->addDays($daysFromToday)->toDateString()]);
    $payment = paidInvoicePayment($invoice);

    app(RefundPayment::class)->handle($payment, 'Pagamento lançado em duplicidade', $this->admin);

    $invoice->refresh();
    expect($invoice->hasStatus($expected))->toBeTrue()
        ->and($invoice->paid_at)->toBeNull();
})->with([
    'due today' => [0, InvoiceStatus::Open],
    'not yet due' => [5, InvoiceStatus::Open],
    'past due' => [-3, InvoiceStatus::Overdue],
]);

test('the linked income is cancelled and the payment is kept with the refund stamp', function () {
    $payment = paidInvoicePayment(Invoice::factory()->create());

    app(RefundPayment::class)->handle($payment, 'Erro de digitação', $this->admin);

    $payment = Payment::query()->findOrFail($payment->id);

    expect($payment->refunded_at->toDateTimeString())->toBe('2026-10-15 10:00:00')
        ->and($payment->refunded_by_user_id)->toBe($this->admin->id)
        ->and($payment->refund_reason)->toBe('Erro de digitação')
        ->and($payment->financialEntry->entry_status_id)->toBe(EntryStatus::Cancelled->id())
        ->and(Activity::query()->where('event', 'refunded')->where('subject_id', $payment->id)->exists())->toBeTrue();
});

test('the reason is optional and the card status is not changed', function () {
    $card = Card::factory()->suspended()->create();
    $invoice = Invoice::factory()->overdue(40)->create(['customer_id' => $card->customer_id]);
    $payment = paidInvoicePayment($invoice);
    expect($card->refresh()->hasStatus(CardStatus::Active))->toBeTrue();

    app(RefundPayment::class)->handle($payment, null, $this->admin);

    expect($payment->refresh()->refund_reason)->toBeNull()
        ->and($card->refresh()->hasStatus(CardStatus::Active))->toBeTrue();
});

test('a payment cannot be refunded twice', function () {
    $payment = paidInvoicePayment(Invoice::factory()->create());

    app(RefundPayment::class)->handle($payment, null, $this->admin);
    app(RefundPayment::class)->handle($payment, null, $this->admin);
})->throws(ValidationException::class, 'Este pagamento já foi estornado.');

test('financeiro cannot refund', function () {
    $payment = paidInvoicePayment(Invoice::factory()->create());
    $financeiro = User::factory()->create();
    $financeiro->assignRole('financeiro');

    expect(fn () => app(RefundPayment::class)->handle($payment, null, $financeiro))
        ->toThrow(AuthorizationException::class)
        ->and($payment->refresh()->refunded_at)->toBeNull();
});

test('after the refund the invoice can be paid again with a new receipt', function () {
    $invoice = Invoice::factory()->create();
    $first = paidInvoicePayment($invoice);

    app(RefundPayment::class)->handle($first, null, $this->admin);
    $second = paidInvoicePayment($invoice->refresh());

    expect($second->receipt_number)->toBeGreaterThan($first->receipt_number)
        ->and($invoice->refresh()->hasStatus(InvoiceStatus::Paid))->toBeTrue()
        ->and($invoice->activePayment->is($second))->toBeTrue()
        ->and($invoice->payments()->count())->toBe(2);
});

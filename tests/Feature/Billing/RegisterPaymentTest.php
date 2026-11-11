<?php

use App\Actions\Billing\PaymentWillReactivateCard;
use App\Actions\Billing\RegisterPayment;
use App\Enums\CardStatus;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Card;
use App\Models\CardFee;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
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

    $entry = $payment->financialEntries()->sole();
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
        ->and($payment->financialEntries()->sole()->amount)->toBe('95.00');
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

test('a boleto payment in 3 installments pays the invoice and books one pending income per month', function () {
    $customer = Customer::factory()->withCard()->create(['name' => 'Maria Aparecida Souza']);
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => '100.00']);

    $payment = registerPayment($invoice, ['paid_at' => '2026-10-31', 'payment_method' => PaymentMethod::Boleto->value, 'installments' => 3]);

    expect($payment->installments)->toBe(3)
        ->and($payment->amount)->toBe('100.00');

    expect($invoice->refresh()->hasStatus(InvoiceStatus::Paid))->toBeTrue();

    $entries = $payment->financialEntries()->get();

    expect($entries->pluck('installment_number')->all())->toBe([1, 2, 3])
        ->and($entries->pluck('amount')->all())->toBe(['33.34', '33.33', '33.33'])
        ->and($entries->map(fn (FinancialEntry $entry): string => $entry->due_date->toDateString())->all())->toBe(['2026-10-31', '2026-11-30', '2026-12-31'])
        ->and($entries->pluck('entry_status_id')->unique()->all())->toBe([EntryStatus::Pending->id()])
        ->and($entries->pluck('paid_at')->filter()->all())->toBe([])
        ->and($entries->pluck('payment_method_id')->unique()->all())->toBe([PaymentMethod::Boleto->id()])
        ->and($entries->pluck('financial_category_id')->unique()->all())->toBe([FinancialCategory::monthlyFees()->id])
        ->and($entries->last()->description)->toBe('Mensalidade 10/2026 - Maria Aparecida Souza (parcela 3/3)');
});

test('a boleto payment may be split into 12 installments', function () {
    $invoice = Invoice::factory()->create(['amount' => '120.00']);

    $payment = registerPayment($invoice, ['payment_method' => PaymentMethod::Boleto->value, 'installments' => 12]);

    expect($payment->financialEntries()->count())->toBe(12)
        ->and($payment->financialEntries()->sum('amount'))->toBe('120.00');
});

test('only card and boleto payments may be split into installments', function (PaymentMethod $method) {
    $invoice = Invoice::factory()->create();

    expect(paymentError(fn () => registerPayment($invoice, ['payment_method' => $method->value, 'installments' => 2]), 'installments'))
        ->toBe('Só é possível parcelar no cartão ou no boleto.')
        ->and(Payment::count())->toBe(0);
})->with([PaymentMethod::Pix, PaymentMethod::Cash, PaymentMethod::Recurring]);

test('more than 12 installments are rejected', function () {
    $invoice = Invoice::factory()->create();

    expect(paymentError(fn () => registerPayment($invoice, ['payment_method' => PaymentMethod::Card->value, 'installments' => 13]), 'installments'))
        ->toBe('O campo parcelas deve ser entre 1 e 12.')
        ->and(Payment::count())->toBe(0);
});

test('a year of plan in 12 boletos issues the missing months, pays all of them and splits the total into 12 monthly installments', function () {
    $customer = Customer::factory()->withCard()->for(Plan::factory()->state(['price' => '89.90']))->create(['name' => 'Maria Aparecida Souza', 'due_day' => 10]);
    $october = Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => '89.90']);

    $payment = registerPayment($october, [
        'amount' => '1078.80',
        'payment_method' => PaymentMethod::Boleto->value,
        'months' => 12,
        'installments' => 12,
    ]);

    $invoices = $customer->invoices()->orderBy('reference_month')->get();

    expect($payment->months)->toBe(12)
        ->and($payment->invoice_id)->toBe($october->id)
        ->and($payment->invoices()->pluck('invoices.id')->all())->toBe($invoices->modelKeys());

    expect($invoices->map(fn (Invoice $invoice): string => $invoice->reference_month->format('Y-m'))->all())
        ->toBe(['2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06', '2027-07', '2027-08', '2027-09'])
        ->and($invoices->every(fn (Invoice $invoice): bool => $invoice->hasStatus(InvoiceStatus::Paid)))->toBeTrue()
        ->and($invoices->last()->due_date->toDateString())->toBe('2027-09-10')
        ->and($invoices->last()->activePayment()->sole()->is($payment))->toBeTrue();

    $entries = $payment->financialEntries()->get();

    expect($entries)->toHaveCount(12)
        ->and($entries->pluck('amount')->unique()->all())->toBe(['89.90'])
        ->and($entries->first()->description)->toBe('Mensalidades 10/2026 a 09/2027 - Maria Aparecida Souza (parcela 1/12)');
});

test('the period total adds the frozen amount of invoiced months to the plan price of the others', function () {
    $customer = Customer::factory()->for(Plan::factory()->state(['price' => '100.00']))->create();
    $october = Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => '89.90']);
    Invoice::factory()->for($customer)->forMonth('2026-11')->create(['amount' => '95.00']);

    expect(paymentError(fn () => registerPayment($october, ['amount' => '284.89', 'months' => 3]), 'amount'))
        ->toBe('O valor recebido não pode ser menor que R$ 284,90.');

    $payment = registerPayment($october, ['amount' => '284.90', 'months' => 3]);

    expect($payment->financialEntries()->sole()->amount)->toBe('284.90')
        ->and($payment->financialEntries()->sole()->description)->toEndWith('10/2026 a 12/2026 - '.$customer->name);
});

test('a period with a month already paid is rejected and issues nothing', function () {
    $customer = Customer::factory()->create();
    $october = Invoice::factory()->for($customer)->forMonth('2026-10')->create();
    Invoice::factory()->for($customer)->forMonth('2026-12')->paid()->create();

    expect(paymentError(fn () => registerPayment($october, ['amount' => '9999.00', 'months' => 3]), 'invoice'))
        ->toBe('A mensalidade 12/2026 já foi paga.')
        ->and(Payment::count())->toBe(0)
        ->and($customer->invoices()->count())->toBe(2);
});

test('an invoice of a company bill is paid only through the company', function () {
    $customer = Customer::factory()->forCompany(Company::factory()->create())->withCard()->create();
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->create();

    expect(paymentError(fn () => registerPayment($invoice), 'invoice'))
        ->toBe('Esta mensalidade faz parte da fatura da empresa; registre o pagamento na página da empresa.')
        ->and(Payment::count())->toBe(0);
});

test('a card payment records the payer card digits and remembers them on the holder', function () {
    $customer = Customer::factory()->withCard()->create();
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->create();

    $payment = registerPayment($invoice, ['payment_method' => PaymentMethod::Card->value, 'card_digits' => '498407-9593']);

    expect($payment->card_bin)->toBe('498407')
        ->and($payment->card_last4)->toBe('9593')
        ->and($customer->paymentCards()->sole()->masked_number)->toBe('498407******9593');
});

test('card digits are rejected when incomplete or not paying by card', function (array $overrides, string $message) {
    $invoice = Invoice::factory()->for(Customer::factory()->withCard())->forMonth('2026-10')->create();

    expect(paymentError(fn () => registerPayment($invoice, $overrides), 'card_digits'))->toBe($message)
        ->and(Payment::count())->toBe(0);
})->with([
    'incomplete' => [['payment_method' => PaymentMethod::Card->value, 'card_digits' => '498407-95'], 'Informe os 6 primeiros e os 4 últimos dígitos do cartão.'],
    'not a card payment' => [['payment_method' => PaymentMethod::Pix->value, 'card_digits' => '498407-9593'], 'Os dígitos do cartão só valem para pagamento com cartão.'],
]);

test('a recurring card payment pays the invoice with its own method and remembers the payer card', function () {
    $customer = Customer::factory()->withCard()->create();
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->create();

    $payment = registerPayment($invoice, ['payment_method' => PaymentMethod::Recurring->value, 'card_digits' => '498407-9593']);

    expect($payment->payment_method_id)->toBe(PaymentMethod::Recurring->id())
        ->and($payment->methodLabel())->toBe('Cartão recorrente')
        ->and($invoice->refresh()->hasStatus(InvoiceStatus::Paid))->toBeTrue()
        ->and($customer->paymentCards()->sole()->masked_number)->toBe('498407******9593');
});

test('a year of plan on the card in 12x is received from the acquirer at once, with the card machine fee', function () {
    settings(['card_settlement_days' => 1]);
    CardFee::query()->where('is_debit', false)->where('installments', 12)->update(['rate' => '9.99']);
    $customer = Customer::factory()->withCard()->for(Plan::factory()->state(['price' => '89.90']))->create(['name' => 'Maria Aparecida Souza']);
    $october = Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => '89.90']);

    $payment = registerPayment($october, ['amount' => '1078.80', 'payment_method' => PaymentMethod::Card->value, 'months' => 12, 'installments' => 12]);

    $income = $payment->financialEntries()->sole();
    $fee = $income->fees()->sole();

    expect($payment->card_fee_rate)->toBe('9.99')
        ->and($payment->card_fee_amount)->toBe('107.77')
        ->and($customer->invoices()->get()->every(fn (Invoice $invoice): bool => $invoice->hasStatus(InvoiceStatus::Paid)))->toBeTrue()
        ->and($income->amount)->toBe('1078.80')
        ->and($income->entry_status_id)->toBe(EntryStatus::Pending->id())
        ->and($income->due_date->toDateString())->toBe('2026-10-16')
        ->and($income->supplier_or_payer)->toBe('Granito')
        ->and($income->description)->toBe('Mensalidades 10/2026 a 09/2027 - Maria Aparecida Souza · cartão Crédito 12x')
        ->and($fee->entry_type_id)->toBe(EntryType::Expense->id())
        ->and($fee->financial_category_id)->toBe(FinancialCategory::cardFees()->id)
        ->and($fee->amount)->toBe('107.77')
        ->and($fee->due_date->toDateString())->toBe('2026-10-16')
        ->and($fee->entry_status_id)->toBe(EntryStatus::Pending->id());
});

test('a debit card sale uses the debit rate and cannot be split', function () {
    CardFee::query()->where('is_debit', true)->update(['rate' => '1.50']);
    $invoice = Invoice::factory()->for(Customer::factory()->withCard())->forMonth('2026-10')->create(['amount' => '100.00']);

    expect(paymentError(fn () => registerPayment($invoice, ['payment_method' => PaymentMethod::Card->value, 'card_debit' => true, 'installments' => 2]), 'installments'))
        ->toBe('Venda no débito não tem parcelas.');

    $payment = registerPayment($invoice, ['payment_method' => PaymentMethod::Card->value, 'card_debit' => true]);

    expect($payment->is_debit)->toBeTrue()
        ->and($payment->card_fee_amount)->toBe('1.50')
        ->and($payment->financialEntries()->sole()->description)->toEndWith('· cartão Débito');
});

<?php

use App\Actions\Billing\CancelInvoice;
use App\Actions\Cards\AssignCard;
use App\Actions\Cards\CancelCard;
use App\Actions\Cards\ReactivateCard;
use App\Enums\CardStatus;
use App\Enums\InvoiceStatus;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Validation\ValidationException;

test('cancelling a pending invoice marks it cancelled', function (string $state) {
    $invoice = Invoice::factory()->{$state}()->create();

    app(CancelInvoice::class)->handle($invoice);

    expect($invoice->refresh()->hasStatus(InvoiceStatus::Cancelled))->toBeTrue();
})->with(['open', 'overdue']);

test('a paid or cancelled invoice cannot be cancelled', function (string $state, InvoiceStatus $status) {
    $invoice = Invoice::factory()->{$state}()->create();

    expect(fn () => app(CancelInvoice::class)->handle($invoice))
        ->toThrow(ValidationException::class, 'Só mensalidades em aberto ou em atraso podem ser canceladas.');

    expect($invoice->refresh()->hasStatus($status))->toBeTrue();
})->with([
    'paid' => ['paid', InvoiceStatus::Paid],
    'cancelled' => ['cancelled', InvoiceStatus::Cancelled],
]);

test('the cancel option cancels the 2 pending invoices and keeps the paid ones', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->create();
    $card = Card::factory()->suspended()->for($customer)->create();
    $paid = Invoice::factory()->for($customer)->forMonth('2026-07')->paid()->create();
    $overdue = Invoice::factory()->for($customer)->forMonth('2026-08')->create([
        'invoice_status_id' => InvoiceStatus::Overdue->id(),
        'due_date' => today()->subDays(40)->toDateString(),
    ]);
    $open = Invoice::factory()->for($customer)->forMonth('2026-10')->open()->create();
    $otherCustomerInvoice = Invoice::factory()->overdue(10)->create();

    app(CancelCard::class)->handle($card, 'Desistência do titular', CancelCard::CancelPendingInvoices, $user);

    $card->refresh();
    $log = $card->statusLogs()->sole();

    expect($card->hasStatus(CardStatus::Cancelled))->toBeTrue()
        ->and($card->is_current)->toBeFalse()
        ->and($card->customer_id)->toBe($customer->id)
        ->and($log->reason)->toBe('Desistência do titular')
        ->and($log->user_id)->toBe($user->id)
        ->and($overdue->refresh()->hasStatus(InvoiceStatus::Cancelled))->toBeTrue()
        ->and($open->refresh()->hasStatus(InvoiceStatus::Cancelled))->toBeTrue()
        ->and($paid->refresh()->hasStatus(InvoiceStatus::Paid))->toBeTrue()
        ->and($otherCustomerInvoice->refresh()->hasStatus(InvoiceStatus::Overdue))->toBeTrue()
        ->and($customer->refresh()->currentCard)->toBeNull();
});

test('the keep option keeps the overdue invoices chargeable among the delinquents', function () {
    $customer = Customer::factory()->create();
    $card = Card::factory()->active()->for($customer)->create();
    $overdue = Invoice::factory()->for($customer)->overdue(35)->create();

    app(CancelCard::class)->handle($card, 'Mudou de cidade', CancelCard::KeepPendingInvoices);

    expect($card->refresh()->hasStatus(CardStatus::Cancelled))->toBeTrue()
        ->and($overdue->refresh()->hasStatus(InvoiceStatus::Overdue))->toBeTrue()
        ->and(Invoice::query()->overdue()->where('customer_id', $customer->id)->pluck('id')->all())->toBe([$overdue->id]);
});

test('a cancelled card can be neither reactivated nor linked again', function () {
    $user = actingAsRole('admin');
    $customer = Customer::factory()->create();
    $card = Card::factory()->active()->for($customer)->create(['number' => '000123']);

    app(CancelCard::class)->handle($card, 'Desistência', CancelCard::KeepPendingInvoices, $user);

    expect(fn () => app(ReactivateCard::class)->handle($card, 'Voltou', $user))
        ->toThrow(ValidationException::class, 'O cancelamento é definitivo');

    expect(fn () => app(AssignCard::class)->handle(Customer::factory()->create(), '000123', $user))
        ->toThrow(ValidationException::class, 'Já vinculado');

    expect($card->refresh()->hasStatus(CardStatus::Cancelled))->toBeTrue()
        ->and($card->customer_id)->toBe($customer->id);
});

test('only current cards can be cancelled', function (CardStatus $status) {
    $card = Card::factory()->status($status)->create();

    expect(fn () => app(CancelCard::class)->handle($card, 'Motivo', CancelCard::KeepPendingInvoices))
        ->toThrow(ValidationException::class, 'Só cartões ativos ou suspensos podem ser cancelados.');

    expect($card->refresh()->hasStatus($status))->toBeTrue();
})->with([
    'cancelled' => [CardStatus::Cancelled],
    'in stock' => [CardStatus::InStock],
    'lost' => [CardStatus::Lost],
]);

test('the reason and the invoices option are mandatory', function (?string $reason, string $option) {
    $customer = Customer::factory()->create();
    $card = Card::factory()->active()->for($customer)->create();
    $invoice = Invoice::factory()->for($customer)->overdue(5)->create();

    expect(fn () => app(CancelCard::class)->handle($card, $reason, $option))
        ->toThrow(ValidationException::class);

    expect($card->refresh()->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($invoice->refresh()->hasStatus(InvoiceStatus::Overdue))->toBeTrue();
})->with([
    'empty reason' => ['  ', CancelCard::CancelPendingInvoices],
    'unknown option' => ['Motivo', 'delete'],
]);

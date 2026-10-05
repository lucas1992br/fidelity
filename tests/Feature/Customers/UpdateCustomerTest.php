<?php

use App\Actions\Billing\IssueInvoice;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('changing the due day from 10 to 20 keeps the open invoice due on the 10th and applies to the next ones', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create(['due_day' => 10]);
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->create(['due_date' => '2026-10-10']);

    Livewire::test('pages::customers.edit', ['customer' => $customer])
        ->assertSet('form.due_day', 10)
        ->set('form.due_day', 20)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('customers.show', $customer));

    expect($customer->refresh()->due_day)->toBe(20)
        ->and($invoice->refresh()->due_date->toDateString())->toBe('2026-10-10')
        ->and($invoice->invoice_status_id)->toBe(InvoiceStatus::Open->id());

    $next = app(IssueInvoice::class)->handle($customer, CarbonImmutable::parse('2026-11-01'));

    expect($next->due_date->toDateString())->toBe('2026-11-20');
});

test('the edit saves the personal, contact and address data normalized', function () {
    actingAsRole('admin');
    $customer = Customer::factory()->withCard()->create();
    $planId = $customer->plan_id;

    Livewire::test('pages::customers.edit', ['customer' => $customer])
        ->set('form.name', 'Maria Aparecida Souza')
        ->set('form.phone', '(11) 91234-5678')
        ->set('form.zip_code', '01310-100')
        ->set('form.city', 'São Paulo')
        ->set('form.state', 'sp')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->refresh())
        ->name->toBe('Maria Aparecida Souza')
        ->phone->toBe('11912345678')
        ->zip_code->toBe('01310100')
        ->state->toBe('SP')
        ->plan_id->toBe($planId);
});

test('the holder keeps their own CPF but cannot take the CPF of another holder', function () {
    actingAsRole('financeiro');
    Customer::factory()->create(['cpf' => '52998224725']);
    $customer = Customer::factory()->withCard()->create();
    $ownCpf = $customer->cpf;

    Livewire::test('pages::customers.edit', ['customer' => $customer])
        ->call('save')
        ->assertHasNoErrors()
        ->set('form.cpf', '529.982.247-25')
        ->call('save')
        ->assertHasErrors(['form.cpf' => 'unique']);

    expect($customer->refresh()->cpf)->toBe($ownCpf);
});

test('a holder whose plan was deactivated can still be edited', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    $customer->plan->update(['is_active' => false]);

    Livewire::test('pages::customers.edit', ['customer' => $customer])
        ->set('form.due_day', 5)
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->refresh()->due_day)->toBe(5);
});

test('atendente gets 403 on the edit screen and on save', function () {
    $customer = Customer::factory()->withCard()->create(['due_day' => 10]);

    actingAsRole('atendente');
    $this->get(route('customers.edit', $customer))->assertForbidden();
    Livewire::test('pages::customers.edit', ['customer' => $customer])->assertForbidden();

    actingAsRole('financeiro');
    $page = Livewire::test('pages::customers.edit', ['customer' => $customer]);
    actingAsRole('atendente');
    $page->set('form.due_day', 20)->call('save')->assertForbidden();

    expect($customer->refresh()->due_day)->not->toBe(20);
});

<?php

use App\Enums\CardStatus;
use App\Enums\EntryType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Reports\CashFlowReportExport;
use App\Reports\CustomersReport;
use App\Reports\DelinquencyReport;
use App\Reports\EntriesReport;
use App\Reports\InvoicesReport;
use App\Reports\PaymentsReport;
use App\Reports\Report;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
    $this->from = CarbonImmutable::parse('2026-10-01');
    $this->until = CarbonImmutable::parse('2026-10-31');
});

test('the catalog lists the reports', function () {
    expect(array_keys(Report::catalog()))->toBe(['clientes', 'empresas', 'mensalidades', 'inadimplencia', 'pagamentos', 'lancamentos', 'fluxo-de-caixa', 'vendas-por-atendente']);
});

test('payments grouped by method add up and leave refunded payments out', function () {
    Payment::factory()->method(PaymentMethod::Pix)->create(['paid_at' => '2026-10-02', 'amount' => '89.90']);
    Payment::factory()->method(PaymentMethod::Pix)->create(['paid_at' => '2026-10-20', 'amount' => '100.10']);
    Payment::factory()->method(PaymentMethod::Cash)->create(['paid_at' => '2026-10-05', 'amount' => '50.00']);
    Payment::factory()->method(PaymentMethod::Cash)->refunded()->create(['paid_at' => '2026-10-06', 'amount' => '70.00']);
    Payment::factory()->method(PaymentMethod::Card)->create(['paid_at' => '2026-09-30', 'amount' => '999.00']);

    $report = new PaymentsReport($this->from, $this->until);
    $totals = $report->totals();

    expect($report->query()->count())->toBe(4)
        ->and(collect($totals['methods'])->mapWithKeys(fn (array $line): array => [$line['method']->value => [$line['count'], $line['amount']]])->all())
        ->toBe(['pix' => [2, '190.00'], 'cash' => [1, '50.00']])
        ->and($totals['total'])->toBe(['count' => 3, 'amount' => '240.00'])
        ->and($totals['refunded'])->toBe(['count' => 1, 'amount' => '70.00']);
});

test('entries by category ignore cancelled ones', function () {
    $rent = FinancialCategory::factory()->expense()->create(['name' => 'Aluguel']);
    $other = FinancialCategory::factory()->income()->create(['name' => 'Outras receitas']);
    FinancialEntry::factory()->for($rent, 'category')->paid()->create(['entry_type_id' => EntryType::Expense->id(), 'due_date' => '2026-10-05', 'amount' => '1500.00']);
    FinancialEntry::factory()->for($rent, 'category')->pending()->create(['entry_type_id' => EntryType::Expense->id(), 'due_date' => '2026-10-25', 'amount' => '200.00']);
    FinancialEntry::factory()->for($rent, 'category')->cancelled()->create(['entry_type_id' => EntryType::Expense->id(), 'due_date' => '2026-10-06', 'amount' => '800.00']);
    FinancialEntry::factory()->for($other, 'category')->paid()->create(['entry_type_id' => EntryType::Income->id(), 'due_date' => '2026-10-10', 'amount' => '300.00']);
    FinancialEntry::factory()->for($other, 'category')->paid()->create(['entry_type_id' => EntryType::Income->id(), 'due_date' => '2026-11-10', 'amount' => '999.00']);

    $report = new EntriesReport($this->from, $this->until);
    $totals = $report->totals();

    expect($report->query()->pluck('amount')->all())->toBe(['300.00', '1500.00', '200.00'])
        ->and($totals['categories'])->toBe([
            ['category' => 'Outras receitas', 'type' => EntryType::Income, 'count' => 1, 'amount' => '300.00', 'paid' => '300.00'],
            ['category' => 'Aluguel', 'type' => EntryType::Expense, 'count' => 2, 'amount' => '1700.00', 'paid' => '1500.00'],
        ])
        ->and($totals['income'])->toBe('300.00')
        ->and($totals['expense'])->toBe('1700.00');
});

test('customers filtered by plan and status follow the rules of the customer list', function () {
    $family = Plan::factory()->create(['name' => 'Familiar']);
    $single = Plan::factory()->create(['name' => 'Individual']);
    $active = Customer::factory()->for($family)->withCard()->create(['name' => 'Ana Ativa']);
    Dependent::factory()->for($active)->count(2)->create();
    Dependent::factory()->for($active)->inactive()->create();
    $suspended = Customer::factory()->for($family)->withCard(CardStatus::Suspended)->create(['name' => 'Bia Suspensa']);
    $cancelled = Customer::factory()->for($family)->withCard(CardStatus::Cancelled)->create(['name' => 'Caio Cancelado']);
    Customer::factory()->for($single)->withCard()->create(['name' => 'Davi Individual']);

    $byPlan = new CustomersReport($this->from, $this->until, planId: $family->id);
    $activeOfPlan = new CustomersReport($this->from, $this->until, planId: $family->id, status: CardStatus::Active->value);
    $cancelledOnly = new CustomersReport($this->from, $this->until, status: CardStatus::Cancelled->value);

    expect($byPlan->query()->pluck('id')->all())->toBe([$active->id, $suspended->id, $cancelled->id])
        ->and($activeOfPlan->query()->pluck('id')->all())->toBe([$active->id])
        ->and($cancelledOnly->query()->pluck('id')->all())->toBe([$cancelled->id])
        ->and(CustomersReport::cardStatusOf($cancelledOnly->query()->first()))->toBe(CardStatus::Cancelled)
        ->and($activeOfPlan->totalsByPlan($activeOfPlan->query()->get()))->toBe([['plan' => 'Familiar', 'holders' => 1, 'dependents' => 2]]);
});

test('invoices, delinquency and cash flow reuse the queries of their screens', function () {
    $customer = Customer::factory()->withCard()->create();
    Invoice::factory()->for($customer)->overdue(20)->create(['amount' => '89.90']);
    Invoice::factory()->for($customer)->forMonth('2026-10')->paid()->create(['amount' => '89.90']);
    Invoice::factory()->for($customer)->forMonth('2026-11')->open()->create();
    FinancialEntry::factory()->income()->paid()->create(['due_date' => '2026-10-03', 'paid_at' => '2026-10-03', 'amount' => '89.90']);

    $invoices = new InvoicesReport($this->from, $this->until, status: InvoiceStatus::Paid->value);
    $delinquency = new DelinquencyReport($this->from, $this->until);
    $cashFlow = new CashFlowReportExport($this->from, $this->until);

    expect($invoices->rowCount())->toBe(1)
        ->and($invoices->pdfData()['summary']['paid'])->toBe(['amount' => '89.90', 'count' => 1])
        ->and($delinquency->query()->pluck('id')->all())->toBe([$customer->id])
        ->and($delinquency->periodLabel())->toBe('Posição em 15/10/2026')
        ->and($cashFlow->granularity())->toBe('daily')
        ->and($cashFlow->rowCount())->toBe(31)
        ->and($cashFlow->data()['total_income'])->toBe('89.90')
        ->and((new CashFlowReportExport(CarbonImmutable::parse('2026-01-01'), $this->until))->rowCount())->toBe(10);
});

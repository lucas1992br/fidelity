<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\User;
use App\Reports\CompaniesReport;
use App\Reports\SalesByUserReport;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
    $this->from = CarbonImmutable::parse('2026-10-01');
    $this->until = CarbonImmutable::parse('2026-10-31');
});

test('the companies report totals the employees in force, their dependents and the monthly bill', function () {
    $company = Company::factory()->create(['name' => 'Metalúrgica Souza LTDA']);
    $employee = Customer::factory()->forCompany($company)->withCard()->create(['monthly_amount' => '100.00']);
    Customer::factory()->forCompany($company)->withCard()->create(['monthly_amount' => '50.00']);
    Customer::factory()->forCompany($company)->create(['monthly_amount' => '999.00']);
    Dependent::factory()->count(2)->for($employee)->create();
    Dependent::factory()->inactive()->for($employee)->create();
    Customer::factory()->withCard()->create();

    $data = (new CompaniesReport($this->from, $this->until))->pdfData();

    expect($data['companies'])->toHaveCount(1)
        ->and($data['companies']->first()->employees)->toHaveCount(3)
        ->and($data['totals'])->toBe([['company' => 'Metalúrgica Souza LTDA', 'employees' => 2, 'dependents' => 2, 'monthly' => '150.00']]);
});

test('the companies report filters active or inactive companies', function (string $status, string $expected) {
    Company::factory()->create(['name' => 'Ativa LTDA']);
    Company::factory()->inactive()->create(['name' => 'Inativa LTDA']);

    $names = (new CompaniesReport($this->from, $this->until, status: $status))->query()->pluck('name')->all();

    expect($names)->toBe([$expected]);
})->with([
    'active' => [CompaniesReport::StatusActive, 'Ativa LTDA'],
    'inactive' => [CompaniesReport::StatusInactive, 'Inativa LTDA'],
]);

test('the sales report totals each user sales, re-enrollments and amounts with the first invoice paid', function () {
    $ana = User::factory()->create(['name' => 'Ana Atendente']);
    $bruno = User::factory()->create(['name' => 'Bruno Atendente']);
    $paidCustomer = Customer::factory()->create(['monthly_amount' => '100.00']);
    PlanChange::factory()->for($paidCustomer)->for($ana)->sale()->create(['to_price' => '100.00', 'created_at' => '2026-10-05 09:00']);
    Invoice::factory()->for($paidCustomer)->forMonth('2026-10')->paid()->create(['created_at' => '2026-10-05 09:00']);
    $openCustomer = Customer::factory()->create();
    PlanChange::factory()->for($openCustomer)->for($ana)->sale()->create(['to_price' => '60.00', 'created_at' => '2026-10-06 09:00']);
    Invoice::factory()->for($openCustomer)->forMonth('2026-10')->create(['created_at' => '2026-10-06 09:00']);
    PlanChange::factory()->for(Customer::factory())->for($bruno)->reenrollment()->create(['to_price' => '80.00', 'created_at' => '2026-10-07 09:00']);
    PlanChange::factory()->for(Customer::factory())->for($ana)->sale()->create(['created_at' => '2026-09-30 23:00']);
    PlanChange::factory()->for(Customer::factory())->for($ana)->from(Plan::factory()->create())->create(['created_at' => '2026-10-08 09:00']);

    $data = (new SalesByUserReport($this->from, $this->until))->pdfData();

    expect($data['sales'])->toHaveCount(3)
        ->and($data['totals'])->toBe([
            ['user' => 'Ana Atendente', 'sales' => 2, 'reenrollments' => 0, 'monthly' => '160.00', 'paid_sales' => 1, 'paid_monthly' => '100.00'],
            ['user' => 'Bruno Atendente', 'sales' => 0, 'reenrollments' => 1, 'monthly' => '80.00', 'paid_sales' => 0, 'paid_monthly' => '0.00'],
        ]);
});

test('the sales report is filtered by the chosen user on the reports screen', function () {
    actingAsRole('financeiro');
    $ana = User::factory()->create(['name' => 'Ana Atendente']);
    $bruno = User::factory()->create(['name' => 'Bruno Atendente']);
    PlanChange::factory()->for(Customer::factory())->for($ana)->sale()->create(['created_at' => '2026-10-05 09:00']);
    PlanChange::factory()->for(Customer::factory())->for($bruno)->sale()->create(['created_at' => '2026-10-05 09:00']);

    $report = new SalesByUserReport($this->from, $this->until, userId: $ana->id);

    expect($report->query()->pluck('user_id')->all())->toBe([$ana->id]);

    Livewire::test('pages::reports')
        ->call('selectReport', 'vendas-por-atendente')
        ->assertSee('Atendente')
        ->set('user', (string) $ana->id)
        ->call('downloadPdf')
        ->assertHasNoErrors();
});

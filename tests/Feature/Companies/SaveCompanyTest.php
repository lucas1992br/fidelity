<?php

use App\Actions\Companies\SaveCompany;
use App\Models\Company;
use App\Models\CompanyBill;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function companyInput(array $overrides = []): array
{
    return [
        'name' => '  Metalúrgica Souza LTDA ',
        'trade_name' => '',
        'cnpj' => '11.222.333/0001-81',
        'contact_name' => 'Joana RH',
        'email' => '',
        'phone' => '(11) 3333-4444',
        'due_day' => 15,
        'monthly_amount' => '1.500,00',
        'notes' => '',
        'is_active' => true,
        ...$overrides,
    ];
}

test('a new company is saved with the CNPJ and phone as digits and empty fields as null', function () {
    $company = app(SaveCompany::class)->handle(companyInput(), actingAsRole('financeiro'));

    expect($company->refresh()->name)->toBe('Metalúrgica Souza LTDA')
        ->and($company->cnpj)->toBe('11222333000181')
        ->and($company->phone)->toBe('1133334444')
        ->and($company->trade_name)->toBeNull()
        ->and($company->email)->toBeNull()
        ->and($company->due_day)->toBe(15)
        ->and($company->monthly_amount)->toBe('1500.00');
});

test('an invalid or duplicated CNPJ is rejected', function (string $cnpj, string $message) {
    Company::factory()->create(['cnpj' => '11222333000181']);

    expect(fn () => app(SaveCompany::class)->handle(companyInput(['cnpj' => $cnpj]), actingAsRole('financeiro')))
        ->toThrow(ValidationException::class, $message);
})->with([
    'invalid' => ['11.222.333/0001-82', 'O CNPJ informado é inválido.'],
    'duplicated' => ['11.222.333/0001-81', 'Este CNPJ já pertence a outra empresa.'],
]);

test('a new due day moves every employee to it', function () {
    $user = actingAsRole('financeiro');
    $company = Company::factory()->create(['due_day' => 10]);
    $employees = Customer::factory()->count(2)->forCompany($company)->create();
    $individual = Customer::factory()->create(['due_day' => 10]);

    app(SaveCompany::class)->handle(companyInput(['cnpj' => $company->cnpj, 'due_day' => 25]), $user, $company);

    expect($employees->map(fn (Customer $employee): int => $employee->refresh()->due_day)->all())->toBe([25, 25])
        ->and($individual->refresh()->due_day)->toBe(10);
});

test('saving a company requires customers.update', function () {
    expect(fn () => app(SaveCompany::class)->handle(companyInput(), actingAsRole('atendente')))
        ->toThrow(AuthorizationException::class);

    expect(Company::count())->toBe(0);
});

test('a fixed amount that is missing or not above zero is rejected', function (?string $amount, string $message) {
    expect(fn () => app(SaveCompany::class)->handle(companyInput(['monthly_amount' => $amount]), actingAsRole('financeiro')))
        ->toThrow(ValidationException::class, $message);

    expect(Company::count())->toBe(0);
})->with([
    'missing' => [null, 'O campo valor fixo mensal é obrigatório.'],
    'zero' => ['0,00', 'O valor fixo mensal deve ser maior que zero.'],
    'negative' => ['-10,00', 'O valor fixo mensal deve ser maior que zero, com até 2 casas decimais.'],
]);

test('a new fixed amount applies to the unpaid bills and the paid ones keep theirs', function () {
    $user = actingAsRole('financeiro');
    $company = Company::factory()->create(['monthly_amount' => '1000.00']);
    $employee = Customer::factory()->forCompany($company)->withCard()->create();
    $paidBill = CompanyBill::factory()->for($company)->create(['reference_month' => '2026-09-01']);
    Invoice::factory()->for($employee)->forMonth('2026-09')->paid()->create(['amount' => '0.00']);
    $openBill = CompanyBill::factory()->for($company)->create(['reference_month' => '2026-10-01']);
    Invoice::factory()->for($employee)->forMonth('2026-10')->create(['amount' => '0.00']);

    app(SaveCompany::class)->handle(companyInput(['cnpj' => $company->cnpj, 'monthly_amount' => '1.250,00']), $user, $company);

    expect($company->refresh()->monthly_amount)->toBe('1250.00')
        ->and($openBill->refresh()->amount)->toBe('1250.00')
        ->and($paidBill->refresh()->amount)->toBe('1000.00');
});

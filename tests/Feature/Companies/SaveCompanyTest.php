<?php

use App\Actions\Companies\SaveCompany;
use App\Models\Company;
use App\Models\Customer;
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
        ->and($company->due_day)->toBe(15);
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

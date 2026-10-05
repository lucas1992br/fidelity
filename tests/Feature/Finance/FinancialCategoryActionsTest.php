<?php

use App\Actions\Finance\DeleteFinancialCategory;
use App\Actions\Finance\SaveFinancialCategory;
use App\Enums\EntryType;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use Illuminate\Validation\ValidationException;

test('creates a category with name, type and active flag', function () {
    $category = app(SaveFinancialCategory::class)->handle([
        'name' => '  Consultorias ',
        'entry_type' => 'expense',
        'is_active' => true,
    ]);

    expect($category->refresh()->name)->toBe('Consultorias')
        ->and($category->entry_type_id)->toBe(EntryType::Expense->id())
        ->and($category->is_system)->toBeFalse()
        ->and($category->is_active)->toBeTrue();
});

test('deleting "Mensalidades" fails', function () {
    $monthlyFees = FinancialCategory::monthlyFees();

    expect(fn () => app(DeleteFinancialCategory::class)->handle($monthlyFees))
        ->toThrow(ValidationException::class, 'Categoria de sistema não pode ser excluída.');

    expect(FinancialCategory::whereKey($monthlyFees->id)->exists())->toBeTrue();
});

test('changing the type of "Mensalidades" fails', function () {
    $monthlyFees = FinancialCategory::monthlyFees();

    expect(fn () => app(SaveFinancialCategory::class)->handle([
        'name' => $monthlyFees->name,
        'entry_type' => 'expense',
        'is_active' => true,
    ], $monthlyFees))->toThrow(ValidationException::class, 'Categoria de sistema não pode ter o tipo alterado.');

    expect($monthlyFees->refresh()->entry_type_id)->toBe(EntryType::Income->id());
});

test('a category with entries is not deleted and the message says to deactivate it', function () {
    $category = FinancialCategory::factory()->expense()->create();
    FinancialEntry::factory()->for($category, 'category')->create()->delete();

    expect(fn () => app(DeleteFinancialCategory::class)->handle($category))
        ->toThrow(ValidationException::class, 'Categoria com lançamentos não pode ser excluída; desative-a.');

    expect(FinancialCategory::whereKey($category->id)->exists())->toBeTrue();
});

test('a category with entries does not have the type changed', function () {
    $category = FinancialCategory::factory()->expense()->create();
    FinancialEntry::factory()->for($category, 'category')->create();

    expect(fn () => app(SaveFinancialCategory::class)->handle([
        'name' => $category->name,
        'entry_type' => 'income',
        'is_active' => true,
    ], $category))->toThrow(ValidationException::class, 'Categoria com lançamentos não pode ter o tipo alterado.');

    expect($category->refresh()->entry_type_id)->toBe(EntryType::Expense->id());
});

test('a category without entries is deleted', function () {
    $category = FinancialCategory::factory()->create();

    app(DeleteFinancialCategory::class)->handle($category);

    expect(FinancialCategory::whereKey($category->id)->exists())->toBeFalse();
});

test('an inactive category leaves the options of a new entry', function () {
    $category = FinancialCategory::factory()->expense()->create(['name' => 'Copa']);

    expect(FinancialCategory::optionsFor(EntryType::Expense))->toHaveKey($category->id);

    app(SaveFinancialCategory::class)->handle(['name' => 'Copa', 'entry_type' => 'expense', 'is_active' => false], $category);

    expect(FinancialCategory::optionsFor(EntryType::Expense))->not->toHaveKey($category->id)
        ->and(FinancialCategory::optionsFor(EntryType::Expense, $category->id))->toHaveKey($category->id);
});

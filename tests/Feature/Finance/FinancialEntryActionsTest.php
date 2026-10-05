<?php

use App\Actions\Finance\CreateFinancialEntry;
use App\Actions\Finance\DeleteFinancialEntry;
use App\Actions\Finance\SettleFinancialEntry;
use App\Actions\Finance\UpdateFinancialEntry;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Enums\PaymentMethod;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Valid input of a manual expense in the given category.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function expenseInput(FinancialCategory $category, array $overrides = []): array
{
    return [
        'entry_type' => 'expense',
        'financial_category_id' => $category->id,
        'description' => 'Aluguel de outubro',
        'amount' => '2500.00',
        'due_date' => '2026-10-10',
        'supplier_or_payer' => 'Imobiliária Centro',
        'notes' => null,
        ...$overrides,
    ];
}

test('creates a pending manual expense', function () {
    $user = User::factory()->create();
    $category = FinancialCategory::factory()->expense()->create();

    $entry = app(CreateFinancialEntry::class)->handle(expenseInput($category), $user)->refresh();

    expect($entry->entry_type_id)->toBe(EntryType::Expense->id())
        ->and($entry->entry_status_id)->toBe(EntryStatus::Pending->id())
        ->and($entry->amount)->toBe('2500.00')
        ->and($entry->paid_at)->toBeNull()
        ->and($entry->payment_method_id)->toBeNull()
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->isAutomatic())->toBeFalse();
});

test('an expense with an income category is rejected and asks for a category of the same type', function () {
    $incomeCategory = FinancialCategory::factory()->income()->create();

    try {
        app(CreateFinancialEntry::class)->handle(expenseInput($incomeCategory), User::factory()->create());
        $this->fail('The expense was accepted with an income category.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('financial_category_id')
            ->and($exception->errors()['financial_category_id'][0])->toBe('Escolha uma categoria de despesa ativa.');
    }

    expect(FinancialEntry::count())->toBe(0);
});

test('an inactive category is rejected for a new entry', function () {
    $category = FinancialCategory::factory()->expense()->inactive()->create();

    app(CreateFinancialEntry::class)->handle(expenseInput($category), User::factory()->create());
})->throws(ValidationException::class, 'Escolha uma categoria de despesa ativa.');

test('zero and negative amounts are rejected', function (string $amount) {
    app(CreateFinancialEntry::class)->handle(expenseInput(FinancialCategory::factory()->create(), ['amount' => $amount]), User::factory()->create());
})->throws(ValidationException::class)->with(['0.00', '-10.00']);

test('creating an already paid entry stores paid_at and the payment method', function () {
    $category = FinancialCategory::factory()->expense()->create();

    $entry = app(CreateFinancialEntry::class)->handle(expenseInput($category, [
        'is_paid' => true,
        'paid_at' => '2026-10-03',
        'payment_method' => 'pix',
    ]), User::factory()->create())->refresh();

    expect($entry->entry_status_id)->toBe(EntryStatus::Paid->id())
        ->and($entry->paid_at->toDateString())->toBe('2026-10-03')
        ->and($entry->payment_method_id)->toBe(PaymentMethod::Pix->id());
});

test('editing or deleting an automatic entry fails even for the admin', function (string $action) {
    actingAsRole('admin');
    $entry = FinancialEntry::factory()->automatic()->create();
    $amount = $entry->amount;

    expect(fn () => $action === 'update'
        ? app(UpdateFinancialEntry::class)->handle($entry, ['entry_type' => 'income', 'financial_category_id' => $entry->financial_category_id, 'description' => 'Alterado', 'amount' => '1.00', 'due_date' => '2026-10-01'])
        : app(DeleteFinancialEntry::class)->handle($entry)
    )->toThrow(ValidationException::class);

    expect($entry->refresh()->trashed())->toBeFalse()
        ->and($entry->amount)->toBe($amount);
})->with(['update', 'delete']);

test('editing a manual entry keeps its category valid even after it was deactivated', function () {
    $category = FinancialCategory::factory()->expense()->create();
    $entry = FinancialEntry::factory()->for($category, 'category')->create();
    $category->update(['is_active' => false]);

    app(UpdateFinancialEntry::class)->handle($entry, expenseInput($category, ['description' => 'Aluguel corrigido', 'amount' => '2600.00']));

    expect($entry->refresh()->description)->toBe('Aluguel corrigido')
        ->and($entry->amount)->toBe('2600.00');
});

test('deleting a manual entry soft deletes it', function () {
    $entry = FinancialEntry::factory()->create();

    app(DeleteFinancialEntry::class)->handle($entry);

    expect($entry->refresh()->trashed())->toBeTrue()
        ->and(FinancialEntry::withTrashed()->whereKey($entry->id)->exists())->toBeTrue();
});

test('settling a pending entry makes it paid', function () {
    $entry = FinancialEntry::factory()->pending()->create();

    app(SettleFinancialEntry::class)->handle($entry, ['paid_at' => '2026-10-05', 'payment_method' => 'cash']);

    expect($entry->refresh()->entry_status_id)->toBe(EntryStatus::Paid->id())
        ->and($entry->paid_at->toDateString())->toBe('2026-10-05')
        ->and($entry->payment_method_id)->toBe(PaymentMethod::Cash->id());
});

test('a second settlement fails', function () {
    $entry = FinancialEntry::factory()->pending()->create();
    app(SettleFinancialEntry::class)->handle($entry, ['paid_at' => '2026-10-05', 'payment_method' => 'cash']);

    expect(fn () => app(SettleFinancialEntry::class)->handle($entry, ['paid_at' => '2026-10-06', 'payment_method' => 'pix']))
        ->toThrow(ValidationException::class, 'Este lançamento já foi baixado ou cancelado.');

    expect($entry->refresh()->paid_at->toDateString())->toBe('2026-10-05');
});

test('a cancelled entry cannot be settled', function () {
    $entry = FinancialEntry::factory()->cancelled()->create();

    app(SettleFinancialEntry::class)->handle($entry, ['paid_at' => '2026-10-05', 'payment_method' => 'cash']);
})->throws(ValidationException::class, 'Este lançamento já foi baixado ou cancelado.');

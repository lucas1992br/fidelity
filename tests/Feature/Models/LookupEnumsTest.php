<?php

use App\Enums\CardStatus as CardStatusEnum;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Enums\Kinship;
use App\Enums\PaymentMethod;
use App\Models\CardStatus;
use App\Models\InvoiceStatus;
use Illuminate\Support\Facades\DB;

dataset('lookup enums', [
    'card statuses' => [CardStatusEnum::class, 'card_statuses'],
    'invoice statuses' => [InvoiceStatusEnum::class, 'invoice_statuses'],
    'payment methods' => [PaymentMethod::class, 'payment_methods'],
    'kinships' => [Kinship::class, 'kinships'],
    'entry types' => [EntryType::class, 'entry_types'],
    'entry statuses' => [EntryStatus::class, 'entry_statuses'],
]);

test('enum cases mirror the seeded lookup slugs', function (string $enum, string $table) {
    $values = array_map(fn (BackedEnum $case): string => $case->value, $enum::cases());

    expect($values)->toBe(DB::table($table)->orderBy('id')->pluck('slug')->all())
        ->and($enum::lookupTable())->toBe($table);
})->with('lookup enums');

test('enum labels match the lookup names', function (string $enum, string $table) {
    $labels = collect($enum::cases())->mapWithKeys(fn (BackedEnum $case): array => [$case->value => $case->label()])->all();

    expect($labels)->toBe(DB::table($table)->orderBy('id')->pluck('name', 'slug')->all());
})->with('lookup enums');

test('enum colors match the lookup colors', function (string $enum, string $table) {
    $colors = collect($enum::cases())->mapWithKeys(fn (BackedEnum $case): array => [$case->value => $case->color()])->all();

    expect($colors)->toBe(DB::table($table)->orderBy('id')->pluck('color', 'slug')->all());
})->with([
    'card statuses' => [CardStatusEnum::class, 'card_statuses'],
    'invoice statuses' => [InvoiceStatusEnum::class, 'invoice_statuses'],
    'entry statuses' => [EntryStatus::class, 'entry_statuses'],
]);

test('id resolves the lookup row by slug and fromId goes back', function (string $enum, string $table) {
    foreach ($enum::cases() as $case) {
        $id = DB::table($table)->where('slug', $case->value)->value('id');

        expect($case->id())->toBe($id)
            ->and($enum::fromId($id))->toBe($case);
    }
})->with('lookup enums');

test('card status active id is the id of the active row', function () {
    expect(CardStatusEnum::Active->id())->toBe(DB::table('card_statuses')->where('slug', 'active')->value('id'));
});

test('fromId rejects an id that is not in the lookup', function () {
    EntryType::fromId(999_999);
})->throws(UnexpectedValueException::class);

test('lookup models cast the slug to the enum and filter active rows', function () {
    $lostId = CardStatusEnum::Lost->id();
    CardStatus::whereKey($lostId)->update(['is_active' => false]);

    $active = CardStatus::find(CardStatusEnum::Active->id());

    expect($active->slug)->toBe(CardStatusEnum::Active)
        ->and($active->is_current)->toBeTrue()
        ->and($active->is_reusable)->toBeFalse()
        ->and(CardStatus::active()->pluck('id'))->not->toContain($lostId)->toHaveCount(5)
        ->and(InvoiceStatus::where('is_payable', true)->get()->pluck('slug')->all())->toBe([InvoiceStatusEnum::Open, InvoiceStatusEnum::Overdue]);
});

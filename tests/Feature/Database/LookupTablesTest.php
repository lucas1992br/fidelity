<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('lookup flags default to false and is_active defaults to true', function (string $table, array $row, array $falseFlags) {
    $id = DB::table($table)->insertGetId([...$row, 'slug' => 'sample', 'name' => 'Sample']);
    $record = DB::table($table)->find($id);

    expect($record->is_active)->toBeTrue();

    foreach ($falseFlags as $flag) {
        expect($record->{$flag})->toBeFalse();
    }
})->with([
    'card_statuses' => ['card_statuses', ['color' => 'blue'], ['is_current', 'is_reusable']],
    'invoice_statuses' => ['invoice_statuses', ['color' => 'blue'], ['is_payable']],
    'payment_methods' => ['payment_methods', [], []],
    'kinships' => ['kinships', [], []],
    'entry_types' => ['entry_types', [], []],
    'entry_statuses' => ['entry_statuses', ['color' => 'blue'], []],
]);

test('lookup slugs are unique', function () {
    DB::table('kinships')->insert(['name' => 'Outro', 'slug' => 'other']);
    DB::table('kinships')->insert(['name' => 'Outro 2', 'slug' => 'other']);
})->throws(UniqueConstraintViolationException::class);

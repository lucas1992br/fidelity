<?php

use App\Exceptions\PhysicalDeleteNotAllowed;
use App\Models\FinancialEntry;
use App\Models\Payment;

test('deleting a payment throws and keeps it in the database', function () {
    $payment = Payment::factory()->create();

    expect(fn () => $payment->delete())->toThrow(PhysicalDeleteNotAllowed::class);

    $this->assertDatabaseHas('payments', ['id' => $payment->id]);
});

test('force deleting a financial entry throws and keeps it in the database', function () {
    $entry = FinancialEntry::factory()->create();

    expect(fn () => $entry->forceDelete())->toThrow(PhysicalDeleteNotAllowed::class);

    $this->assertDatabaseHas('financial_entries', ['id' => $entry->id, 'deleted_at' => null]);
});

test('a manual financial entry can still be soft deleted', function () {
    $entry = FinancialEntry::factory()->create();

    $entry->delete();

    $this->assertSoftDeleted($entry);
});

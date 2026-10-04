<?php

use App\Models\Card;
use App\Models\Customer;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Policies\CardPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\FinancialEntryPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
use Illuminate\Support\Facades\Gate;

/**
 * Every policy action: [ability, model, whether the check needs a persisted
 * model instead of the class name, allowed for admin, financeiro, atendente].
 *
 * @return array<string, array{string, class-string, bool, bool, bool, bool}>
 */
function policyMatrix(): array
{
    return [
        'customer viewAny' => ['viewAny', Customer::class, false, true, true, false],
        'customer view' => ['view', Customer::class, true, true, true, false],
        'customer create' => ['create', Customer::class, false, true, true, true],
        'customer update' => ['update', Customer::class, true, true, true, false],
        'customer export' => ['export', Customer::class, true, true, true, false],
        'card check' => ['check', Card::class, false, true, true, true],
        'card manage' => ['manage', Card::class, false, true, true, false],
        'card viewStock' => ['viewStock', Card::class, false, true, true, false],
        'card manageStock' => ['manageStock', Card::class, false, true, true, false],
        'invoice viewAny' => ['viewAny', Invoice::class, false, true, true, false],
        'invoice view' => ['view', Invoice::class, true, true, true, false],
        'invoice pay' => ['pay', Invoice::class, true, true, true, false],
        'payment viewReceipt' => ['viewReceipt', Payment::class, true, true, true, false],
        'payment refund' => ['refund', Payment::class, true, true, false, false],
        'entry viewAny' => ['viewAny', FinancialEntry::class, false, true, true, false],
        'entry create' => ['create', FinancialEntry::class, false, true, true, false],
        'entry settle' => ['settle', FinancialEntry::class, true, true, true, false],
        'manual entry update' => ['update', FinancialEntry::class, true, true, true, false],
        'manual entry delete' => ['delete', FinancialEntry::class, true, true, true, false],
    ];
}

test('each policy action maps to its permission', function (string $role, string $ability, string $model, bool $needsInstance, bool $allowed) {
    $user = actingAsRole($role);
    $subject = $needsInstance ? $model::factory()->create() : $model;

    expect(Gate::forUser($user)->allows($ability, $subject))->toBe($allowed);
})->with(function (): array {
    $cases = [];

    foreach (policyMatrix() as $action => [$ability, $model, $needsInstance, $admin, $financeiro, $atendente]) {
        foreach (['admin' => $admin, 'financeiro' => $financeiro, 'atendente' => $atendente] as $role => $allowed) {
            $cases["{$role} · {$action}"] = [$role, $ability, $model, $needsInstance, $allowed];
        }
    }

    return $cases;
});

test('the policies are discovered for their models', function (string $model, string $policy) {
    expect(Gate::getPolicyFor($model))->toBeInstanceOf($policy);
})->with([
    [Customer::class, CustomerPolicy::class],
    [Card::class, CardPolicy::class],
    [Invoice::class, InvoicePolicy::class],
    [Payment::class, PaymentPolicy::class],
    [FinancialEntry::class, FinancialEntryPolicy::class],
]);

test('a refunded payment cannot be refunded again, even by the admin', function () {
    $admin = actingAsRole('admin');
    $payment = Payment::factory()->refunded()->create();

    expect(Gate::forUser($admin)->allows('refund', $payment))->toBeFalse();
});

test('an automatic entry cannot be updated or deleted, even by the admin', function (string $role) {
    $user = actingAsRole($role);
    $entry = FinancialEntry::factory()->automatic()->create();

    expect(Gate::forUser($user)->allows('update', $entry))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $entry))->toBeFalse()
        ->and(Gate::forUser($user)->allows('settle', $entry))->toBeTrue();
})->with(['admin', 'financeiro']);

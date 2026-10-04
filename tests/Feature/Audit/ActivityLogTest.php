<?php

use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use Spatie\Activitylog\Models\Activity;

test('updating only the customer phone logs just that field with the user as causer', function () {
    $customer = Customer::factory()->create(['phone' => '11911111111']);
    $user = actingAsRole('atendente');

    $customer->update(['phone' => '11922222222']);

    $activity = Activity::query()->where('event', 'updated')->sole();

    expect($activity->log_name)->toBe('customers')
        ->and($activity->subject->is($customer))->toBeTrue()
        ->and($activity->causer->is($user))->toBeTrue()
        ->and($activity->attribute_changes->toArray())->toBe([
            'attributes' => ['phone' => '11922222222'],
            'old' => ['phone' => '11911111111'],
        ]);
});

test('saving a model without changes does not log', function () {
    $customer = Customer::factory()->create();
    actingAsRole('atendente');
    $logged = Activity::count();

    $customer->save();
    $customer->update(['phone' => $customer->phone]);

    expect(Activity::count())->toBe($logged);
});

test('changes made by the routines have no causer', function () {
    $customer = Customer::factory()->create();

    $customer->update(['phone' => '11933333333']);

    expect(Activity::query()->where('event', 'updated')->sole()->causer)->toBeNull();
});

test('main models are audited in the log of their area', function (Closure $makeSubject, string $logName) {
    $subject = $makeSubject();

    expect($subject->activitiesAsSubject()->where('event', 'created')->sole()->log_name)->toBe($logName);
})->with([
    'customer' => [fn () => Customer::factory()->create(), 'customers'],
    'dependent' => [fn () => Dependent::factory()->create(), 'customers'],
    'plan' => [fn () => Plan::factory()->create(), 'customers'],
    'card' => [fn () => Card::factory()->create(), 'cards'],
    'invoice' => [fn () => Invoice::factory()->create(), 'billing'],
    'payment' => [fn () => Payment::factory()->create(), 'billing'],
    'financial entry' => [fn () => FinancialEntry::factory()->create(), 'finance'],
]);

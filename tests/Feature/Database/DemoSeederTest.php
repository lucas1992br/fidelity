<?php

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Settings\ClinicSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

function seedDatabaseIn(string $environment): void
{
    app()->detectEnvironment(fn () => $environment);

    test()->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
}

test('the demo seeder runs in local and its data follows the domain rules', function () {
    seedDatabaseIn('local');

    $customersWithManyCurrentCards = Card::current()->groupBy('customer_id')->havingRaw('count(*) > 1')->pluck('customer_id');
    $activePayments = Payment::query()->whereNull('refunded_at')->withCount('financialEntry')->get();
    $dependentsOverLimit = Customer::query()->with('plan')->withCount('dependents')->get()
        ->filter(fn (Customer $customer): bool => $customer->dependents_count > $customer->plan->max_dependents);
    $customersPastSuspensionLimit = Invoice::overdue()
        ->whereDate('due_date', '<', today()->subDays(app(ClinicSettings::class)->suspend_after_days))
        ->distinct()->pluck('customer_id')->sort()->values()->all();
    $suspendedCustomers = Card::withStatus(CardStatusEnum::Suspended)->orderBy('customer_id')->pluck('customer_id')->all();
    $sequencesWithGaps = Dependent::query()->get()->groupBy('customer_id')
        ->reject(fn ($dependents): bool => $dependents->pluck('sequence')->sort()->values()->all() === range(1, $dependents->count()));

    expect(Customer::count())->toBe(50)
        ->and($customersWithManyCurrentCards)->toBeEmpty()
        ->and(Card::current()->count())->toBe(46)
        ->and(Card::withStatus(CardStatusEnum::Suspended)->count())->toBeGreaterThan(0)
        ->and(Card::withStatus(CardStatusEnum::Cancelled)->count())->toBeGreaterThan(0)
        ->and($activePayments)->not->toBeEmpty()
        ->and($activePayments->every(fn (Payment $payment): bool => $payment->financial_entry_count === 1))->toBeTrue()
        ->and(Invoice::whereNotNull('paid_at')->count())->toBe($activePayments->count())
        ->and(Invoice::overdue()->count())->toBeGreaterThan(0)
        ->and($customersPastSuspensionLimit)->toBe($suspendedCustomers)
        ->and($dependentsOverLimit)->toBeEmpty()
        ->and(Dependent::count())->toBeGreaterThan(0)
        ->and($sequencesWithGaps)->toBeEmpty()
        ->and(Card::inStock()->count())->toBe(100)
        ->and(DB::table('card_status_logs')->whereIn('card_id', Card::inStock()->select('id'))->count())->toBe(100)
        ->and(User::role('financeiro')->where('email', 'financeiro@demo.test')->exists())->toBeTrue()
        ->and(User::role('atendente')->where('email', 'atendente@demo.test')->exists())->toBeTrue()
        ->and(DB::table('financial_entries')->whereNull('payment_id')->count())->toBeGreaterThan(0);
});

test('the demo seeder does not run in production', function () {
    seedDatabaseIn('production');

    expect(DB::table('plans')->count())->toBe(2)
        ->and(Customer::count())->toBe(0)
        ->and(Card::count())->toBe(0)
        ->and(User::where('email', 'like', '%@demo.test')->exists())->toBeFalse();
});

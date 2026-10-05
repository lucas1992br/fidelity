<?php

use App\Actions\Billing\RefundPayment;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\CashFlowReport;

beforeEach(function () {
    $this->travelTo('2026-10-05 10:00');
});

/**
 * Paid entry of the given type on the given payment date.
 */
function paidEntry(string $type, string $paidAt, string $amount): FinancialEntry
{
    return FinancialEntry::factory()->{$type}()->paid()->create(['due_date' => $paidAt, 'paid_at' => $paidAt, 'amount' => $amount]);
}

test('the opening balance only counts payments before the period', function () {
    paidEntry('income', '2026-09-30', '500.00');
    paidEntry('expense', '2026-09-15', '120.25');
    paidEntry('income', '2026-10-01', '1000.00');
    FinancialEntry::factory()->income()->pending()->create(['due_date' => '2026-09-10', 'amount' => '999.00']);

    $report = app(CashFlowReport::class)->daily(2026, 10);

    expect($report['opening_balance'])->toBe('379.75')
        ->and($report['rows'])->toHaveCount(31)
        ->and($report['rows'][0])->toMatchArray(['key' => '2026-10-01', 'income' => '1000.00', 'expense' => '0.00', 'balance' => '1000.00', 'cumulative' => '1379.75']);
});

test('the cumulative balance of day 3 is the opening balance plus the balances of days 1 to 3', function () {
    paidEntry('income', '2026-09-20', '200.00');
    paidEntry('income', '2026-10-01', '150.00');
    paidEntry('expense', '2026-10-01', '50.00');
    paidEntry('expense', '2026-10-02', '80.10');
    paidEntry('income', '2026-10-03', '30.00');
    paidEntry('income', '2026-10-03', '0.45');
    paidEntry('income', '2026-10-04', '700.00');

    $rows = app(CashFlowReport::class)->daily(2026, 10)['rows'];

    expect(array_column(array_slice($rows, 0, 3), 'balance'))->toBe(['100.00', '-80.10', '30.45'])
        ->and($rows[2])->toMatchArray(['key' => '2026-10-03', 'income' => '30.45', 'expense' => '0.00', 'cumulative' => '250.35'])
        ->and($rows[3]['cumulative'])->toBe('950.35');
});

test('cancelled and soft-deleted entries are ignored, such as the income of a refunded payment', function () {
    $payment = Payment::factory()->create(['paid_at' => '2026-10-02']);
    $automatic = FinancialEntry::factory()->automatic($payment)->create();
    app(RefundPayment::class)->handle($payment, 'Pagamento em duplicidade', actingAsRole('admin'));
    paidEntry('expense', '2026-10-02', '40.00')->delete();
    paidEntry('income', '2026-10-02', '10.00');

    $report = app(CashFlowReport::class)->daily(2026, 10);

    expect($automatic->refresh()->paid_at->toDateString())->toBe('2026-10-02')
        ->and($report['rows'][1])->toMatchArray(['income' => '10.00', 'expense' => '0.00'])
        ->and($report['total_income'])->toBe('10.00')
        ->and($report['total_expense'])->toBe('0.00');
});

test('the monthly view aggregates each of the 12 months of the year', function () {
    paidEntry('income', '2025-12-31', '100.00');
    paidEntry('income', '2026-01-10', '300.00');
    paidEntry('income', '2026-01-25', '200.00');
    paidEntry('expense', '2026-03-05', '150.00');

    $report = app(CashFlowReport::class)->monthly(2026);

    expect($report['opening_balance'])->toBe('100.00')
        ->and(array_column($report['rows'], 'key'))->toBe(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'])
        ->and($report['rows'][0])->toMatchArray(['income' => '500.00', 'expense' => '0.00', 'cumulative' => '600.00'])
        ->and($report['rows'][2])->toMatchArray(['income' => '0.00', 'expense' => '150.00', 'balance' => '-150.00', 'cumulative' => '450.00'])
        ->and($report['closing_balance'])->toBe('450.00');
});

test('the forecast includes pending expenses and open invoices by due date', function () {
    FinancialEntry::factory()->expense()->pending()->create(['due_date' => '2026-10-15', 'amount' => '250.00']);
    FinancialEntry::factory()->income()->pending()->create(['due_date' => '2026-10-15', 'amount' => '60.00']);
    FinancialEntry::factory()->expense()->cancelled()->create(['due_date' => '2026-10-15', 'amount' => '999.00']);
    Invoice::factory()->create(['due_date' => '2026-10-15', 'amount' => '89.90']);
    Invoice::factory()->paid()->create(['due_date' => '2026-10-15', 'amount' => '77.00']);

    $report = app(CashFlowReport::class)->daily(2026, 10, withForecast: true);
    $day = $report['rows'][14];

    expect($day)->toMatchArray(['key' => '2026-10-15', 'income' => '0.00', 'expense' => '0.00', 'forecast_income' => '149.90', 'forecast_expense' => '250.00'])
        ->and($report['rows'][13]['forecast_income'])->toBe('0.00')
        ->and(app(CashFlowReport::class)->monthly(2026, withForecast: true)['rows'][9])->toMatchArray(['forecast_income' => '149.90', 'forecast_expense' => '250.00'])
        ->and(app(CashFlowReport::class)->daily(2026, 10)['rows'][14]['forecast_income'])->toBeNull();
});

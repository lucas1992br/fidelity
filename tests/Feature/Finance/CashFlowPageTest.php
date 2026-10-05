<?php

use App\Models\FinancialEntry;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-05 10:00');
});

test('shows the daily view of the month and switching to monthly shows 12 rows', function () {
    actingAsRole('financeiro');
    FinancialEntry::factory()->income()->paid()->create(['due_date' => '2026-09-30', 'paid_at' => '2026-09-30', 'amount' => '500.00']);
    FinancialEntry::factory()->expense()->paid()->create(['due_date' => '2026-10-02', 'paid_at' => '2026-10-02', 'amount' => '120.00']);

    $page = Livewire::test('pages::finance.cash-flow')
        ->assertSet('month', '2026-10')
        ->assertSee(['Saldo inicial', 'R$ 500,00', 'R$ 380,00'])
        ->assertViewHas('report', fn (array $report): bool => count($report['rows']) === 31);

    expect(substr_count($page->html(), 'data-row="'))->toBe(31);

    $page->call('$set', 'mode', 'monthly')
        ->assertViewHas('report', fn (array $report): bool => count($report['rows']) === 12 && $report['opening_balance'] === '0.00');

    expect(substr_count($page->html(), 'data-row="'))->toBe(12);
});

test('showing the forecast adds the forecast columns and the chart line', function () {
    actingAsRole('financeiro');
    FinancialEntry::factory()->expense()->pending()->create(['due_date' => '2026-10-15', 'amount' => '250.00']);

    Livewire::test('pages::finance.cash-flow')
        ->assertDontSee('Previsto saídas')
        ->set('forecast', true)
        ->assertSee(['Previsto saídas', 'R$ 250,00'])
        ->assertSeeHtml('Previsto (saldo)');
});

test('atendente gets 403 and the cash flow route renders the page', function () {
    actingAsRole('atendente');
    Livewire::test('pages::finance.cash-flow')->assertForbidden();

    actingAsRole('financeiro');
    $this->withoutVite()->get(route('finance.cash-flow', ['view' => 'monthly', 'year' => '2026']))->assertOk()->assertSee('Saldo acumulado');
});

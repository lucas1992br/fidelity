<?php

use App\Models\Card;
use App\Models\CardBatch;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the low stock alert shows with 38 in stock below a minimum of 50', function () {
    actingAsRole('financeiro');
    settings(['min_card_stock' => 50]);
    Card::factory()->count(38)->inStock()->create();

    Livewire::test('pages::cards.stock')
        ->assertViewHas('isLowStock', true)
        ->assertSee('Abaixo do mínimo de 50. Peça um novo lote.');
});

test('the low stock alert is hidden with 60 in stock', function () {
    actingAsRole('financeiro');
    settings(['min_card_stock' => 50]);
    Card::factory()->count(60)->inStock()->create();

    Livewire::test('pages::cards.stock')
        ->assertViewHas('isLowStock', false)
        ->assertDontSee('Abaixo do mínimo');
});

test('the summary counts linked cards with the active and suspended breakdown', function () {
    actingAsRole('financeiro');
    Card::factory()->count(3)->active()->create();
    Card::factory()->suspended()->create();
    Card::factory()->lost()->create();
    Card::factory()->discarded()->create();

    Livewire::test('pages::cards.stock')
        ->assertViewHas('counts', fn (array $counts): bool => $counts['active'] === 3 && $counts['suspended'] === 1)
        ->assertSee('3 ativos · 1 suspenso')
        ->assertSee('Não voltam ao estoque');
});

test('the status filter in_stock lists only stock cards', function () {
    actingAsRole('financeiro');
    Card::factory()->inStock()->create(['number' => 'STK-001']);
    Card::factory()->active()->for(Customer::factory()->state(['name' => 'Maria Aparecida']))->create(['number' => 'ACT-001']);
    Card::factory()->discarded()->create(['number' => 'DSC-001']);

    Livewire::withQueryParams(['status' => 'in_stock'])
        ->test('pages::cards.stock')
        ->assertSet('status', 'in_stock')
        ->assertSee('STK-001')
        ->assertDontSee('ACT-001')
        ->assertDontSee('DSC-001')
        ->set('status', '')
        ->assertSee('ACT-001')
        ->assertSee('Maria Aparecida');
});

test('the batch and number filters narrow the list', function () {
    actingAsRole('financeiro');
    $batch = CardBatch::factory()->create();
    Card::factory()->for($batch, 'batch')->create(['number' => '000501']);
    Card::factory()->create(['number' => '000777']);

    Livewire::test('pages::cards.stock')
        ->set('batch', (string) $batch->id)
        ->assertSee('000501')
        ->assertDontSee('000777')
        ->set('batch', '')
        ->set('number', '777')
        ->assertSee('000777')
        ->assertDontSee('000501');
});

test('the discard action shows only on stock cards and for cards.stock.manage', function () {
    actingAsRole('financeiro');
    $stockCard = Card::factory()->inStock()->create();
    $activeCard = Card::factory()->active()->create();

    Livewire::test('pages::cards.stock')
        ->assertSeeHtml("open-discard-card', { id: {$stockCard->id} }")
        ->assertDontSeeHtml("open-discard-card', { id: {$activeCard->id} }");
});

test('the cards table runs a constant number of queries', function () {
    actingAsRole('financeiro');
    $batch = CardBatch::factory()->create();
    Card::factory()->count(5)->for($batch, 'batch')->inStock()->create();
    Card::factory()->count(5)->for($batch, 'batch')->active()->create();

    Livewire::test('pages::cards.stock');

    DB::enableQueryLog();
    Livewire::test('pages::cards.stock');
    $fewCards = count(DB::getQueryLog());

    Card::factory()->count(10)->for($batch, 'batch')->active()->create();

    DB::flushQueryLog();
    Livewire::test('pages::cards.stock');

    expect(count(DB::getQueryLog()))->toBe($fewCards);
});

test('the batches tab shows the range and the usage', function () {
    actingAsRole('financeiro');
    $batch = CardBatch::factory()->create(['description' => 'Lote gráfica set/2026', 'first_number' => '000172', 'last_number' => '000175']);
    Card::factory()->for($batch, 'batch')->active()->create();
    Card::factory()->count(2)->for($batch, 'batch')->inStock()->create();
    Card::factory()->for($batch, 'batch')->discarded()->create();

    Livewire::withQueryParams(['tab' => 'batches'])
        ->test('pages::cards.stock')
        ->assertSee('Lote gráfica set/2026')
        ->assertSee('000172 – 000175')
        ->assertSee('1 vinculado · 2 disponíveis · 1 inutilizado');
});

test('atendente gets 403', function () {
    actingAsRole('atendente');

    Livewire::test('pages::cards.stock')->assertForbidden();

    $this->withoutVite()->get(route('cards.stock'))->assertForbidden();
});

test('the stock route renders the page', function () {
    actingAsRole('financeiro');

    $this->withoutVite()->get(route('cards.stock'))
        ->assertOk()
        ->assertSee('Em estoque')
        ->assertSee('Cadastrar lote');
});

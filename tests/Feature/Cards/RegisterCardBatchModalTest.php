<?php

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardBatch;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

test('the range preview lists valid and ignored numbers and confirming registers the batch', function () {
    actingAsRole('financeiro');
    Card::factory()->create(['number' => '000002']);

    Livewire::test('cards.register-batch-modal')
        ->call('open')
        ->assertSet('received_at', today()->toDateString())
        ->set('description', 'Lote gráfica out/2026')
        ->set('supplier', 'Gráfica Impressul')
        ->set('start', 1)
        ->set('end', 3)
        ->set('digits', 6)
        ->call('previewBatch')
        ->assertHasNoErrors()
        ->assertSet('preview.valid_count', 2)
        ->assertSee('000001, 000003')
        ->assertSee('Já cadastrado no estoque')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('isOpen', false)
        ->assertDispatched('notify')
        ->assertDispatched('card-stock-updated');

    $batch = CardBatch::sole();

    expect($batch->quantity)->toBe(2)
        ->and($batch->supplier)->toBe('Gráfica Impressul')
        ->and($batch->cards()->pluck('number')->sort()->values()->all())->toBe(['000001', '000003']);
});

test('confirm is disabled until a preview of the current input exists', function () {
    actingAsRole('financeiro');

    Livewire::test('cards.register-batch-modal')
        ->call('open')
        ->set('description', 'Lote')
        ->set('start', 1)
        ->set('end', 3)
        ->call('previewBatch')
        ->assertSet('preview.valid_count', 3)
        ->assertDontSeeHtml('disabled="disabled"')
        ->set('end', 5)
        ->assertSet('preview', null)
        ->assertSeeHtml('disabled="disabled"')
        ->call('confirm');

    expect(CardBatch::count())->toBe(0);
});

test('the range errors show on the fields', function () {
    actingAsRole('financeiro');

    Livewire::test('cards.register-batch-modal')
        ->call('open')
        ->set('start', 10)
        ->set('end', 5)
        ->call('previewBatch')
        ->assertHasErrors('start')
        ->assertSee('O número inicial deve ser menor ou igual ao final.')
        ->assertSet('preview', null);
});

test('the list mode registers numbers as printed', function () {
    actingAsRole('financeiro');

    Livewire::test('cards.register-batch-modal')
        ->call('open')
        ->set('description', 'Lote avulso')
        ->call('setMode', 'list')
        ->set('numbers', " AB-001 \nAB-002\nAB-001\n")
        ->call('previewBatch')
        ->assertSet('preview.valid_count', 2)
        ->assertSee('Repetido na lista')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(Card::inStock()->pluck('number')->sort()->values()->all())->toBe(['AB-001', 'AB-002']);
});

test('the csv mode reads the uploaded file', function () {
    actingAsRole('financeiro');
    $file = UploadedFile::fake()->createWithContent('cartoes.csv', "numero;obs\n000901;ok\n000902;ok\n");

    Livewire::test('cards.register-batch-modal')
        ->call('open')
        ->set('description', 'Lote CSV')
        ->call('setMode', 'csv')
        ->set('csv', $file)
        ->call('previewBatch')
        ->assertHasNoErrors()
        ->assertSet('preview.valid_count', 2)
        ->call('confirm')
        ->assertHasNoErrors();

    expect(Card::withStatus(CardStatus::InStock)->pluck('number')->sort()->values()->all())->toBe(['000901', '000902']);
});

test('the csv mode rejects other file types', function () {
    actingAsRole('financeiro');

    Livewire::test('cards.register-batch-modal')
        ->call('open')
        ->call('setMode', 'csv')
        ->set('csv', UploadedFile::fake()->image('cartoes.png'))
        ->call('previewBatch')
        ->assertHasErrors('csv');
});

test('the stock page opens the modal for cards.stock.manage', function () {
    actingAsRole('financeiro');

    Livewire::test('pages::cards.stock')
        ->assertSeeLivewire('cards.register-batch-modal');
});

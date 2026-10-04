<?php

use App\Actions\Cards\RegisterCardBatch;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardBatch;
use App\Models\CardStatusLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function batchInput(array $overrides = []): array
{
    return [
        'description' => 'Lote gráfica out/2026',
        'supplier' => 'Gráfica Impressul',
        'received_at' => today()->toDateString(),
        'mode' => 'range',
        'prefix' => '',
        'start' => 1,
        'end' => 10,
        'digits' => 6,
        ...$overrides,
    ];
}

test('a range of 10 with 2 existing creates 8 stock cards, quantity 8 and 8 entry logs', function () {
    $user = actingAsRole('financeiro');
    Card::factory()->create(['number' => '000003']);
    Card::factory()->active()->create(['number' => '000007']);

    $batch = app(RegisterCardBatch::class)->handle($user, batchInput());

    $cards = $batch->cards()->get();

    expect($batch->quantity)->toBe(8)
        ->and($batch->user_id)->toBe($user->id)
        ->and($batch->first_number)->toBe('000001')
        ->and($batch->last_number)->toBe('000010')
        ->and($cards)->toHaveCount(8)
        ->and($cards->pluck('number')->all())->not->toContain('000003', '000007')
        ->and($cards->every(fn (Card $card): bool => $card->hasStatus(CardStatus::InStock) && $card->customer_id === null && ! $card->is_current))->toBeTrue();

    $logs = CardStatusLog::whereIn('card_id', $cards->pluck('id'))->get();

    expect($logs)->toHaveCount(8)
        ->and($logs->every(fn (CardStatusLog $log): bool => $log->from_card_status_id === null
            && $log->to_card_status_id === CardStatus::InStock->id()
            && $log->user_id === $user->id))->toBeTrue();
});

test('a list without valid numbers does not create a batch', function () {
    $user = actingAsRole('financeiro');
    Card::factory()->create(['number' => 'X-1']);

    expect(fn () => app(RegisterCardBatch::class)->handle($user, batchInput(['mode' => 'list', 'numbers' => "X-1\nX-1"])))
        ->toThrow(ValidationException::class, 'Nenhum número válido para cadastrar');

    expect(CardBatch::count())->toBe(0)
        ->and(Card::count())->toBe(1);
});

test('description and received date are required', function () {
    $user = actingAsRole('financeiro');

    expect(fn () => app(RegisterCardBatch::class)->handle($user, batchInput(['description' => '', 'received_at' => ''])))
        ->toThrow(ValidationException::class);

    expect(CardBatch::count())->toBe(0);
});

test('a user without cards.stock.manage cannot run the action', function () {
    $user = User::factory()->create();
    $user->assignRole('atendente');

    expect(fn () => app(RegisterCardBatch::class)->handle($user, batchInput()))
        ->toThrow(AuthorizationException::class);

    expect(CardBatch::count())->toBe(0);
});

test('a user without cards.stock.manage cannot run it through the component', function (string $method) {
    actingAsRole('financeiro');
    $component = Livewire::test('cards.register-batch-modal')
        ->call('open')
        ->set('description', 'Lote gráfica out/2026')
        ->set('start', 1)
        ->set('end', 10)
        ->call('previewBatch');

    actingAsRole('atendente');

    $component->call($method)->assertForbidden();

    expect(CardBatch::count())->toBe(0)
        ->and(Card::count())->toBe(0);
})->with(['open', 'previewBatch', 'confirm']);

<?php

namespace App\Actions\Cards;

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\CardBatch;
use App\Models\CardStatusLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Register a batch of printed cards into stock. The preview is rebuilt on the
 * server inside the transaction, so numbers registered meanwhile are skipped;
 * each valid card enters `in_stock`, unassigned, with an entry log.
 */
class RegisterCardBatch
{
    public function __construct(protected BuildCardBatchPreview $buildPreview) {}

    /**
     * @param  array{description?: string, supplier?: string|null, received_at?: string, mode?: string, prefix?: string|null, start?: int|string|null, end?: int|string|null, digits?: int|string|null, numbers?: string|null}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, array $input): CardBatch
    {
        if (! $user->can('cards.stock.manage')) {
            throw new AuthorizationException;
        }

        $validated = Validator::make($input, [
            'description' => ['required', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'received_at' => ['required', 'date', 'before_or_equal:today'],
        ], [], [
            'description' => 'descrição',
            'supplier' => 'gráfica',
            'received_at' => 'data de recebimento',
        ])->validate();

        return DB::transaction(function () use ($user, $input, $validated): CardBatch {
            $valid = $this->buildPreview->handle($input)['valid'];

            if ($valid === []) {
                throw ValidationException::withMessages([
                    'numbers' => 'Nenhum número válido para cadastrar: todos foram ignorados.',
                ]);
            }

            $batch = CardBatch::create([
                'description' => $validated['description'],
                'supplier' => filled($validated['supplier'] ?? null) ? $validated['supplier'] : null,
                'received_at' => $validated['received_at'],
                'quantity' => count($valid),
                'first_number' => $valid[0],
                'last_number' => $valid[array_key_last($valid)],
                'user_id' => $user->id,
            ]);

            $this->createCards($batch, $user, $valid);

            return $batch;
        });
    }

    /**
     * Bulk insert the cards and their entry logs (`from` null → `in_stock`).
     *
     * @param  list<string>  $numbers
     */
    protected function createCards(CardBatch $batch, User $user, array $numbers): void
    {
        $inStockId = CardStatusEnum::InStock->id();
        $now = now();

        foreach (array_chunk($numbers, 500) as $chunk) {
            Card::insert(array_map(fn (string $number): array => [
                'card_batch_id' => $batch->id,
                'number' => $number,
                'customer_id' => null,
                'card_status_id' => $inStockId,
                'is_current' => false,
                'assigned_at' => null,
                'status_changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        $batch->cards()->select('id')->chunkById(500, function ($cards) use ($inStockId, $user, $now): void {
            CardStatusLog::insert($cards->map(fn (Card $card): array => [
                'card_id' => $card->id,
                'from_card_status_id' => null,
                'to_card_status_id' => $inStockId,
                'reason' => 'Entrada no estoque',
                'user_id' => $user->id,
                'created_at' => $now,
            ])->all());
        });
    }
}

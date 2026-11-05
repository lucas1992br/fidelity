<?php

namespace App\Actions\Billing;

use App\Enums\CardTransactionResult;
use App\Models\CardImport;
use App\Models\CardTransaction;
use App\Models\User;
use App\Support\Billing\GranitoStatement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import a Granito card statement: every new transaction is stored (one
 * already imported, by its Granito id, is skipped), those not approved are
 * `ignored`, and the approved ones are reconciled oldest first, settling the
 * invoices of the holders and companies whose card is known. The rest stay
 * `pending` for the user to link a payer. Requires `billing.pay`.
 */
class ImportCardStatement
{
    public function __construct(protected ReconcileCardTransaction $reconcileCardTransaction) {}

    /**
     * @return array{import: CardImport, errors: list<string>} The import and the unreadable lines.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(string $contents, string $fileName, User $user): array
    {
        if (! $user->can('billing.pay')) {
            throw new AuthorizationException;
        }

        $statement = GranitoStatement::parse($contents);

        if ($statement->transactions === []) {
            throw ValidationException::withMessages([
                'file' => $statement->errors === [] ? 'O arquivo não tem transações.' : $statement->errors,
            ]);
        }

        $import = DB::transaction(function () use ($statement, $fileName, $user): CardImport {
            $import = CardImport::create(['file_name' => $fileName, 'user_id' => $user->id]);
            $known = CardTransaction::query()
                ->whereIn('external_id', array_column($statement->transactions, 'external_id'))
                ->pluck('external_id')
                ->all();
            $created = 0;

            foreach ($statement->transactions as $line) {
                if (in_array($line['external_id'], $known, true)) {
                    continue;
                }

                $known[] = $line['external_id'];
                $created++;

                $import->transactions()->create([
                    ...collect($line)->except('is_approved')->all(),
                    'result' => $line['is_approved'] ? CardTransactionResult::Pending : CardTransactionResult::Ignored,
                    'message' => $line['is_approved'] ? null : "Transação {$line['status']}: não dá baixa.",
                ]);
            }

            $import->update([
                'transactions_count' => $created,
                'duplicates_count' => count($statement->transactions) - $created,
            ]);

            return $import;
        });

        $import->transactions()
            ->withResult(CardTransactionResult::Pending)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->each(fn (CardTransaction $transaction): CardTransaction => $this->reconcileCardTransaction->handle($transaction, $user));

        return ['import' => $import->refresh(), 'errors' => $statement->errors];
    }
}

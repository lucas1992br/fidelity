<?php

namespace App\Actions\Finance;

use App\Enums\EntryStatus as EntryStatusEnum;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\FinancialEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mark a pending entry as paid or received (US-10.4): it enters the cash flow
 * on the informed date. The row is locked, so two settlements of the same
 * entry result in a single one.
 */
class SettleFinancialEntry
{
    /**
     * @param  array{paid_at?: mixed, payment_method?: mixed}  $input  Date as Y-m-d, method slug.
     *
     * @throws ValidationException
     */
    public function handle(FinancialEntry $entry, array $input): FinancialEntry
    {
        $validated = Validator::make($input, [
            'paid_at' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['required', Rule::exists('payment_methods', 'slug')->where('is_active', true)],
        ], [], [
            'paid_at' => 'data do pagamento',
            'payment_method' => 'forma de pagamento',
        ])->validate();

        return DB::transaction(function () use ($entry, $validated): FinancialEntry {
            $entry = FinancialEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->entry_status_id !== EntryStatusEnum::Pending->id()) {
                throw ValidationException::withMessages(['entry' => 'Este lançamento já foi baixado ou cancelado.']);
            }

            $entry->update([
                'entry_status_id' => EntryStatusEnum::Paid->id(),
                'paid_at' => $validated['paid_at'],
                'payment_method_id' => PaymentMethodEnum::from($validated['payment_method'])->id(),
            ]);

            return $entry;
        });
    }
}

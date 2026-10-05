<?php

namespace App\Actions\Finance;

use App\Enums\EntryStatus as EntryStatusEnum;
use App\Enums\EntryType as EntryTypeEnum;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\FinancialEntry;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Register a manual income or expense (US-10.1). The category must be of the
 * same type and active; the entry is created `pending`, or already `paid`
 * when the payment date and method are informed.
 */
class CreateFinancialEntry
{
    /**
     * @param  array{entry_type?: mixed, financial_category_id?: mixed, description?: mixed, amount?: mixed, due_date?: mixed, supplier_or_payer?: mixed, notes?: mixed, is_paid?: mixed, paid_at?: mixed, payment_method?: mixed}  $input  Type and method as slugs, amount as "89.90", dates as Y-m-d.
     *
     * @throws ValidationException
     */
    public function handle(array $input, User $user): FinancialEntry
    {
        $attributes = self::validate($input);

        return FinancialEntry::create([...$attributes, 'user_id' => $user->id]);
    }

    /**
     * Validate the entry data and map it to the `financial_entries` columns.
     * When editing, the entry's current category stays valid even if inactive.
     *
     * @param  array<string, mixed>  $input
     * @return array{entry_type_id: int, financial_category_id: int, description: string, amount: string, due_date: string, entry_status_id: int, paid_at: string|null, payment_method_id: int|null, supplier_or_payer: string|null, notes: string|null}
     *
     * @throws ValidationException
     */
    public static function validate(array $input, ?FinancialEntry $entry = null): array
    {
        foreach (['description', 'supplier_or_payer', 'notes'] as $field) {
            $input[$field] = is_string($input[$field] ?? null) && trim($input[$field]) !== '' ? trim($input[$field]) : null;
        }

        $type = EntryTypeEnum::tryFrom((string) ($input['entry_type'] ?? ''));

        $validated = Validator::make($input, [
            'entry_type' => ['required', Rule::enum(EntryTypeEnum::class)],
            'financial_category_id' => [
                'required', 'integer',
                Rule::exists('financial_categories', 'id')
                    ->where('entry_type_id', $type?->id())
                    ->where(fn ($query) => $query->where(fn ($query) => $query->where('is_active', true)->when($entry, fn ($query) => $query->orWhere('id', $entry->financial_category_id)))),
            ],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/', function (string $attribute, mixed $value, Closure $fail): void {
                $amount = (string) $value;

                if (is_numeric($amount) && bccomp($amount, '0', 2) <= 0) {
                    $fail('O valor deve ser maior que zero.');
                }
            }],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'supplier_or_payer' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_paid' => ['boolean'],
            'paid_at' => ['exclude_unless:is_paid,true', 'required', 'date_format:Y-m-d'],
            'payment_method' => ['exclude_unless:is_paid,true', 'required', Rule::exists('payment_methods', 'slug')->where('is_active', true)],
        ], [
            'financial_category_id.exists' => $type === EntryTypeEnum::Income
                ? 'Escolha uma categoria de receita ativa.'
                : 'Escolha uma categoria de despesa ativa.',
        ], self::attributes())->validate();

        $isPaid = (bool) ($validated['is_paid'] ?? false);

        return [
            'entry_type_id' => EntryTypeEnum::from($validated['entry_type'])->id(),
            'financial_category_id' => (int) $validated['financial_category_id'],
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'due_date' => $validated['due_date'],
            'entry_status_id' => ($isPaid ? EntryStatusEnum::Paid : EntryStatusEnum::Pending)->id(),
            'paid_at' => $isPaid ? $validated['paid_at'] : null,
            'payment_method_id' => $isPaid ? PaymentMethodEnum::from($validated['payment_method'])->id() : null,
            'supplier_or_payer' => $validated['supplier_or_payer'],
            'notes' => $validated['notes'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'entry_type' => 'tipo',
            'financial_category_id' => 'categoria',
            'description' => 'descrição',
            'amount' => 'valor',
            'due_date' => 'vencimento',
            'supplier_or_payer' => 'fornecedor/pagador',
            'notes' => 'observação',
            'is_paid' => 'já pago',
            'paid_at' => 'data do pagamento',
            'payment_method' => 'forma de pagamento',
        ];
    }
}

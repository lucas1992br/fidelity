<?php

namespace App\Actions\Finance;

use App\Enums\EntryType as EntryTypeEnum;
use App\Models\FinancialCategory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a financial category (US-10.5). System categories (e.g.
 * "Mensalidades", looked up by name and type by the automatic entries) keep
 * their name, type and active flag. A category with entries keeps its type,
 * which the composite foreign key of `financial_entries` also enforces.
 */
class SaveFinancialCategory
{
    /**
     * @param  array{name?: mixed, entry_type?: mixed, is_active?: mixed}  $input  Entry type as slug (income, expense).
     *
     * @throws ValidationException
     */
    public function handle(array $input, ?FinancialCategory $category = null): FinancialCategory
    {
        $input['name'] = is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null);

        $validated = Validator::make($input, [
            'entry_type' => ['required', Rule::enum(EntryTypeEnum::class)],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('financial_categories', 'name')
                    ->where('entry_type_id', EntryTypeEnum::tryFrom((string) ($input['entry_type'] ?? ''))?->id())
                    ->ignore($category?->id),
            ],
            'is_active' => ['required', 'boolean'],
        ], [
            'name.unique' => 'Já existe uma categoria com este nome neste tipo.',
        ], [
            'name' => 'nome',
            'entry_type' => 'tipo',
            'is_active' => 'ativo',
        ])->validate();

        $entryTypeId = EntryTypeEnum::from($validated['entry_type'])->id();

        if ($category?->exists) {
            $this->ensureCanChange($category, $validated['name'], $entryTypeId, (bool) $validated['is_active']);
        }

        $category ??= new FinancialCategory;
        $category->fill([
            'entry_type_id' => $entryTypeId,
            'name' => $validated['name'],
            'is_active' => (bool) $validated['is_active'],
        ])->save();

        return $category;
    }

    /**
     * @throws ValidationException
     */
    protected function ensureCanChange(FinancialCategory $category, string $name, int $entryTypeId, bool $isActive): void
    {
        $typeChanged = $category->entry_type_id !== $entryTypeId;

        if ($category->is_system && $typeChanged) {
            throw ValidationException::withMessages(['entry_type' => 'Categoria de sistema não pode ter o tipo alterado.']);
        }

        if ($category->is_system && $category->name !== $name) {
            throw ValidationException::withMessages(['name' => 'Categoria de sistema não pode ser renomeada.']);
        }

        if ($category->is_system && ! $isActive) {
            throw ValidationException::withMessages(['is_active' => 'Categoria de sistema não pode ser desativada.']);
        }

        if ($typeChanged && $category->entries()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['entry_type' => 'Categoria com lançamentos não pode ter o tipo alterado.']);
        }
    }
}

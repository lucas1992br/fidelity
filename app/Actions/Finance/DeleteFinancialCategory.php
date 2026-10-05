<?php

namespace App\Actions\Finance;

use App\Models\FinancialCategory;
use Illuminate\Validation\ValidationException;

/**
 * Delete a financial category nobody used (US-10.5). System categories are
 * never deleted, and categories with entries must be deactivated instead.
 */
class DeleteFinancialCategory
{
    /**
     * @throws ValidationException
     */
    public function handle(FinancialCategory $category): void
    {
        if ($category->is_system) {
            throw ValidationException::withMessages(['category' => 'Categoria de sistema não pode ser excluída.']);
        }

        if ($category->entries()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Categoria com lançamentos não pode ser excluída; desative-a.',
            ]);
        }

        $category->delete();
    }
}

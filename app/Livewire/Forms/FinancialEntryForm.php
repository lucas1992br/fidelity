<?php

namespace App\Livewire\Forms;

use App\Actions\Plans\SavePlan;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Enums\PaymentMethod;
use App\Models\FinancialEntry;
use Livewire\Attributes\Locked;
use Livewire\Form;

/**
 * Manual financial entry data of the entry modal (US-10.1). The amount is
 * typed in pt-BR ("1.234,56") and the dates come from date inputs (Y-m-d);
 * validation happens in CreateFinancialEntry / UpdateFinancialEntry.
 */
class FinancialEntryForm extends Form
{
    /**
     * Record being edited; null for a new entry.
     */
    #[Locked]
    public ?int $entryId = null;

    public string $entry_type = 'expense';

    public int|string|null $financial_category_id = null;

    public string $description = '';

    public string $amount = '';

    public string $due_date = '';

    public string $supplier_or_payer = '';

    public string $notes = '';

    public bool $is_paid = false;

    public string $paid_at = '';

    public string $payment_method = '';

    /**
     * Defaults of a new entry: expense due today, paid today by PIX when marked as paid.
     */
    public function fillDefaults(): void
    {
        $this->due_date = today()->toDateString();
        $this->paid_at = today()->toDateString();
        $this->payment_method = PaymentMethod::Pix->value;
    }

    /**
     * Load an existing manual entry for editing.
     */
    public function setEntry(FinancialEntry $entry): void
    {
        $this->entryId = $entry->id;
        $this->entry_type = EntryType::fromId($entry->entry_type_id)->value;
        $this->financial_category_id = $entry->financial_category_id;
        $this->description = $entry->description;
        $this->amount = number_format((float) $entry->amount, 2, ',', '.');
        $this->due_date = $entry->due_date->toDateString();
        $this->supplier_or_payer = (string) $entry->supplier_or_payer;
        $this->notes = (string) $entry->notes;
        $this->is_paid = $entry->entry_status_id === EntryStatus::Paid->id();
        $this->paid_at = $entry->paid_at?->toDateString() ?? today()->toDateString();
        $this->payment_method = $entry->payment_method_id ? PaymentMethod::fromId($entry->payment_method_id)->value : PaymentMethod::Pix->value;
    }

    /**
     * Input in the format of the entry actions.
     *
     * @return array{entry_type: string, financial_category_id: int|string|null, description: string, amount: string|null, due_date: string, supplier_or_payer: string, notes: string, is_paid: bool, paid_at: string, payment_method: string}
     */
    public function input(): array
    {
        return [
            'entry_type' => $this->entry_type,
            'financial_category_id' => $this->financial_category_id,
            'description' => $this->description,
            'amount' => SavePlan::normalizePrice($this->amount),
            'due_date' => $this->due_date,
            'supplier_or_payer' => $this->supplier_or_payer,
            'notes' => $this->notes,
            'is_paid' => $this->is_paid,
            'paid_at' => $this->paid_at,
            'payment_method' => $this->payment_method,
        ];
    }
}

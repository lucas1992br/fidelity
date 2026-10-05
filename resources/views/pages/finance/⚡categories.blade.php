<?php

use App\Actions\Finance\DeleteFinancialCategory;
use App\Actions\Finance\SaveFinancialCategory;
use App\Enums\EntryType;
use App\Models\FinancialCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Financial categories (US-10.5): income and expense groups with name, status
 * and a lock on system categories, plus a create/edit modal. Viewing requires
 * `finance.view`; every change requires `finance.manage`. Deactivate and
 * delete are hidden when the actions would refuse them.
 */
new class extends Component
{
    public bool $isModalOpen = false;

    public ?int $categoryId = null;

    public string $name = '';

    public string $entry_type = 'expense';

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('finance.view');
    }

    public function create(string $type = 'expense'): void
    {
        $this->authorize('finance.manage');

        $this->resetForm();
        $this->entry_type = EntryType::tryFrom($type)?->value ?? EntryType::Expense->value;
        $this->isModalOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('finance.manage');

        $category = FinancialCategory::findOrFail($id);

        $this->resetForm();
        $this->categoryId = $category->id;
        $this->name = $category->name;
        $this->entry_type = EntryType::fromId($category->entry_type_id)->value;
        $this->is_active = $category->is_active;
        $this->isModalOpen = true;
    }

    public function save(SaveFinancialCategory $saveCategory): void
    {
        $this->authorize('finance.manage');

        $category = $saveCategory->handle(
            $this->only('name', 'entry_type', 'is_active'),
            $this->categoryId ? FinancialCategory::findOrFail($this->categoryId) : null,
        );

        $this->dispatch('notify', title: $this->categoryId ? 'Categoria atualizada' : 'Categoria criada', description: $category->name.' · '.EntryType::fromId($category->entry_type_id)->label());
        $this->close();
    }

    /**
     * Activate or deactivate a category without opening the modal.
     */
    public function toggleActive(int $id, SaveFinancialCategory $saveCategory): void
    {
        $this->authorize('finance.manage');

        $category = FinancialCategory::findOrFail($id);

        try {
            $saveCategory->handle([
                'name' => $category->name,
                'entry_type' => EntryType::fromId($category->entry_type_id)->value,
                'is_active' => ! $category->is_active,
            ], $category);
        } catch (ValidationException $exception) {
            $this->dispatch('notify', type: 'error', title: $exception->getMessage());

            return;
        }

        $this->dispatch('notify', title: $category->is_active ? 'Categoria ativada' : 'Categoria desativada', description: $category->name);
    }

    public function delete(int $id, DeleteFinancialCategory $deleteCategory): void
    {
        $this->authorize('finance.manage');

        $category = FinancialCategory::findOrFail($id);

        try {
            $deleteCategory->handle($category);
        } catch (ValidationException $exception) {
            $this->dispatch('notify', type: 'error', title: $exception->getMessage());

            return;
        }

        $this->dispatch('notify', title: 'Categoria excluída', description: $category->name);
        $this->close();
    }

    public function close(): void
    {
        $this->isModalOpen = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('categoryId', 'name', 'entry_type', 'is_active');
        $this->resetValidation();
    }

    public function render(): View
    {
        $categories = FinancialCategory::query()
            ->withCount(['entries' => fn ($query) => $query->withTrashed()])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $editing = $this->categoryId ? $categories->firstWhere('id', $this->categoryId) : null;

        return $this->view([
            'groups' => [
                EntryType::Income->value => ['label' => 'Receitas', 'categories' => $categories->where('entry_type_id', EntryType::Income->id())],
                EntryType::Expense->value => ['label' => 'Despesas', 'categories' => $categories->where('entry_type_id', EntryType::Expense->id())],
            ],
            'isTypeLocked' => $editing !== null && ($editing->is_system || $editing->entries_count > 0),
            'isEditingSystem' => (bool) $editing?->is_system,
            'canManage' => auth()->user()->can('finance.manage'),
        ]);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center gap-4">
        <p class="m-0 flex-1 text-sm text-ink-500">Categorias inativas não aparecem em novos lançamentos. Categorias com lançamentos não podem ser excluídas; desative-as.</p>
        @if ($canManage)
            <x-ui.button icon="tags" wire:click="create">Nova categoria</x-ui.button>
        @endif
    </div>

    <div class="grid grid-cols-[repeat(auto-fit,minmax(420px,1fr))] gap-4">
        @foreach ($groups as $type => $group)
            <x-ui.card class="flex flex-col gap-4" wire:key="group-{{ $type }}">
                <div class="flex items-center gap-2">
                    <x-ui.card-title class="flex-1">{{ $group['label'] }}</x-ui.card-title>
                    <span class="text-[13px] text-ink-500">{{ $group['categories']->count() }} {{ $group['categories']->count() === 1 ? 'categoria' : 'categorias' }}</span>
                </div>

                @if ($group['categories']->isEmpty())
                    <x-ui.empty-state icon="tags" title="Nenhuma categoria" description="Crie uma categoria para classificar os lançamentos." />
                @else
                    <x-ui.table :columns="['Nome', 'Status', 'Lançamentos', '>Ações']" min-width="min-w-[400px]">
                        @foreach ($group['categories'] as $category)
                            <tr wire:key="category-{{ $category->id }}">
                                <td>
                                    <span class="inline-flex items-center gap-1.5 font-semibold text-ink-800">
                                        @if ($category->is_system)
                                            <x-ui.icon name="lock" class="size-3.5 text-ink-400" data-slot="system-lock" />
                                        @endif
                                        {{ $category->name }}
                                    </span>
                                </td>
                                <td>
                                    <x-ui.badge :tone="$category->is_active ? 'green' : 'gray'">{{ $category->is_active ? 'Ativa' : 'Inativa' }}</x-ui.badge>
                                </td>
                                <td class="text-ink-500">{{ $category->entries_count }}</td>
                                <td class="text-right whitespace-nowrap">
                                    @if ($canManage && ! $category->is_system)
                                        <x-ui.button variant="link" size="sm" wire:click="edit({{ $category->id }})">Editar</x-ui.button>
                                        <x-ui.button variant="link" size="sm" class="ml-3" wire:click="toggleActive({{ $category->id }})">{{ $category->is_active ? 'Desativar' : 'Ativar' }}</x-ui.button>
                                        @if ($category->entries_count === 0)
                                            <x-ui.button
                                                variant="link"
                                                size="sm"
                                                class="ml-3 text-danger-700 hover:text-danger-700"
                                                wire:click="delete({{ $category->id }})"
                                                wire:confirm="Excluir a categoria {{ $category->name }}?"
                                            >Excluir</x-ui.button>
                                        @endif
                                    @elseif ($category->is_system)
                                        <span class="text-xs text-ink-400">Categoria de sistema</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        @endforeach
    </div>

    @if ($isModalOpen)
        <x-ui.modal :title="$categoryId ? 'Editar categoria' : 'Nova categoria'" subtitle="O tipo define em quais lançamentos a categoria aparece." close="close">
            <x-ui.field label="Nome" for="category-name" error="name">
                <x-ui.input id="category-name" wire:model="name" error="name" autocomplete="off" :disabled="$isEditingSystem" />
            </x-ui.field>

            <div class="flex flex-col gap-1.5">
                <span class="text-[13px] font-semibold text-ink-800">Tipo</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (\App\Enums\EntryType::cases() as $option)
                        <x-ui.choice
                            wire:key="category-type-{{ $option->value }}"
                            :active="$entry_type === $option->value"
                            :disabled="$isTypeLocked"
                            class="disabled:cursor-not-allowed disabled:opacity-60"
                            wire:click="$set('entry_type', '{{ $option->value }}')"
                        >{{ $option->label() }}</x-ui.choice>
                    @endforeach
                </div>
                @error('entry_type')
                    <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span>
                @else
                    @if ($isTypeLocked)
                        <span class="text-xs text-ink-500">O tipo não pode ser alterado: categoria de sistema ou com lançamentos.</span>
                    @endif
                @enderror
            </div>

            <label class="flex items-center gap-2.5 text-sm font-semibold text-ink-800">
                <input type="checkbox" wire:model="is_active" class="size-4 accent-guardian-500" @disabled($isEditingSystem)>
                Ativa para novos lançamentos
            </label>
            @error('is_active') <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span> @enderror

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="save">{{ $categoryId ? 'Salvar alterações' : 'Criar categoria' }}</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>

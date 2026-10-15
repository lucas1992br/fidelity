<?php

use App\Actions\Cards\CheckCardAvailability;
use App\Actions\Customers\AddDependent;
use App\Actions\Customers\CreateCustomer;
use App\Livewire\Forms\CustomerForm;
use App\Livewire\Forms\DependentForm;
use App\Models\Company;
use App\Models\Kinship;
use App\Models\Plan;
use App\Settings\ClinicSettings;
use App\Support\Cards\CardAvailability;
use App\Support\Customers\CustomerRules;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * New sale (US-4.1–4.4): stock card checked in real time, holder data,
 * address filled by ViaCEP, plan, monthly amount (prefilled with the plan's
 * suggested price, freely editable), optional company (an employee follows
 * the company's due day; `?empresa={id}` preselects it) and due day, and the
 * dependents repeater. "Concluir venda" runs `CreateCustomer` in one
 * transaction. Every entry point requires `sales.create`.
 */
new class extends Component
{
    public string $cardNumber = '';

    public CustomerForm $customer;

    public DependentForm $dependent;

    /**
     * Dependents added to the sale, already validated.
     *
     * @var list<array{name: string, cpf: string|null, birth_date: string, kinship_id: int}>
     */
    public array $dependents = [];

    public function mount(ClinicSettings $settings): void
    {
        $this->authorize('sales.create');

        $this->customer->fillDefaults($settings);

        if ($company = $this->companies->firstWhere('id', request()->integer('empresa'))) {
            $this->customer->company_id = $company->id;
            $this->customer->due_day = $company->due_day;
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'customer.plan_id') {
            $this->revalidateDependentsLimit();

            if ($this->selectedPlan) {
                $this->customer->monthly_amount = number_format((float) $this->selectedPlan->price, 2, ',', '.');
            }
        }

        if ($property === 'customer.company_id' && $this->selectedCompany) {
            $this->customer->due_day = $this->selectedCompany->due_day;
        }
    }

    /**
     * State of the typed card number, or null while the field is empty.
     */
    #[Computed]
    public function cardAvailability(): ?CardAvailability
    {
        if (trim($this->cardNumber) === '') {
            return null;
        }

        return app(CheckCardAvailability::class)->handle($this->cardNumber, auth()->user());
    }

    /**
     * Plans offered in new sign-ups.
     *
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()->active()->orderBy('price')->orderBy('name')->get();
    }

    /**
     * Active companies an employee can be sold to.
     *
     * @return Collection<int, Company>
     */
    #[Computed]
    public function companies(): Collection
    {
        return Company::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function selectedCompany(): ?Company
    {
        return $this->companies->firstWhere('id', (int) $this->customer->company_id);
    }

    #[Computed]
    public function selectedPlan(): ?Plan
    {
        return $this->plans->firstWhere('id', (int) $this->customer->plan_id);
    }

    /**
     * @return Collection<int, string>
     */
    #[Computed]
    public function kinships(): Collection
    {
        return Kinship::query()->active()->orderBy('id')->pluck('name', 'id');
    }

    #[Computed]
    public function canAddDependent(): bool
    {
        return $this->selectedPlan !== null && count($this->dependents) < $this->selectedPlan->max_dependents;
    }

    public function addDependent(): void
    {
        $this->authorize('sales.create');

        if ($this->selectedPlan === null) {
            $this->addError('dependents', 'Escolha o plano antes de incluir dependentes.');

            return;
        }

        if ($message = AddDependent::limitMessage($this->selectedPlan, count($this->dependents) + 1)) {
            $this->addError('dependents', $message);

            return;
        }

        $dependent = $this->dependent->validated();

        if ($dependent['cpf'] !== null && in_array($dependent['cpf'], array_column($this->dependents, 'cpf'), true)) {
            $this->dependent->addError('cpf', 'Este CPF já foi informado para outro dependente.');

            return;
        }

        $this->dependents[] = $dependent;
        $this->dependent->reset();
        $this->resetErrorBag('dependents');
    }

    public function removeDependent(int $index): void
    {
        $this->authorize('sales.create');

        unset($this->dependents[$index]);
        $this->dependents = array_values($this->dependents);

        $this->revalidateDependentsLimit();
    }

    public function save(CreateCustomer $createCustomer): void
    {
        $this->authorize('sales.create');

        $this->cardNumber = trim($this->cardNumber);
        $cardError = $this->cardNumber === '' ? 'Informe o número do cartão.' : null;

        try {
            $customer = $this->customer->validated();
        } catch (ValidationException $exception) {
            if ($cardError) {
                $exception->validator->errors()->add('cardNumber', $cardError);
            }

            throw $exception;
        }

        if ($cardError) {
            throw ValidationException::withMessages(['cardNumber' => $cardError]);
        }

        if ($this->selectedPlan && ($message = AddDependent::limitMessage($this->selectedPlan, count($this->dependents)))) {
            throw ValidationException::withMessages(['dependents' => $message]);
        }

        try {
            $createCustomer->handle([
                ...$customer,
                'card_number' => $this->cardNumber,
                'dependents' => $this->dependents,
            ], auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->pageErrors($exception));
        }

        session()->flash('notify', ['title' => 'Venda concluída', 'description' => "Cartão {$this->cardNumber} · {$customer['name']}"]);

        $this->redirectRoute('quick-search', ['q' => $this->cardNumber], navigate: true);
    }

    /**
     * Show (or clear) the limit error when the plan or the dependents change.
     */
    protected function revalidateDependentsLimit(): void
    {
        $message = $this->selectedPlan ? AddDependent::limitMessage($this->selectedPlan, count($this->dependents)) : null;

        $this->resetErrorBag('dependents');

        if ($message) {
            $this->addError('dependents', $message);
        }
    }

    /**
     * Map the Action error keys to the page fields.
     *
     * @return array<string, list<string>>
     */
    protected function pageErrors(ValidationException $exception): array
    {
        $errors = [];

        foreach ($exception->errors() as $key => $messages) {
            if (preg_match('/^dependents\.(\d+)\./', $key, $match)) {
                $errors['dependents'] = array_map(fn (string $message): string => 'Dependente '.((int) $match[1] + 1).': '.$message, $messages);

                continue;
            }

            $errors[match ($key) {
                'card_number' => 'cardNumber',
                'dependents' => 'dependents',
                default => "customer.{$key}",
            }] = $messages;
        }

        return $errors;
    }

    public function render(): View
    {
        return $this->view([
            'states' => CustomerRules::STATES,
        ]);
    }
};
?>

<form
    wire:submit="save"
    x-data="{
        cepLoading: false,
        cepWarning: null,
        async lookupCep(value) {
            const cep = value.replace(/\D/g, '');
            this.cepWarning = null;

            if (cep.length !== 8) {
                return;
            }

            this.cepLoading = true;

            try {
                const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`);

                if (! response.ok) {
                    throw new Error('ViaCEP indisponível');
                }

                const address = await response.json();

                if (address.erro) {
                    this.cepWarning = 'CEP não encontrado. Preencha o endereço manualmente.';

                    return;
                }

                $wire.set('customer.street', address.logradouro || $wire.customer.street, false);
                $wire.set('customer.district', address.bairro || $wire.customer.district, false);
                $wire.set('customer.city', address.localidade || $wire.customer.city, false);
                $wire.set('customer.state', address.uf || $wire.customer.state, false);
                $refs.addressNumber?.focus();
            } catch (error) {
                this.cepWarning = 'Não foi possível consultar o CEP agora. Preencha o endereço manualmente.';
            } finally {
                this.cepLoading = false;
            }
        },
    }"
    class="mx-auto flex w-full max-w-[960px] flex-col gap-6"
>
    <x-ui.card class="flex flex-col gap-4">
        <x-ui.card-title>Cartão</x-ui.card-title>
        <x-ui.field label="Número do cartão" for="card-number" error="cardNumber" hint="Digite o número impresso ou passe o cartão no leitor.">
            <input
                id="card-number"
                wire:model.live.debounce.300ms="cardNumber"
                x-init="$nextTick(() => $el.focus())"
                x-on:keydown.enter.prevent="$refs.customerName.focus()"
                autofocus
                autocomplete="off"
                placeholder="000123"
                @error('cardNumber') aria-invalid="true" @enderror
                class="h-[52px] max-w-full min-w-0 rounded-md border border-ink-200 bg-white px-4 font-mono text-xl font-medium tracking-[0.08em] text-ink-800 outline-none focus:border-guardian-500 focus:shadow-focus"
            >
        </x-ui.field>

        @if ($this->cardAvailability)
            <x-ui.callout :tone="$this->cardAvailability->tone()" wire:key="card-availability">{{ $this->cardAvailability->message }}</x-ui.callout>
        @endif
    </x-ui.card>

    <x-ui.card class="flex flex-col gap-5">
        <x-ui.card-title>Dados pessoais</x-ui.card-title>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,240px),1fr))] gap-4">
            <x-ui.field label="Nome completo" for="customer-name" error="customer.name" class="sm:col-span-2">
                <x-ui.input id="customer-name" x-ref="customerName" wire:model="customer.name" error="customer.name" autocomplete="off" />
            </x-ui.field>
            <x-ui.field label="CPF" for="customer-cpf" error="customer.cpf">
                <x-ui.input id="customer-cpf" wire:model="customer.cpf" error="customer.cpf" mask="cpf" />
            </x-ui.field>
            <x-ui.field label="Data de nascimento" for="customer-birth-date" error="customer.birth_date">
                <x-ui.input id="customer-birth-date" type="date" max="{{ today()->subDay()->toDateString() }}" wire:model="customer.birth_date" error="customer.birth_date" />
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card class="flex flex-col gap-5">
        <x-ui.card-title>Contato</x-ui.card-title>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,240px),1fr))] gap-4">
            <x-ui.field label="Celular / WhatsApp" for="customer-phone" error="customer.phone">
                <x-ui.input id="customer-phone" wire:model="customer.phone" error="customer.phone" mask="phone" />
            </x-ui.field>
            <x-ui.field label="Telefone secundário" for="customer-phone-secondary" error="customer.phone_secondary" hint="Opcional.">
                <x-ui.input id="customer-phone-secondary" wire:model="customer.phone_secondary" error="customer.phone_secondary" mask="phone" />
            </x-ui.field>
            <x-ui.field label="E-mail" for="customer-email" error="customer.email" hint="Opcional.">
                <x-ui.input id="customer-email" type="email" wire:model="customer.email" error="customer.email" />
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card class="flex flex-col gap-5">
        <x-ui.card-title>Endereço</x-ui.card-title>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,200px),1fr))] gap-4">
            <x-ui.field label="CEP" for="customer-zip-code" error="customer.zip_code">
                <x-ui.input id="customer-zip-code" wire:model="customer.zip_code" error="customer.zip_code" mask="zip" x-on:input.debounce.300ms="lookupCep($event.target.value)" />
                <span x-show="cepLoading" x-cloak class="text-xs text-ink-500">Consultando CEP…</span>
            </x-ui.field>
            <x-ui.field label="Logradouro" for="customer-street" error="customer.street" class="sm:col-span-2">
                <x-ui.input id="customer-street" wire:model="customer.street" error="customer.street" />
            </x-ui.field>
            <x-ui.field label="Número" for="customer-number" error="customer.number">
                <x-ui.input id="customer-number" x-ref="addressNumber" wire:model="customer.number" error="customer.number" />
            </x-ui.field>
            <x-ui.field label="Complemento" for="customer-complement" error="customer.complement" hint="Opcional.">
                <x-ui.input id="customer-complement" wire:model="customer.complement" error="customer.complement" />
            </x-ui.field>
            <x-ui.field label="Bairro" for="customer-district" error="customer.district">
                <x-ui.input id="customer-district" wire:model="customer.district" error="customer.district" />
            </x-ui.field>
            <x-ui.field label="Cidade" for="customer-city" error="customer.city">
                <x-ui.input id="customer-city" wire:model="customer.city" error="customer.city" />
            </x-ui.field>
            <x-ui.field label="UF" for="customer-state" error="customer.state">
                <x-ui.select id="customer-state" wire:model="customer.state" error="customer.state" placeholder="Selecione" :options="array_combine($states, $states)" />
            </x-ui.field>
        </div>
        <x-ui.callout tone="warning" x-show="cepWarning" x-cloak><span x-text="cepWarning"></span></x-ui.callout>
    </x-ui.card>

    <x-ui.card class="flex flex-col gap-5">
        <x-ui.card-title>Plano, valor e vencimento</x-ui.card-title>
        <div class="flex flex-col gap-1.5">
            <span class="text-[13px] font-semibold text-ink-800">Plano</span>
            @if ($this->plans->isEmpty())
                <x-ui.callout tone="warning">Nenhum plano ativo. Peça ao administrador para ativar um plano.</x-ui.callout>
            @else
                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,220px),1fr))] gap-3" role="radiogroup" aria-label="Plano">
                    @foreach ($this->plans as $plan)
                        @php($selected = (int) $customer->plan_id === $plan->id)
                        <label
                            wire:key="plan-{{ $plan->id }}"
                            @class([
                                'flex cursor-pointer flex-col gap-1 rounded-md border p-4',
                                'border-guardian-500 bg-guardian-50' => $selected,
                                'border-ink-200 bg-white hover:bg-ink-50' => ! $selected,
                            ])
                        >
                            <input type="radio" name="plan" value="{{ $plan->id }}" wire:model.live="customer.plan_id" class="sr-only">
                            <span class="text-[15px] font-semibold text-ink-800">{{ $plan->name }}</span>
                            <span class="text-sm text-ink-700">sugerido {{ \App\Support\Format::money($plan->price) }}/mês</span>
                            <span class="text-xs text-ink-500">{{ $plan->max_dependents === 0 ? 'Não inclui dependentes' : "Até {$plan->max_dependents} dependentes" }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
            @error('customer.plan_id')
                <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span>
            @enderror
        </div>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,220px),1fr))] gap-4">
            <x-ui.field label="Valor mensal (R$)" for="customer-monthly-amount" error="customer.monthly_amount" hint="Valor cobrado do titular. O preço do plano é só uma sugestão.">
                <x-ui.input id="customer-monthly-amount" wire:model="customer.monthly_amount" error="customer.monthly_amount" mask="money" placeholder="0,00" />
            </x-ui.field>
            @if ($this->companies->isNotEmpty())
                <x-ui.field label="Empresa" for="customer-company" error="customer.company_id" hint="Opcional. Funcionário entra na fatura da empresa.">
                    <x-ui.select id="customer-company" wire:model.live="customer.company_id" error="customer.company_id" placeholder="Cliente individual" :options="$this->companies->pluck('display_name', 'id')" />
                </x-ui.field>
            @endif
            <x-ui.field
                label="Dia de vencimento"
                for="customer-due-day"
                error="customer.due_day"
                :hint="$this->selectedCompany ? 'Vencimento da empresa '.$this->selectedCompany->display_name.'.' : 'De 1 a 28. A primeira mensalidade vence no próximo dia escolhido.'"
            >
                <x-ui.input id="customer-due-day" type="number" min="1" max="28" wire:model="customer.due_day" error="customer.due_day" :disabled="$this->selectedCompany !== null" />
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card class="flex flex-col gap-5">
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.card-title class="flex-1">Dependentes</x-ui.card-title>
            @if ($this->selectedPlan)
                <span class="text-[13px] text-ink-500">{{ count($dependents) }} de {{ $this->selectedPlan->max_dependents }}</span>
            @endif
        </div>

        @error('dependents')
            <x-ui.callout tone="danger">{{ $message }}</x-ui.callout>
        @enderror

        @if ($dependents !== [])
            <ul class="m-0 flex list-none flex-col divide-y divide-ink-100 p-0">
                @foreach ($dependents as $index => $item)
                    <li wire:key="dependent-{{ $index }}-{{ $item['name'] }}" class="flex flex-wrap items-center gap-3 py-3">
                        <span class="w-8 font-mono text-[13px] text-ink-500">-{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <div class="flex min-w-0 flex-1 flex-col">
                            <span class="text-sm font-semibold text-ink-800">{{ $item['name'] }}</span>
                            <span class="text-xs text-ink-500">
                                {{ $this->kinships[$item['kinship_id']] ?? '' }} · {{ \App\Support\Format::date(\Illuminate\Support\Carbon::parse($item['birth_date'])) }}
                                @if ($item['cpf']) · {{ \App\Support\Format::cpf($item['cpf']) }} @endif
                            </span>
                        </div>
                        <x-ui.button variant="ghost" size="sm" wire:click="removeDependent({{ $index }})">Remover</x-ui.button>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($this->selectedPlan === null)
            <p class="m-0 text-sm text-ink-500">Escolha o plano para incluir dependentes.</p>
        @elseif ($this->selectedPlan->max_dependents === 0)
            <p class="m-0 text-sm text-ink-500">O plano {{ $this->selectedPlan->name }} não inclui dependentes.</p>
        @else
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,200px),1fr))] gap-4">
                <x-ui.field label="Nome" for="dependent-name" error="dependent.name">
                    <x-ui.input id="dependent-name" wire:model="dependent.name" error="dependent.name" :disabled="! $this->canAddDependent" />
                </x-ui.field>
                <x-ui.field label="CPF" for="dependent-cpf" error="dependent.cpf" hint="Opcional.">
                    <x-ui.input id="dependent-cpf" wire:model="dependent.cpf" error="dependent.cpf" mask="cpf" :disabled="! $this->canAddDependent" />
                </x-ui.field>
                <x-ui.field label="Data de nascimento" for="dependent-birth-date" error="dependent.birth_date">
                    <x-ui.input id="dependent-birth-date" type="date" max="{{ today()->toDateString() }}" wire:model="dependent.birth_date" error="dependent.birth_date" :disabled="! $this->canAddDependent" />
                </x-ui.field>
                <x-ui.field label="Parentesco" for="dependent-kinship" error="dependent.kinship_id">
                    <x-ui.select id="dependent-kinship" wire:model="dependent.kinship_id" error="dependent.kinship_id" placeholder="Selecione" :options="$this->kinships" :disabled="! $this->canAddDependent" />
                </x-ui.field>
            </div>
            <div>
                <x-ui.button variant="soft" icon="user-plus" wire:click="addDependent" :disabled="! $this->canAddDependent">Adicionar dependente</x-ui.button>
            </div>
        @endif
    </x-ui.card>

    <div class="flex justify-end">
        <x-ui.button type="submit" size="lg" icon="circle-check" wire:loading.attr="disabled" wire:target="save">Concluir venda</x-ui.button>
    </div>
</form>

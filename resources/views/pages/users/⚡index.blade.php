<?php

use App\Actions\Users\CreateUser;
use App\Actions\Users\ResetUserPassword;
use App\Actions\Users\SetUserActive;
use App\Actions\Users\UpdateUser;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * User administration (US-1.4): create, edit profile, activate/deactivate
 * and reset passwords. Every entry point requires `users.manage`.
 */
new class extends Component
{
    /**
     * Open modal: "form" (create/edit) or "password" (reset).
     */
    public ?string $modal = null;

    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'atendente';

    public string $password = '';

    public function mount(): void
    {
        $this->authorize('users.manage');
    }

    public function create(): void
    {
        $this->authorize('users.manage');

        $this->resetForm();
        $this->modal = 'form';
    }

    public function edit(int $id): void
    {
        $this->authorize('users.manage');

        $user = User::with('roles')->findOrFail($id);

        $this->resetForm();
        $this->userId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->getRoleNames()->first() ?? 'atendente';
        $this->modal = 'form';
    }

    public function save(CreateUser $createUser, UpdateUser $updateUser): void
    {
        $this->authorize('users.manage');

        if ($this->userId) {
            $user = $updateUser->handle(User::findOrFail($this->userId), $this->only('name', 'email', 'role'));
            $this->dispatch('notify', title: 'Usuário atualizado', description: "{$user->name} · ".User::ROLE_LABELS[$this->role]);
        } else {
            $user = $createUser->handle($this->only('name', 'email', 'password', 'role'));
            $this->dispatch('notify', title: 'Usuário criado', description: "{$user->name} · ".User::ROLE_LABELS[$this->role]);
        }

        $this->close();
    }

    public function editPassword(int $id): void
    {
        $this->authorize('users.manage');

        $user = User::findOrFail($id);

        $this->resetForm();
        $this->userId = $user->id;
        $this->name = $user->name;
        $this->modal = 'password';
    }

    public function resetPassword(ResetUserPassword $resetUserPassword): void
    {
        $this->authorize('users.manage');

        $user = $resetUserPassword->handle(User::findOrFail($this->userId), $this->password);

        $this->dispatch('notify', title: 'Senha redefinida', description: $user->name);
        $this->close();
    }

    public function toggleActive(int $id, SetUserActive $setUserActive): void
    {
        $this->authorize('users.manage');

        $user = User::findOrFail($id);

        try {
            $setUserActive->handle(auth()->user(), $user, ! $user->is_active);
        } catch (ValidationException $exception) {
            $this->dispatch('notify', type: 'error', title: $exception->getMessage());

            return;
        }

        $this->dispatch('notify', title: $user->is_active ? 'Usuário ativado' : 'Usuário desativado', description: $user->name);
    }

    public function close(): void
    {
        $this->modal = null;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('userId', 'name', 'email', 'role', 'password');
        $this->resetValidation();
    }

    public function render(): View
    {
        return $this->view([
            'users' => User::with('roles')->orderBy('name')->get(),
            'roles' => User::ROLE_LABELS,
        ]);
    }
};
?>

<div class="flex flex-col gap-6">
    <x-ui.card class="flex flex-col gap-2">
        <div class="mb-2 flex flex-wrap items-center gap-4">
            <x-ui.card-title class="flex-1">Usuários do sistema</x-ui.card-title>
            <x-ui.button icon="user-plus" wire:click="create">Novo usuário</x-ui.button>
        </div>

        <x-ui.table :columns="['Nome', 'E-mail', 'Perfil', 'Status', '>Ações']" min-width="min-w-[760px]">
            @foreach ($users as $user)
                @php($userRole = $user->getRoleNames()->first())
                <tr wire:key="user-{{ $user->id }}">
                    <td>
                        <div class="flex items-center gap-3">
                            <x-ui.avatar :initials="\App\Support\Format::initials($user->name)" size="sm" tone="gray" />
                            <span class="font-semibold text-ink-800">{{ $user->name }}</span>
                        </div>
                    </td>
                    <td>{{ $user->email }}</td>
                    <td>
                        <x-ui.badge :tone="match ($userRole) { 'admin' => 'blue', 'financeiro' => 'amber', default => 'gray' }">{{ $user->role_label }}</x-ui.badge>
                    </td>
                    <td>
                        <x-ui.badge :tone="$user->is_active ? 'green' : 'gray'" dot>{{ $user->is_active ? 'Ativo' : 'Inativo' }}</x-ui.badge>
                    </td>
                    <td>
                        <div class="flex justify-end gap-1.5">
                            <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $user->id }})">Editar</x-ui.button>
                            <x-ui.button variant="ghost" size="sm" wire:click="editPassword({{ $user->id }})">Redefinir senha</x-ui.button>
                            @if (! $user->is_active)
                                <x-ui.button variant="soft" size="sm" wire:click="toggleActive({{ $user->id }})">Ativar</x-ui.button>
                            @elseif (! $user->is(auth()->user()))
                                <x-ui.button
                                    variant="secondary"
                                    size="sm"
                                    wire:click="toggleActive({{ $user->id }})"
                                    wire:confirm="Desativar {{ $user->name }}? O acesso será bloqueado no próximo login."
                                >Desativar</x-ui.button>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @if ($modal === 'form')
        <x-ui.modal :title="$userId ? 'Editar usuário' : 'Novo usuário'" :subtitle="$userId ? $email : 'O usuário entra com o e-mail e a senha definidos aqui.'" close="close">
            <x-ui.field label="Nome" for="user-name" error="name">
                <x-ui.input id="user-name" wire:model="name" error="name" autocomplete="off" />
            </x-ui.field>

            <x-ui.field label="E-mail" for="user-email" error="email">
                <x-ui.input id="user-email" type="email" wire:model="email" error="email" autocomplete="off" />
            </x-ui.field>

            @unless ($userId)
                <x-ui.field label="Senha" for="user-password" error="password">
                    <x-ui.input id="user-password" type="password" wire:model="password" error="password" autocomplete="new-password" />
                </x-ui.field>
            @endunless

            <x-ui.field label="Perfil" error="role">
                <div class="grid grid-cols-3 gap-2">
                    @foreach ($roles as $value => $label)
                        <x-ui.choice wire:key="role-{{ $value }}" :active="$role === $value" wire:click="$set('role', '{{ $value }}')">{{ $label }}</x-ui.choice>
                    @endforeach
                </div>
            </x-ui.field>

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="save">{{ $userId ? 'Salvar alterações' : 'Criar usuário' }}</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif

    @if ($modal === 'password')
        <x-ui.modal title="Redefinir senha" :subtitle="$name" close="close">
            <x-ui.field label="Nova senha" for="user-new-password" error="password" hint="Informe a nova senha ao usuário.">
                <x-ui.input id="user-new-password" type="password" wire:model="password" error="password" autocomplete="new-password" />
            </x-ui.field>

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="resetPassword">Redefinir senha</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>

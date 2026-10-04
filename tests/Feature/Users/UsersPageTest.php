<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('financeiro gets 403 when mounting the users page', function () {
    actingAsRole('financeiro');

    Livewire::test('pages::users.index')->assertForbidden();

    $this->withoutVite()->get(route('users.index'))->assertForbidden();
});

test('financeiro gets 403 when calling save', function () {
    actingAsRole('admin');

    $component = Livewire::test('pages::users.index')
        ->call('create')
        ->set('name', 'Intruso')
        ->set('email', 'intruso@clinica.com')
        ->set('password', 'secret-password')
        ->set('role', 'admin');

    actingAsRole('financeiro');

    $component->call('save')->assertForbidden();

    expect(User::where('email', 'intruso@clinica.com')->exists())->toBeFalse();
});

test('the users route renders the page for the admin', function () {
    $admin = actingAsRole('admin');

    $this->withoutVite()->get(route('users.index'))
        ->assertOk()
        ->assertSee('Novo usuário')
        ->assertSee($admin->email);
});

test('admin creates a user through the component', function () {
    actingAsRole('admin');

    Livewire::test('pages::users.index')
        ->call('create')
        ->set('name', 'Ana Lima')
        ->set('email', 'ana@clinica.com')
        ->set('password', 'secret-password')
        ->set('role', 'atendente')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('modal', null)
        ->assertDispatched('notify')
        ->assertSee('ana@clinica.com');

    $user = User::where('email', 'ana@clinica.com')->firstOrFail();

    expect($user->getRoleNames()->all())->toBe(['atendente'])
        ->and($user->is_active)->toBeTrue();
});

test('the component shows the action validation errors', function () {
    actingAsRole('admin');
    User::factory()->create(['email' => 'ana@clinica.com']);

    Livewire::test('pages::users.index')
        ->call('create')
        ->set('name', 'Ana Lima')
        ->set('email', 'ana@clinica.com')
        ->set('password', 'secret-password')
        ->call('save')
        ->assertHasErrors('email')
        ->assertSet('modal', 'form');
});

test('admin edits the profile and resets the password', function () {
    actingAsRole('admin');
    $user = User::factory()->create();
    $user->assignRole('atendente');

    Livewire::test('pages::users.index')
        ->call('edit', $user->id)
        ->assertSet('email', $user->email)
        ->set('role', 'financeiro')
        ->call('save')
        ->assertHasNoErrors()
        ->call('editPassword', $user->id)
        ->set('password', 'brand-new-password')
        ->call('resetPassword')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->getRoleNames()->all())->toBe(['financeiro'])
        ->and(Hash::check('brand-new-password', $user->password))->toBeTrue();
});

test('admin deactivates another user but not themselves', function () {
    $admin = actingAsRole('admin');
    $user = User::factory()->create(['name' => 'Outro Usuário']);

    Livewire::test('pages::users.index')
        ->assertSeeHtml('wire:click="toggleActive('.$user->id.')"')
        ->assertDontSeeHtml('wire:click="toggleActive('.$admin->id.')"')
        ->call('toggleActive', $user->id)
        ->call('toggleActive', $admin->id)
        ->assertDispatched('notify', type: 'error');

    expect($user->refresh()->is_active)->toBeFalse()
        ->and($admin->refresh()->is_active)->toBeTrue();
});

test('financeiro cannot call the user actions', function (string $method, bool $takesUserId) {
    actingAsRole('admin');
    $component = Livewire::test('pages::users.index');
    $user = User::factory()->create();

    actingAsRole('financeiro');

    $component->call($method, ...($takesUserId ? [$user->id] : []))->assertForbidden();
})->with([
    'create' => ['create', false],
    'edit' => ['edit', true],
    'editPassword' => ['editPassword', true],
    'resetPassword' => ['resetPassword', false],
    'toggleActive' => ['toggleActive', true],
]);

<?php

use App\Actions\Users\CreateUser;
use App\Actions\Users\ResetUserPassword;
use App\Actions\Users\SetUserActive;
use App\Actions\Users\UpdateUser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

test('creates an active user with the given profile', function () {
    $user = app(CreateUser::class)->handle([
        'name' => 'Maria Souza',
        'email' => 'Maria@Clinica.com',
        'password' => 'secret-password',
        'role' => 'financeiro',
    ]);

    expect($user->email)->toBe('maria@clinica.com')
        ->and($user->is_active)->toBeTrue()
        ->and($user->getRoleNames()->all())->toBe(['financeiro'])
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

test('creating a user with a duplicated e-mail fails', function () {
    User::factory()->create(['email' => 'maria@clinica.com']);

    app(CreateUser::class)->handle([
        'name' => 'Maria Souza',
        'email' => 'MARIA@clinica.com',
        'password' => 'secret-password',
        'role' => 'atendente',
    ]);
})->throws(ValidationException::class, 'e-mail');

test('creating a user with an unknown profile fails', function () {
    app(CreateUser::class)->handle([
        'name' => 'Maria Souza',
        'email' => 'maria@clinica.com',
        'password' => 'secret-password',
        'role' => 'gerente',
    ]);
})->throws(ValidationException::class);

test('changing the profile replaces the previous one', function () {
    $user = User::factory()->create();
    $user->assignRole('atendente');

    app(UpdateUser::class)->handle($user, [
        'name' => 'Novo Nome',
        'email' => $user->email,
        'role' => 'financeiro',
    ]);

    $user->refresh();

    expect($user->name)->toBe('Novo Nome')
        ->and($user->getRoleNames()->all())->toBe(['financeiro']);
});

test('deactivating your own account is a validation error', function () {
    $admin = actingAsRole('admin');

    expect(fn () => app(SetUserActive::class)->handle($admin, $admin, false))
        ->toThrow(ValidationException::class, 'Você não pode desativar a sua própria conta.');

    expect($admin->refresh()->is_active)->toBeTrue();
});

test('a deactivated user is blocked on the next login', function () {
    $admin = User::factory()->create();
    $user = User::factory()->create();

    app(SetUserActive::class)->handle($admin, $user, false);

    expect($user->refresh()->is_active)->toBeFalse();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();

    app(SetUserActive::class)->handle($admin, $user, true);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

test('reset stores a new password hash', function () {
    $user = User::factory()->create();
    $oldHash = $user->password;

    app(ResetUserPassword::class)->handle($user, 'brand-new-password');

    $user->refresh();

    expect($user->password)->not->toBe($oldHash)
        ->and(Hash::check('brand-new-password', $user->password))->toBeTrue();
});

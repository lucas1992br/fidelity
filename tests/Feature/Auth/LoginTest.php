<?php

use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

test('login page renders the form in the guest layout', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Cartão fidelidade')
        ->assertSee('action="'.route('login.store').'"', false)
        ->assertSee('name="_token"', false)
        ->assertSee('name="email"', false)
        ->assertSee('name="password"', false)
        ->assertSee('Lembrar de mim')
        ->assertSee('Entrar');
});

test('only the login and logout features are enabled', function () {
    expect(config('fortify.features'))->toBe([])
        ->and(config('fortify.home'))->toBe('/');
});

test('an active user logs in', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

test('wrong password and unknown e-mail get the same generic message', function () {
    $user = User::factory()->create();

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    expect(trans('auth.failed'))->toBe('Essas credenciais não foram encontradas em nossos registros.');
    $this->assertGuest();
});

test('the login error is shown under the e-mail field', function () {
    $this->followingRedirects()
        ->from(route('login'))
        ->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->assertSee('Essas credenciais não foram encontradas em nossos registros.')
        ->assertSee('aria-invalid="true"', false);
});

test('an inactive user with the right password is not authenticated', function () {
    $user = User::factory()->inactive()->create();

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Sua conta está desativada. Procure o administrador.']);

    $this->assertGuest();
});

test('an inactive user with a wrong password gets the generic message', function () {
    $user = User::factory()->inactive()->create();

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
});

test('the sixth login attempt in a minute is throttled', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->from(route('login'))
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Muitas tentativas de login. Tente novamente em 60 segundos.']);

    $this->assertGuest();
});

test('logout ends the session and redirects to the login page', function () {
    actingAsRole('financeiro');

    $this->post(route('logout'))->assertRedirect('/login');

    $this->assertGuest();
});

test('the header shows a logout button', function () {
    $user = actingAsRole('financeiro');

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('action="'.route('logout').'"', false)
        ->assertSee('Sair');
});

test('a guest visiting the dashboard is redirected to the login page', function () {
    $this->get('/painel')->assertRedirect(route('login'));
});

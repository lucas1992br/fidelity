<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('the home page sends each profile to its start page', function (string $role, string $routeName) {
    actingAsRole($role);

    $this->get('/')->assertRedirect(route($routeName));
})->with([
    'atendente' => ['atendente', 'quick-search'],
    'financeiro' => ['financeiro', 'dashboard'],
    'admin' => ['admin', 'dashboard'],
]);

test('after logging in the atendente lands on the quick search', function () {
    $user = User::factory()->create();
    $user->assignRole('atendente');

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/');

    $this->get('/')->assertRedirect(route('quick-search'));
});

test('the welcome page is gone', function () {
    expect(Route::has('welcome'))->toBeFalse()
        ->and(view()->exists('welcome'))->toBeFalse();
});

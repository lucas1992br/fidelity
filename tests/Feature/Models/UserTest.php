<?php

use App\Models\User;

test('role label is the pt-BR name of the profile', function (string $role, string $label) {
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->role_label)->toBe($label);
})->with([
    ['admin', 'Admin'],
    ['financeiro', 'Financeiro'],
    ['atendente', 'Atendente'],
]);

test('active scope leaves out deactivated users', function () {
    $active = User::factory()->create();
    User::factory()->inactive()->create();

    expect(User::active()->pluck('id')->all())->toBe([$active->id])
        ->and($active->refresh()->is_active)->toBeTrue();
});

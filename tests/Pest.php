<?php

use App\Models\User;
use App\Settings\ClinicSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Global helpers shared by the feature tests.
|
*/

/**
 * Create an active user with the given profile (admin, financeiro,
 * atendente) and authenticate as them.
 */
function actingAsRole(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

/**
 * Create an active user whose only permission is `finance.view` (no role)
 * and authenticate as them.
 */
function actingAsFinanceViewer(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo('finance.view');

    test()->actingAs($user);

    return $user;
}

/**
 * Override clinic settings for the current test and persist them.
 *
 * @param  array<string, mixed>  $overrides
 */
function settings(array $overrides): ClinicSettings
{
    return app(ClinicSettings::class)->fill($overrides)->save();
}

<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config([
        'app.admin.name' => 'Admin Clínica',
        'app.admin.email' => 'admin@clinica.test',
        'app.admin.password' => 'secret-password',
    ]);

    $this->seed(RolesAndPermissionsSeeder::class);
});

test('it creates the admin user with the admin role', function () {
    $this->seed(AdminUserSeeder::class);

    $admin = User::where('email', 'admin@clinica.test')->sole();

    expect($admin->name)->toBe('Admin Clínica')
        ->and(Hash::check('secret-password', $admin->password))->toBeTrue()
        ->and($admin->hasRole('admin'))->toBeTrue();
});

test('running it again does not change the password', function () {
    $this->seed(AdminUserSeeder::class);

    config(['app.admin.password' => 'another-password']);
    $this->seed(AdminUserSeeder::class);

    $admin = User::where('email', 'admin@clinica.test')->sole();

    expect(User::count())->toBe(1)
        ->and(Hash::check('secret-password', $admin->password))->toBeTrue()
        ->and($admin->roles()->count())->toBe(1);
});

test('it skips the admin when the credentials are not configured', function () {
    config(['app.admin.email' => null, 'app.admin.password' => null]);

    $this->seed(AdminUserSeeder::class);

    expect(User::count())->toBe(0);
});

test('the database seeder runs lookups, roles and the admin', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::sole()->hasRole('admin'))->toBeTrue()
        ->and(DB::table('card_statuses')->count())->toBe(6);
});

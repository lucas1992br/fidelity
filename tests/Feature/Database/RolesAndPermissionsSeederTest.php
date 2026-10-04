<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('it creates the 17 permissions and the three roles', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::pluck('name')->sort()->values()->all())
        ->toBe(collect(RolesAndPermissionsSeeder::PERMISSIONS)->sort()->values()->all())
        ->and(Permission::count())->toBe(17)
        ->and(Role::pluck('name')->sort()->values()->all())->toBe(['admin', 'atendente', 'financeiro']);
});

test('financeiro has every permission except the four restricted ones', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $permissions = Role::findByName('financeiro')->permissions->pluck('name');

    expect($permissions)->toHaveCount(13)
        ->and($permissions->intersect(['plans.manage', 'billing.refund', 'settings.manage', 'users.manage']))->toBeEmpty()
        ->and($permissions->sort()->values()->all())->toBe(collect([
            'cards.check', 'sales.create', 'dashboard.view', 'customers.view', 'customers.update',
            'cards.manage', 'cards.stock.view', 'cards.stock.manage', 'billing.view', 'billing.pay',
            'finance.view', 'finance.manage', 'reports.view',
        ])->sort()->values()->all());
});

test('atendente has exactly the check and sale permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::findByName('atendente')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['cards.check', 'sales.create']);
});

test('admin has no rows in role_has_permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(DB::table('role_has_permissions')->where('role_id', Role::findByName('admin')->id)->count())->toBe(0);
});

test('running the seeder twice does not duplicate anything', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::count())->toBe(17)
        ->and(Role::count())->toBe(3)
        ->and(DB::table('role_has_permissions')->count())->toBe(15);
});

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Every permission of the access matrix (spec §10.1).
     *
     * @var list<string>
     */
    public const array PERMISSIONS = [
        'cards.check',
        'sales.create',
        'dashboard.view',
        'customers.view',
        'customers.update',
        'cards.manage',
        'cards.stock.view',
        'cards.stock.manage',
        'plans.manage',
        'billing.view',
        'billing.pay',
        'billing.refund',
        'finance.view',
        'finance.manage',
        'reports.view',
        'settings.manage',
        'users.manage',
    ];

    /**
     * Permissions the financeiro role does not receive.
     *
     * @var list<string>
     */
    public const array FINANCEIRO_DENIED = [
        'plans.manage',
        'billing.refund',
        'settings.manage',
        'users.manage',
    ];

    /**
     * Permissions of the atendente role.
     *
     * @var list<string>
     */
    public const array ATENDENTE = [
        'cards.check',
        'sales.create',
    ];

    /**
     * Seed the roles and permissions idempotently.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }

        Role::findOrCreate('admin')->syncPermissions([]);

        Role::findOrCreate('financeiro')->syncPermissions(
            array_values(array_diff(self::PERMISSIONS, self::FINANCEIRO_DENIED)),
        );

        Role::findOrCreate('atendente')->syncPermissions(self::ATENDENTE);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

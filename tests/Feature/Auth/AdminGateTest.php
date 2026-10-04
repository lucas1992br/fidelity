<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

test('admin is granted every ability without role permissions', function () {
    $admin = actingAsRole('admin');

    expect(DB::table('role_has_permissions')->where('role_id', Role::findByName('admin')->id)->count())->toBe(0)
        ->and(Gate::forUser($admin)->allows('users.manage'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('billing.refund'))->toBeTrue();
});

test('non-admin profiles do not get the admin bypass', function () {
    $financeiro = actingAsRole('financeiro');

    expect(Gate::forUser($financeiro)->allows('users.manage'))->toBeFalse()
        ->and(Gate::forUser($financeiro)->allows('billing.refund'))->toBeFalse();
});

<?php

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\FinancialCategory;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

test('feature tests start with lookups, roles and financial categories seeded', function () {
    expect(DB::table('card_statuses')->where('slug', CardStatusEnum::Active->value)->exists())->toBeTrue()
        ->and(Role::pluck('name')->sort()->values()->all())->toBe(['admin', 'atendente', 'financeiro'])
        ->and(FinancialCategory::monthlyFees()->is_system)->toBeTrue();
});

test('acting as role authenticates an active user with that profile', function () {
    $user = actingAsRole('financeiro');

    expect(auth()->id())->toBe($user->id)
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasRole('financeiro'))->toBeTrue()
        ->and($user->can('billing.pay'))->toBeTrue()
        ->and($user->can('users.manage'))->toBeFalse();
});

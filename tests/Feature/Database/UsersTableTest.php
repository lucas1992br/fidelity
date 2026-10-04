<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('a user created without is_active is active by default', function () {
    $user = User::factory()->create();

    expect(DB::table('users')->where('id', $user->id)->value('is_active'))->toBeTrue();
});

test('the inactive factory state deactivates the user', function () {
    $user = User::factory()->inactive()->create();

    expect(DB::table('users')->where('id', $user->id)->value('is_active'))->toBeFalse();
});

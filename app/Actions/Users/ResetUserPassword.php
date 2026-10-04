<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Replace a user's password with a new one chosen by the admin.
 */
class ResetUserPassword
{
    /**
     * @throws ValidationException
     */
    public function handle(User $user, string $password): User
    {
        Validator::make(['password' => $password], [
            'password' => ['required', 'string', Password::defaults()],
        ])->validate();

        $user->forceFill(['password' => $password])->save();

        return $user;
    }
}

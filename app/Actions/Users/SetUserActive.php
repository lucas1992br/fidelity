<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Activate or deactivate a user. A deactivated user is blocked on the next
 * login; nobody can deactivate their own account.
 */
class SetUserActive
{
    /**
     * @throws ValidationException
     */
    public function handle(User $actingUser, User $user, bool $active): User
    {
        if (! $active && $actingUser->is($user)) {
            throw ValidationException::withMessages([
                'is_active' => 'Você não pode desativar a sua própria conta.',
            ]);
        }

        $user->update(['is_active' => $active]);

        return $user;
    }
}

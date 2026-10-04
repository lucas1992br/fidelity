<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Update a user's name, e-mail and profile; the new profile replaces the old one.
 */
class UpdateUser
{
    /**
     * @param  array{name?: string, email?: string, role?: string}  $input
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): User
    {
        $input['email'] = Str::lower(trim((string) ($input['email'] ?? '')));

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($user)],
            'role' => ['required', Rule::in(array_keys(User::ROLE_LABELS))],
        ], [], ['email' => 'e-mail', 'role' => 'perfil'])->validate();

        return DB::transaction(function () use ($user, $validated): User {
            $user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
            ]);

            $user->syncRoles([$validated['role']]);

            return $user;
        });
    }
}

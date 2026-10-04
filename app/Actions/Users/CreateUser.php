<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Create an active user with a single profile (admin, financeiro or atendente).
 */
class CreateUser
{
    /**
     * @param  array{name?: string, email?: string, password?: string, role?: string}  $input
     *
     * @throws ValidationException
     */
    public function handle(array $input): User
    {
        $input['email'] = Str::lower(trim((string) ($input['email'] ?? '')));

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', Rule::in(array_keys(User::ROLE_LABELS))],
        ], [], ['email' => 'e-mail', 'role' => 'perfil'])->validate();

        return DB::transaction(function () use ($validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'is_active' => true,
            ]);

            $user->syncRoles([$validated['role']]);

            return $user;
        });
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    /**
     * Create (or keep) the initial admin from ADMIN_NAME, ADMIN_EMAIL and
     * ADMIN_PASSWORD. An existing user's password is never overwritten.
     */
    public function run(): void
    {
        $email = config('app.admin.email');
        $password = config('app.admin.password');

        if (blank($email) || blank($password)) {
            $this->command->warn('ADMIN_EMAIL and ADMIN_PASSWORD are not set; skipping the initial admin user.');

            return;
        }

        $admin = User::firstOrCreate(
            ['email' => $email],
            ['name' => config('app.admin.name'), 'password' => $password],
        );

        $admin->assignRole(Role::findOrCreate('admin'));
    }
}

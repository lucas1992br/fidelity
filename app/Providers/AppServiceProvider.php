<?php

namespace App\Providers;

use App\Models\User;
use App\Queries\DelinquentsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(DelinquentsQuery::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        $this->configureAuthorization();
    }

    /**
     * The admin holds every permission without rows in `role_has_permissions`.
     *
     * The bypass only answers permission checks (`can('users.manage')`, no
     * arguments). Policy checks (`can('update', $entry)`) still run the policy,
     * whose permission checks pass for the admin through this same bypass, so
     * state rules such as "automatic entries are read-only" apply to everyone.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(fn (User $user, string $ability, array $arguments): ?bool => $user->hasRole('admin') && $arguments === [] ? true : null);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        URL::forceHttps(app()->isProduction());

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

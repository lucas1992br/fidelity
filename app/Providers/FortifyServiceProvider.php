<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the login/logout flow (the only Fortify features enabled).
     */
    public function boot(): void
    {
        Fortify::loginView(fn (): View => view('auth.login'));

        Fortify::authenticateUsing($this->authenticate(...));

        $this->configureRateLimiting();
    }

    /**
     * Resolve the user for the given credentials.
     *
     * Wrong e-mail and wrong password both fall back to Fortify's generic
     * `auth.failed` message, so the response never reveals whether the
     * e-mail exists. A deactivated account is only reported after the
     * password matches.
     *
     * @throws ValidationException
     */
    protected function authenticate(Request $request): ?User
    {
        $user = User::query()->where('email', $request->string(Fortify::username())->lower()->value())->first();

        if (! $user || ! Hash::check($request->string('password')->value(), $user->password)) {
            return null;
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                Fortify::username() => 'Sua conta está desativada. Procure o administrador.',
            ]);
        }

        return $user;
    }

    /**
     * Five login attempts per minute per e-mail + IP; the sixth goes back to
     * the form with the pt-BR throttle message on the e-mail field.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $throttleKey = Str::transliterate(Str::lower($request->string(Fortify::username())->value()).'|'.$request->ip());

            return Limit::perMinute(5)
                ->by($throttleKey)
                ->response(fn (Request $request, array $headers): RedirectResponse => back()
                    ->withInput($request->only(Fortify::username(), 'remember'))
                    ->withErrors([
                        Fortify::username() => trans('auth.throttle', [
                            'seconds' => $headers['Retry-After'],
                            'minutes' => (int) ceil($headers['Retry-After'] / 60),
                        ]),
                    ]));
        });
    }
}

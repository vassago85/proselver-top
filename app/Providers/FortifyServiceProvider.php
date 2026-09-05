<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::authenticateUsing(function (Request $request) {
            $identity = Str::lower(trim($request->input('identity', '')));

            $user = User::where('email', $identity)
                ->orWhere('username', $identity)
                ->orWhere('phone', $identity)
                ->first();

            if ($user && $user->is_active && Hash::check($request->password, $user->password)) {
                return $user;
            }

            // Fortify only fires Illuminate\Auth\Events\Failed automatically
            // when it uses the default Guard::attempt() path.  A custom
            // authenticateUsing() closure short-circuits that, so we fire it
            // ourselves — the LogLoginActivity listener needs it to record
            // failed attempts in login_history.  We keep the credentials
            // payload minimal (identity only, NEVER the password) so nothing
            // sensitive leaks into an event that might be picked up by other
            // listeners later.
            Event::dispatch(new Failed(
                Auth::getDefaultDriver(),
                $user, // may be null if the identity didn't match anyone
                ['identity' => $identity],
            ));

            return null;
        });

        Fortify::loginView(function () {
            return view('auth.login');
        });

        Fortify::requestPasswordResetLinkView(function () {
            return view('auth.forgot-password');
        });

        Fortify::resetPasswordView(function ($request) {
            return view('auth.reset-password', ['request' => $request]);
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input('identity', '')).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}

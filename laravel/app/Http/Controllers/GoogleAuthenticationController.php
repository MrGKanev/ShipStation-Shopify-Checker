<?php

namespace App\Http\Controllers;

use App\Domain\Authentication\GoogleIdentityPolicy;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthenticationController extends Controller
{
    public function redirect(GoogleIdentityPolicy $policy): RedirectResponse
    {
        if (! $this->configured($policy)) {
            return redirect()->route('login')->withErrors(['google' => 'Google sign-in is not configured.']);
        }

        $driver = Socialite::driver('google');
        if (count($policy->allowedDomains()) === 1) {
            $driver->with(['hd' => $policy->allowedDomains()[0], 'prompt' => 'select_account']);
        }

        return $driver->redirect();
    }

    public function callback(Request $request, GoogleIdentityPolicy $policy): RedirectResponse
    {
        if (! $this->configured($policy)) {
            return redirect()->route('login')->withErrors(['google' => 'Google sign-in is not configured.']);
        }
        if ($request->filled('error')) {
            return redirect()->route('login')->withErrors(['google' => 'Google sign-in was cancelled.']);
        }

        try {
            $identity = Socialite::driver('google')->user();
            if (! $policy->allows($identity)) {
                return redirect()->route('login')->withErrors(['google' => 'This Google Workspace account is not allowed.']);
            }
            $email = mb_strtolower(trim((string) $identity->getEmail()));
            $googleId = trim((string) $identity->getId());
            $user = User::query()->where('google_id', $googleId)->orWhereRaw('LOWER(email) = ?', [$email])->first();
            if ($user === null || ($user->google_id !== null && ! hash_equals((string) $user->google_id, $googleId))) {
                return redirect()->route('login')->withErrors(['google' => 'Your account has not been granted access.']);
            }
            $user->forceFill(['google_id' => $googleId, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        } catch (Throwable $exception) {
            Log::warning('Google authentication failed.', ['exception_type' => $exception::class]);

            return redirect()->route('login')->withErrors(['google' => 'Google sign-in could not be completed. Please try again.']);
        }
    }

    private function configured(GoogleIdentityPolicy $policy): bool
    {
        return trim((string) config('services.google.client_id')) !== ''
            && trim((string) config('services.google.client_secret')) !== ''
            && trim((string) config('services.google.redirect')) !== ''
            && $policy->allowedDomains() !== [];
    }
}

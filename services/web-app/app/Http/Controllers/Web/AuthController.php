<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function showLogin(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('chat');
        }

        return Inertia::render('Auth/Login', [
            'twoFactorPending' => $request->session()->has('2fa:user_id'),
        ]);
    }

    public function login(Request $request, AuditLogger $audit): RedirectResponse
    {
        if ($request->session()->has('2fa:user_id') && $request->filled('otp_code')) {
            return $this->verifyTwoFactor($request, $audit);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = 'login:'.strtolower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return back()->withErrors(['email' => "Too many login attempts. Retry in {$seconds}s."]);
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();
        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            return back()->withErrors(['email' => 'Invalid credentials or inactive account.']);
        }

        RateLimiter::clear($throttleKey);

        if ($user->two_factor_enabled && $user->two_factor_secret) {
            $request->session()->put('2fa:user_id', $user->id);
            $request->session()->put('2fa:remember', $request->boolean('remember'));

            return redirect()->route('login');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $audit->record($request, 'auth.login', 'user', (string) $user->id);

        return redirect()->intended(route('chat'));
    }

    protected function verifyTwoFactor(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['otp_code' => ['required', 'string', 'size:6']]);
        $userId = $request->session()->get('2fa:user_id');
        $user = User::query()->find($userId);

        if (! $user || ! $user->two_factor_secret || ! Totp::verify($user->two_factor_secret, $request->string('otp_code')->toString())) {
            return back()->withErrors(['otp_code' => 'Invalid 6-digit authentication code.']);
        }

        $remember = (bool) $request->session()->pull('2fa:remember', false);
        $request->session()->forget('2fa:user_id');
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $audit->record($request, 'auth.login_2fa', 'user', (string) $user->id);

        return redirect()->intended(route('chat'));
    }

    public function cancelTwoFactor(Request $request): RedirectResponse
    {
        $request->session()->forget(['2fa:user_id', '2fa:remember']);

        return redirect()->route('login');
    }

    public function logout(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->record($request, 'auth.logout', 'user', (string) $request->user()?->id);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function profile(Request $request): Response
    {
        $user = $request->user();
        $pendingSecret = $request->session()->get('2fa:pending_secret');

        return Inertia::render('Profile', [
            'pendingSecret' => $pendingSecret,
            'pendingUri' => $pendingSecret ? Totp::provisioningUri($pendingSecret, $user->email) : null,
        ]);
    }

    public function updatePassword(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $request->user()->update(['password' => $validated['password']]);
        $audit->record($request, 'auth.password_change', 'user', (string) $request->user()->id);

        return back()->with('success', 'Password updated.');
    }

    public function setupTwoFactor(Request $request): RedirectResponse
    {
        $secret = Totp::generateSecret();
        $request->session()->put('2fa:pending_secret', $secret);

        return back()->with('success', '2FA secret generated. Enter the 6-digit code from your authenticator app to activate.');
    }

    public function confirmTwoFactor(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['otp_code' => ['required', 'string', 'size:6']]);
        $secret = $request->session()->get('2fa:pending_secret');
        if (! $secret || ! Totp::verify($secret, $request->string('otp_code')->toString())) {
            return back()->withErrors(['otp_code' => 'Verification code did not match.']);
        }

        $request->user()->update([
            'two_factor_secret' => $secret,
            'two_factor_enabled' => true,
        ]);
        $request->session()->forget('2fa:pending_secret');
        $audit->record($request, 'auth.2fa_enable', 'user', (string) $request->user()->id);

        return back()->with('success', 'Two-factor authentication enabled.');
    }

    public function disableTwoFactor(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->user()->update([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
        ]);
        $audit->record($request, 'auth.2fa_disable', 'user', (string) $request->user()->id);

        return back()->with('success', 'Two-factor authentication disabled.');
    }
}

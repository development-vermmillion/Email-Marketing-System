<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(LoginRequest $request, ActivityLogger $activity): RedirectResponse
    {
        // Brute-force protection is the `throttle:login` middleware on the route.
        $credentials = $request->only('email', 'password') + ['is_active' => true];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        $user = Auth::user();

        if (! $user->is_admin) {
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages(['email' => 'This account does not have administrator access.']);
        }

        $request->session()->regenerate(); // prevents session fixation
        $user->forceFill(['last_login_at' => now()])->save();
        $activity->log('auth', 'login', "{$user->email} signed in");

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $activity->log('auth', 'logout', ($request->user()?->email ?? 'User').' signed out');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

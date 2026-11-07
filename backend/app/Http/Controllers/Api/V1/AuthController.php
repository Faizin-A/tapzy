<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthUserResource;
use App\Models\User;
use App\Services\Auth\LoginEligibility;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginEligibility $eligibility): AuthUserResource
    {
        if (! Auth::guard('web')->attempt($request->only('email', 'password'))) {
            $this->rejectLogin($request);
        }

        $user = Auth::guard('web')->user();

        if (! $user instanceof User || ! $eligibility->allows($user)) {
            $this->rejectLogin($request);
        }

        $user = User::query()->find($user->getKey());

        if (! $eligibility->allows($user)
            || ! is_int($user->session_generation)
            || $user->session_generation < 1) {
            $this->rejectLogin($request);
        }

        $request->session()->regenerate();
        $request->session()->put('auth_session_generation', $user->session_generation);

        return AuthUserResource::make($user);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        return response()->noContent();
    }

    public function me(Request $request): AuthUserResource
    {
        return AuthUserResource::make($request->user());
    }

    private function rejectLogin(LoginRequest $request): never
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw ValidationException::withMessages([
            'email' => ['Email atau password salah.'],
        ]);
    }
}

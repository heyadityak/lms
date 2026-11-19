<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\KeycloakProvider;
use App\Auth\SyncKeycloakUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/** OIDC authorization-code login against Keycloak. Passwords never touch the app. */
final class KeycloakLoginController
{
    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('keycloak')->scopes(['openid', 'profile', 'email'])->redirect();
    }

    public function callback(Request $request, SyncKeycloakUser $sync): RedirectResponse
    {
        // Keycloak reports login errors (e.g. access_denied) via ?error=…; never retry the exchange.
        abort_if($request->filled('error'), 401, 'Login was not completed.');

        try {
            $kcUser = Socialite::driver('keycloak')->user();
        } catch (InvalidStateException) {
            return redirect()->route('login');
        }

        // userinfo is fetched over the back channel with the fresh access token, so its claims are trusted.
        $user = $sync->handle($kcUser->getRaw());

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('keycloak_id_token', $kcUser->accessTokenResponseBody['id_token'] ?? null);

        return redirect()->intended('/');
    }

    /** Local logout, then Keycloak RP-initiated logout (ends the SSO session). */
    public function logout(Request $request): SymfonyRedirect
    {
        $idToken = $request->session()->get('keycloak_id_token');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        /** @var KeycloakProvider $provider */
        $provider = Socialite::driver('keycloak');

        return redirect()->away($provider->getLogoutUrl(url('/'), config('keycloak.client_id'), $idToken));
    }
}

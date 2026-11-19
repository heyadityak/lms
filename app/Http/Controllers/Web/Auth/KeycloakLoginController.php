<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Auth\KeycloakJwtGuard;
use App\Auth\KeycloakProvider;
use App\Auth\KeycloakUserProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/** OIDC authorization-code login against Keycloak (D-20). Passwords never touch the app. */
final class KeycloakLoginController
{
    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('keycloak')->scopes(['openid', 'profile', 'email'])->redirect();
    }

    public function callback(Request $request, KeycloakUserProvisioner $provisioner): RedirectResponse
    {
        // Keycloak reports login errors (e.g. access_denied) via ?error=…; never retry the exchange.
        abort_if($request->filled('error'), 401, 'Login was not completed.');

        try {
            $kcUser = Socialite::driver('keycloak')->user();
        } catch (InvalidStateException) {
            return redirect()->route('login');
        }

        // Provision from the verified access token (signature, iss, aud/azp, exp), not from unsigned userinfo.
        try {
            /** @var KeycloakJwtGuard $guard */
            $guard = Auth::guard('keycloak-jwt');
            $claims = $guard->decode((string) $kcUser->token);
        } catch (Throwable $e) {
            Log::warning('auth.login_rejected', ['reason' => $e->getMessage()]);
            abort(401, 'Login token could not be verified.');
        }

        $user = $provisioner->provision($claims); // no base role ⇒ AuthorizationException ⇒ 403
        abort_unless($user->is_active, 403, 'Your account is deactivated.');

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('keycloak_id_token', $kcUser->accessTokenResponseBody['id_token'] ?? null);

        return redirect()->intended('/');
    }

    /** Local logout, then Keycloak RP-initiated logout with id_token_hint (ends the SSO session). */
    public function logout(Request $request): SymfonyRedirect
    {
        $idToken = $request->session()->get('keycloak_id_token');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        /** @var KeycloakProvider $provider */
        $provider = Socialite::driver('keycloak');

        return redirect()->away($provider->getLogoutUrl(rtrim((string) config('app.url'), '/').'/', (string) config('keycloak.client_id'), $idToken));
    }
}

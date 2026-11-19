<?php

declare(strict_types=1);

namespace App\Auth;

use SocialiteProviders\Keycloak\Provider;

/**
 * Splits the browser-facing URL (public_url: authorize, logout) from the
 * back-channel URL (base_url: token, userinfo); they differ inside Docker.
 */
final class KeycloakProvider extends Provider
{
    /** PKCE (S256) on top of the client secret; required by the lms-web client. */
    protected $usesPKCE = true;

    public static function additionalConfigKeys(): array
    {
        return ['base_url', 'public_url', 'realms'];
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->publicRealmUrl().'/protocol/openid-connect/auth', $state);
    }

    public function getLogoutUrl(?string $redirectUri = null, ?string $clientId = null, ?string $idTokenHint = null, ...$additionalParameters): string
    {
        return str_replace($this->getBaseUrl(), $this->publicRealmUrl(), parent::getLogoutUrl($redirectUri, $clientId, $idTokenHint, ...$additionalParameters));
    }

    private function publicRealmUrl(): string
    {
        return rtrim((string) $this->getConfig('public_url', $this->getConfig('base_url')), '/').'/realms/'.$this->getConfig('realms', 'master');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Module;
use App\Support\Modules;
use Closure;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `module:<key>`: 404 when the module is off (D-17; problem+json on /api/*). Runs before auth (D-40 g). */
final class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(app(Modules::class)->enabled(Module::from($module)) || $this->isFirstPartyApiCall($request, $module), 404);

        return $next($request);
    }

    /**
     * `api` gates third-party clients only (D-43 g): a /api/v1 call whose token `azp` is the BFF client passes.
     * The payload is read UNVERIFIED, only to pick 404 vs continue; every /api/v1 route then runs
     * `auth:keycloak-jwt`, so a forged `azp` ends in 401. /api/docs and /api/openapi.yaml have no auth: never bypassed.
     */
    private function isFirstPartyApiCall(Request $request, string $module): bool
    {
        if ($module !== Module::Api->value || ! $request->is('api/v1/*')) {
            return false;
        }
        $payload = explode('.', (string) $request->bearerToken())[1] ?? '';
        $claims = json_decode(JWT::urlsafeB64Decode($payload), true);

        return is_array($claims) && ($claims['azp'] ?? null) === config('keycloak.client_id');
    }
}

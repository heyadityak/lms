<?php

declare(strict_types=1);

namespace App\Support\Keycloak;

use App\Exceptions\DomainException;
use App\Exceptions\IdpConflict;
use App\Exceptions\IdpReadOnly;
use App\Exceptions\IdpUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Admin REST wrapper. Client-credentials token cached until 30 s before expiry (D-22). */
final class HttpKeycloakAdminClient implements KeycloakAdminClient
{
    private const TOKEN_KEY = 'keycloak:admin-token';

    /** Role-membership has no count endpoint; don't scan an unbounded directory. */
    private const ROLE_SCAN_CAP = 1000;

    public function searchUsers(string $q, int $first, int $max, array $filters = []): array
    {
        if (isset($filters['role'])) {
            $users = $this->send('GET', '/roles/'.rawurlencode($filters['role']).'/users', [
                'first' => 0,
                'max' => self::ROLE_SCAN_CAP,
            ])->json();

            return array_slice($this->filterReps(is_array($users) ? $users : [], $q, $filters['enabled'] ?? null), $first, $max);
        }

        $query = ['first' => $first, 'max' => $max, 'briefRepresentation' => 'false'];
        if ($q !== '') {
            $query['search'] = $q;
        }
        if (array_key_exists('enabled', $filters)) {
            $query['enabled'] = $filters['enabled'] ? 'true' : 'false';
        }

        $users = $this->send('GET', '/users', $query)->json();

        return is_array($users) ? array_values($users) : [];
    }

    public function countUsers(string $q, array $filters = []): int
    {
        if (isset($filters['role'])) {
            return count($this->searchUsers($q, 0, self::ROLE_SCAN_CAP, $filters));
        }

        $query = [];
        if ($q !== '') {
            $query['search'] = $q;
        }
        if (array_key_exists('enabled', $filters)) {
            $query['enabled'] = $filters['enabled'] ? 'true' : 'false';
        }
        $body = $this->send('GET', '/users/count', $query)->json();

        return is_numeric($body) ? (int) $body : 0;
    }

    public function getUser(string $id): array
    {
        $user = $this->send('GET', '/users/'.rawurlencode($id))->json();

        return is_array($user) ? $user : [];
    }

    public function createUser(array $representation): string
    {
        unset($representation['credentials']);
        $response = $this->send('POST', '/users', [], $representation);
        $location = (string) $response->header('Location');
        $id = $location === '' ? '' : basename(parse_url($location, PHP_URL_PATH) ?: '');
        if ($id === '') {
            throw new IdpUnavailable('The identity provider did not return a user id.');
        }

        return $id;
    }

    public function updateUser(string $id, array $representation): void
    {
        unset($representation['credentials']);
        $current = $this->getUser($id);
        $this->send('PUT', '/users/'.rawurlencode($id), [], array_merge($current, $representation));
    }

    public function deleteUser(string $id): void
    {
        $this->send('DELETE', '/users/'.rawurlencode($id));
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $this->updateUser($id, ['enabled' => $enabled]);
    }

    public function logoutUser(string $id): void
    {
        $this->send('POST', '/users/'.rawurlencode($id).'/logout');
    }

    public function executeActionsEmail(string $id, array $actions): void
    {
        $lifespan = (int) config('keycloak.actions_email_lifespan', 86400);
        $this->send('PUT', '/users/'.rawurlencode($id).'/execute-actions-email', ['lifespan' => $lifespan], $actions);
    }

    public function realmRolesOf(string $id): array
    {
        $roles = $this->send('GET', '/users/'.rawurlencode($id).'/role-mappings/realm')->json();
        if (! is_array($roles)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $role): string => is_array($role) ? (string) ($role['name'] ?? '') : '',
            $roles,
        )));
    }

    public function addRealmRoles(string $id, array $names): void
    {
        if ($names === []) {
            return;
        }
        $this->send('POST', '/users/'.rawurlencode($id).'/role-mappings/realm', [], $this->roleReps($names));
    }

    public function removeRealmRoles(string $id, array $names): void
    {
        if ($names === []) {
            return;
        }
        $this->send('DELETE', '/users/'.rawurlencode($id).'/role-mappings/realm', [], $this->roleReps($names));
    }

    /** @param  list<string>  $names
     * @return list<array{id: string, name: string}>
     */
    private function roleReps(array $names): array
    {
        $reps = [];
        foreach ($names as $name) {
            $role = $this->send('GET', '/roles/'.rawurlencode($name))->json();
            if (! is_array($role) || ! isset($role['id'], $role['name'])) {
                throw new DomainException('That realm role is not available in the identity provider.', 'VALIDATION_FAILED', 422, ['roles' => ['That realm role is not available in the identity provider.']]);
            }
            $reps[] = ['id' => (string) $role['id'], 'name' => (string) $role['name']];
        }

        return $reps;
    }

    /**
     * @param  list<array<string, mixed>>  $users
     * @return list<array<string, mixed>>
     */
    private function filterReps(array $users, string $q, ?bool $enabled): array
    {
        $needle = mb_strtolower($q);

        return array_values(array_filter($users, function (array $user) use ($needle, $enabled): bool {
            if ($enabled !== null && (bool) ($user['enabled'] ?? false) !== $enabled) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            $haystack = mb_strtolower(implode(' ', [
                (string) ($user['username'] ?? ''),
                (string) ($user['email'] ?? ''),
                (string) ($user['firstName'] ?? ''),
                (string) ($user['lastName'] ?? ''),
            ]));

            return str_contains($haystack, $needle);
        }));
    }

    /** @param  array<string, mixed>|list<mixed>  $body */
    private function send(string $method, string $path, array $query = [], array $body = [], bool $retry = true): Response
    {
        $pending = Http::withToken($this->adminToken())->acceptJson()->timeout(5)->connectTimeout(3);
        $url = $this->adminUrl($path);
        if ($query !== [] && $method !== 'GET') {
            $url .= '?'.http_build_query($query);
        }

        try {
            $response = match ($method) {
                'GET' => $pending->get($url, $query),
                'POST' => $pending->post($url, $body),
                'PUT' => $pending->put($url, $body),
                'DELETE' => $pending->delete($url, $body),
                default => throw new \LogicException($method),
            };
        } catch (ConnectionException $e) {
            throw new IdpUnavailable(previous: $e);
        }

        if ($response->status() === 401 && $retry) {
            Cache::forget(self::TOKEN_KEY);

            return $this->send($method, $path, $query, $body, false);
        }

        $this->interpret($response, $path);

        return $response;
    }

    private function interpret(Response $response, string $path): void
    {
        $status = $response->status();
        if ($status < 400) {
            return;
        }
        if ($status === 409) {
            throw new IdpConflict;
        }
        if ($status === 404) {
            if (preg_match('#/roles/[^/]+\z#', $path) === 1) {
                throw new DomainException('That realm role is not available in the identity provider.', 'VALIDATION_FAILED', 422, ['roles' => ['That realm role is not available in the identity provider.']]);
            }
            throw new DomainException('The requested resource was not found.', 'NOT_FOUND', 404);
        }
        if ($status === 400 || $status === 403) {
            $body = strtolower($response->body());
            if (str_contains($body, 'read-only') || str_contains($body, 'readonly') || str_contains($body, 'federat')) {
                throw new IdpReadOnly;
            }
        }
        if ($status >= 500 || $status === 429 || $status === 401) {
            throw new IdpUnavailable;
        }

        throw new DomainException('The identity provider rejected the change.', 'VALIDATION_FAILED', 422);
    }

    private function adminToken(): string
    {
        $cached = Cache::get(self::TOKEN_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = rtrim((string) config('keycloak.base_url'), '/').'/realms/'.config('keycloak.realm').'/protocol/openid-connect/token';
        try {
            $response = Http::asForm()->acceptJson()->timeout(5)->connectTimeout(3)->post($url, [
                'grant_type' => 'client_credentials',
                'client_id' => config('keycloak.admin_client_id'),
                'client_secret' => config('keycloak.admin_client_secret'),
            ]);
        } catch (ConnectionException $e) {
            throw new IdpUnavailable(previous: $e);
        }
        if (! $response->successful()) {
            throw new IdpUnavailable('The identity provider rejected the admin login.');
        }

        $token = (string) $response->json('access_token');
        if ($token === '') {
            throw new IdpUnavailable('The identity provider did not return an admin token.');
        }
        $ttl = max(1, ((int) $response->json('expires_in', 60)) - 30);
        Cache::put(self::TOKEN_KEY, $token, $ttl);

        return $token;
    }

    private function adminUrl(string $path): string
    {
        return rtrim((string) config('keycloak.base_url'), '/').'/admin/realms/'.config('keycloak.realm').$path;
    }
}

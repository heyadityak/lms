<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Exceptions\DomainException;
use App\Exceptions\IdpConflict;
use App\Exceptions\IdpReadOnly;
use App\Exceptions\IdpUnavailable;
use App\Support\Keycloak\KeycloakAdminClient;
use Illuminate\Support\Str;

/**
 * In-memory Keycloak Admin API. Feature tests bind this; nothing here opens a socket.
 * Set `$unavailable` to make every call throw 503 IDP_UNAVAILABLE.
 */
final class FakeKeycloakAdminClient implements KeycloakAdminClient
{
    /** @var array<string, array<string, mixed>> */
    public array $users = [];

    /** @var list<array{id: string, actions: list<string>}> */
    public array $actionEmails = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<string> */
    public array $loggedOut = [];

    public bool $unavailable = false;

    public function searchUsers(string $q, int $first, int $max, array $filters = []): array
    {
        $this->guard();

        return array_slice($this->matching($q, $filters), $first, $max);
    }

    public function countUsers(string $q, array $filters = []): int
    {
        $this->guard();

        return count($this->matching($q, $filters));
    }

    public function getUser(string $id): array
    {
        $this->guard();
        if (! isset($this->users[$id])) {
            throw new DomainException('The requested resource was not found.', 'NOT_FOUND', 404);
        }

        return $this->users[$id];
    }

    public function createUser(array $representation): string
    {
        $this->guard();
        if (array_key_exists('credentials', $representation)) {
            throw new \LogicException('The LMS must not send a password to Keycloak.');
        }
        $email = (string) ($representation['email'] ?? '');
        foreach ($this->users as $user) {
            $sameUsername = strcasecmp((string) ($user['username'] ?? ''), (string) ($representation['username'] ?? '')) === 0;
            $sameEmail = $email !== '' && strcasecmp((string) ($user['email'] ?? ''), $email) === 0;
            if ($sameUsername || $sameEmail) {
                throw new IdpConflict;
            }
        }

        $id = (string) Str::uuid();
        $this->users[$id] = $representation + ['id' => $id, 'realmRoles' => [], 'emailVerified' => false, 'enabled' => true];

        return $id;
    }

    public function updateUser(string $id, array $representation): void
    {
        $this->guard();
        $current = $this->getUser($id);
        if (filled($current['federationLink'] ?? null) && array_intersect_key($representation, array_flip(['email', 'firstName', 'lastName', 'username'])) !== []) {
            throw new IdpReadOnly;
        }
        unset($representation['credentials']);
        $this->users[$id] = array_merge($current, $representation);
    }

    public function deleteUser(string $id): void
    {
        $this->guard();
        unset($this->users[$id]);
        $this->deleted[] = $id;
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $this->guard();
        $this->getUser($id);
        $this->users[$id]['enabled'] = $enabled;
    }

    public function logoutUser(string $id): void
    {
        $this->guard();
        $this->getUser($id);
        $this->loggedOut[] = $id;
    }

    public function executeActionsEmail(string $id, array $actions): void
    {
        $this->guard();
        $this->getUser($id);
        $this->actionEmails[] = ['id' => $id, 'actions' => array_values($actions)];
    }

    public function realmRolesOf(string $id): array
    {
        $this->guard();
        $user = $this->getUser($id);

        return array_values(array_map(strval(...), (array) ($user['realmRoles'] ?? [])));
    }

    public function addRealmRoles(string $id, array $names): void
    {
        $this->guard();
        $this->getUser($id);
        $current = (array) ($this->users[$id]['realmRoles'] ?? []);
        $this->users[$id]['realmRoles'] = array_values(array_unique([...$current, ...$names]));
    }

    public function removeRealmRoles(string $id, array $names): void
    {
        $this->guard();
        $this->getUser($id);
        $this->users[$id]['realmRoles'] = array_values(array_diff((array) $this->users[$id]['realmRoles'], $names));
    }

    /** @param  array{enabled?: bool, role?: string}  $filters
     * @return list<array<string, mixed>>
     */
    private function matching(string $q, array $filters): array
    {
        $needle = mb_strtolower($q);
        $matched = [];
        foreach ($this->users as $user) {
            if (isset($filters['enabled']) && (bool) ($user['enabled'] ?? false) !== $filters['enabled']) {
                continue;
            }
            if (isset($filters['role']) && ! in_array($filters['role'], (array) ($user['realmRoles'] ?? []), true)) {
                continue;
            }
            if ($needle !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($user['username'] ?? ''),
                    (string) ($user['email'] ?? ''),
                    (string) ($user['firstName'] ?? ''),
                    (string) ($user['lastName'] ?? ''),
                ]));
                if (! str_contains($haystack, $needle)) {
                    continue;
                }
            }
            $matched[] = $user;
        }

        return $matched;
    }

    private function guard(): void
    {
        if ($this->unavailable) {
            throw new IdpUnavailable;
        }
    }
}

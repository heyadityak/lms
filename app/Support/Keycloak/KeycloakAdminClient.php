<?php

declare(strict_types=1);

namespace App\Support\Keycloak;

/**
 * Keycloak Admin REST API (service account `lms-admin`). The only way the app talks to it (D-22).
 * Implementations throw IdpConflict, IdpUnavailable or IdpReadOnly — never the Keycloak body.
 */
interface KeycloakAdminClient
{
    /**
     * @param  array{enabled?: bool, role?: string}  $filters
     * @return list<array<string, mixed>>
     */
    public function searchUsers(string $q, int $first, int $max, array $filters = []): array;

    /** @param  array{enabled?: bool, role?: string}  $filters */
    public function countUsers(string $q, array $filters = []): int;

    /** @return array<string, mixed> */
    public function getUser(string $id): array;

    /** @param  array<string, mixed>  $representation */
    public function createUser(array $representation): string;

    /** @param  array<string, mixed>  $representation  partial; the HTTP client merges it onto the current user */
    public function updateUser(string $id, array $representation): void;

    public function deleteUser(string $id): void;

    public function setEnabled(string $id, bool $enabled): void;

    public function logoutUser(string $id): void;

    /** @param  list<string>  $actions */
    public function executeActionsEmail(string $id, array $actions): void;

    /** @return list<string> realm role names */
    public function realmRolesOf(string $id): array;

    /** @param  list<string>  $names */
    public function addRealmRoles(string $id, array $names): void;

    /** @param  list<string>  $names */
    public function removeRealmRoles(string $id, array $names): void;
}

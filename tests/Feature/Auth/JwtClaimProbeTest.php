<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\KeycloakJwtGuard;
use App\Models\LeaveLedgerEntry;
use App\Models\User;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeKeycloak;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/** Phase 7 QA: validly signed tokens with hostile claims (typ/azp of other clients, date_of_joining abuse). */
final class JwtClaimProbeTest extends TestCase
{
    use FakeKeycloak, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 09:00:00');
        $this->fakeKeycloak();
        $this->seed(LeaveTypeSeeder::class);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function foreignTokens(): array
    {
        return [
            'refresh token' => [['typ' => 'Refresh']],
            'offline token' => [['typ' => 'Offline']],
            'logout token' => [['typ' => 'Logout']],
            'no typ' => [['typ' => null]],
            'lowercase bearer' => [['typ' => 'bearer']],
            'lms-admin service account' => [['azp' => 'lms-admin', 'aud' => ['realm-management', 'account']]],
            'other client, broker aud' => [['azp' => 'other-app', 'aud' => ['broker', 'account']]],
            'issuer with trailing slash' => [['iss' => '{issuer}/']],
            'issuer prefix only' => [['iss' => 'http://localhost:8081/realms/lm']],
            'other realm' => [['iss' => 'http://localhost:8081/realms/master']],
        ];
    }

    /** @param  array<string, mixed>  $claims */
    #[DataProvider('foreignTokens')]
    public function test_signed_token_for_another_purpose_or_client_is_401(array $claims): void
    {
        if (isset($claims['iss'])) {
            $claims['iss'] = str_replace('{issuer}', KeycloakJwtGuard::issuer(), $claims['iss']);
        }
        $response = $this->withToken($this->jwt($claims, ['hr_admin']))->getJson('/api/v1/me')
            ->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->assertMatchesSpec($response, '/me');
        $this->assertDatabaseCount('users', 0);
    }

    private function me(mixed $doj, string $sub = 'sub-new'): TestResponse
    {
        return $this->withToken($this->jwt(['sub' => $sub, 'email' => "{$sub}@example.test", 'date_of_joining' => $doj]))->getJson('/api/v1/me');
    }

    private function ledgerCount(string $sub = 'sub-new'): int
    {
        return LeaveLedgerEntry::query()->whereIn('user_id', User::query()->select('id')->where('keycloak_sub', $sub))->count();
    }

    /** @return array<string, array{0: mixed}> */
    public static function garbageDates(): array
    {
        return [
            'impossible date' => ['2026-02-30'], 'two-digit year' => ['26-07-01'], 'datetime' => ['2026-07-01T00:00:00Z'],
            'unpadded' => ['2026-7-1'], 'epoch int' => [1782864000], 'array' => [['2026-07-01']], 'empty' => [''],
            'sql' => ["2026-07-01' OR '1'='1"],
        ];
    }

    #[DataProvider('garbageDates')]
    public function test_garbage_joining_date_claim_is_ignored(mixed $doj): void
    {
        $this->me($doj)->assertOk()->assertJsonPath('data.date_of_joining', null);
        $this->assertSame(0, $this->ledgerCount());
    }

    /**
     * KeycloakUserProvisioner::dateClaim: `$` in /^\d{4}-\d{2}-\d{2}$/ also matches before a trailing "\n", then
     * CarbonImmutable::createFromFormat throws "Trailing data" → 500 on every API call (and web login) for that user.
     */
    public function test_joining_date_claim_with_trailing_newline_is_ignored_not_a_500(): void
    {
        $this->me("2026-07-01\n")->assertOk()->assertJsonPath('data.date_of_joining', null);
        $this->assertSame(0, $this->ledgerCount());
    }

    public function test_future_joining_date_is_stored_without_a_grant(): void
    {
        $this->me('2027-02-01')->assertOk()->assertJsonPath('data.date_of_joining', '2027-02-01');
        $this->assertSame(0, $this->ledgerCount());
    }

    public function test_joining_date_in_the_far_past_gets_full_quota_once(): void
    {
        $this->me('1900-01-01')->assertOk();
        $this->me('1900-01-01')->assertOk();
        $this->assertSame(3, $this->ledgerCount());
        $this->assertEqualsCanonicalizing([10.0, 15.0, 30.0], LeaveLedgerEntry::query()->pluck('days')->map(fn ($d): float => (float) $d)->all());
    }

    public function test_claim_never_overrides_a_known_joining_date(): void
    {
        User::factory()->create(['keycloak_sub' => 'sub-new', 'email' => 'sub-new@example.test', 'date_of_joining' => '2026-07-01']);
        $this->me('2020-01-01')->assertOk()->assertJsonPath('data.date_of_joining', '2026-07-01');
        $this->assertSame(0, $this->ledgerCount());
    }

    /** USE_CASES §3.9: a deactivated user is refused and excluded from accrual; the refused call must not write. */
    public function test_deactivated_users_token_has_no_provisioning_side_effects(): void
    {
        $user = User::factory()->inactive()->create(['keycloak_sub' => 'sub-new', 'email' => 'sub-new@example.test', 'date_of_joining' => null, 'last_login_at' => null]);

        $this->me('2026-07-01')->assertUnauthorized();

        $user->refresh();
        $this->assertSame(0, $this->ledgerCount(), 'Inactive user received an entitlement grant.');
        $this->assertNull($user->date_of_joining);
        $this->assertNull($user->last_login_at);
    }
}

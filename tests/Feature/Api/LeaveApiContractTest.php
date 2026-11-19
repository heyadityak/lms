<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Auth\JwksCache;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\LeaveTestHelpers;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/** Phase 7 QA: openapi.yaml contract for every phase-6 endpoint (errors too), pagination, query validation, idempotency, security probes. */
final class LeaveApiContractTest extends TestCase
{
    use LeaveTestHelpers, RefreshDatabase, ValidatesOpenApi;

    private const ROUTES = [
        ['get', '/me', '/me'],
        ['get', '/me/balances', '/me/balances'],
        ['get', '/me/leave-requests', '/me/leave-requests'],
        ['get', '/leave-types', '/leave-types'],
        ['get', '/holidays', '/holidays'],
        ['get', '/leave-requests', '/leave-requests'],
        ['post', '/leave-requests', '/leave-requests'],
        ['get', '/leave-requests/{id}', '/leave-requests/{leaveRequestId}'],
        ['post', '/leave-requests/{id}/approve', '/leave-requests/{leaveRequestId}/approve'],
        ['post', '/leave-requests/{id}/reject', '/leave-requests/{leaveRequestId}/reject'],
        ['post', '/leave-requests/{id}/cancel', '/leave-requests/{leaveRequestId}/cancel'],
        ['get', '/approvals', '/approvals'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLeave();
    }

    private function problem(TestResponse $response, int $status, string $code, string $path, string $method = 'get'): void
    {
        $response->assertStatus($status)->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', $code);
        $this->assertMatchesSpec($response, $path, $method);
    }

    private static function ulid(): string
    {
        return strtoupper((string) Str::ulid());
    }

    public function test_every_endpoint_answers_401_problem_without_a_token(): void
    {
        $id = self::ulid();
        foreach (self::ROUTES as [$method, $uri, $template]) {
            $response = $this->json(strtoupper($method), '/api/v1'.str_replace('{id}', $id, $uri), $method === 'post' ? ['comment' => 'x'] : []);
            $this->problem($response, 401, 'UNAUTHENTICATED', $template, $method);
        }
    }

    public function test_every_endpoint_answers_404_problem_when_api_module_is_off(): void
    {
        $this->setting('modules.disabled', ['api']);
        $id = self::ulid();
        foreach (self::ROUTES as [$method, $uri, $template]) {
            $response = $this->api($this->alice)->json(strtoupper($method), '/api/v1'.str_replace('{id}', $id, $uri));
            $this->problem($response, 404, 'NOT_FOUND', $template, $method);
        }
    }

    public function test_unknown_numeric_lowercase_and_garbage_ids_are_404_on_every_route(): void
    {
        $real = $this->apply($this->alice)->assertCreated()->json('data.id');
        $internalId = (string) LeaveRequest::query()->sole()->id;
        foreach ([self::ulid(), $internalId, strtolower($real), "1' OR '1'='1", str_repeat('Z', 300)] as $id) {
            $this->problem($this->api($this->carol)->getJson('/api/v1/leave-requests/'.rawurlencode($id)), 404, 'NOT_FOUND', '/leave-requests/{leaveRequestId}');
            foreach (['approve', 'reject', 'cancel'] as $action) {
                $this->problem($this->api($this->carol)->postJson('/api/v1/leave-requests/'.rawurlencode($id)."/{$action}", ['comment' => 'Probe']), 404, 'NOT_FOUND', "/leave-requests/{leaveRequestId}/{$action}", 'post');
            }
        }
    }

    /** API_GUIDELINES §5: "404 … so ids cannot be probed". The body must not tell "exists but hidden" from "does not exist". */
    public function test_404_body_does_not_reveal_whether_an_id_exists_or_internal_class_names(): void
    {
        $real = $this->apply($this->alice)->assertCreated()->json('data.id');
        $stranger = User::factory()->create();
        $fields = fn (TestResponse $r): array => array_intersect_key($r->assertNotFound()->json(), array_flip(['type', 'title', 'status', 'detail', 'code']));

        $hidden = $fields($this->api($stranger)->getJson("/api/v1/leave-requests/{$real}"));
        $missing = $this->api($stranger)->getJson('/api/v1/leave-requests/'.self::ulid());

        $this->assertStringNotContainsString('Models', (string) $missing->json('detail'));
        $this->assertSame($hidden, $fields($missing));
    }

    public function test_pagination_meta_and_links(): void
    {
        foreach (['2026-12-01', '2026-12-02', '2026-12-03'] as $date) {
            $this->apply($this->alice, ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => $date, 'end_date' => $date])->assertCreated();
        }

        $page = $this->api($this->alice)->getJson('/api/v1/me/leave-requests?sort=start_date&page[size]=1&page[number]=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.start_date', '2026-12-02')
            ->assertJsonPath('meta', ['current_page' => 2, 'per_page' => 1, 'total' => 3, 'last_page' => 3]);
        $this->assertMatchesSpec($page, '/me/leave-requests');
        foreach (['first' => 1, 'prev' => 1, 'next' => 3, 'last' => 3] as $rel => $number) {
            parse_str((string) parse_url((string) $page->json("links.{$rel}"), PHP_URL_QUERY), $query);
            $this->assertSame(['sort' => 'start_date', 'page' => ['size' => '1', 'number' => (string) $number]], $query, $rel);
        }

        $last = $this->api($this->alice)->getJson('/api/v1/leave-requests?page[size]=100')->assertOk()->assertJsonPath('meta.per_page', 100)->assertJsonPath('links.next', null)->assertJsonPath('links.prev', null);
        $this->assertMatchesSpec($last, '/leave-requests');
        $beyond = $this->api($this->bob)->getJson('/api/v1/approvals?page[number]=50')->assertOk()->assertJsonCount(0, 'data');
        $this->assertMatchesSpec($beyond, '/approvals');
    }

    public function test_sort_orders_and_default_is_newest_start_first(): void
    {
        foreach (['2026-12-02' => '2026-12-04', '2026-12-07' => '2026-12-07', '2026-11-30' => '2026-11-30'] as $start => $end) {
            $this->apply($this->alice, ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => $start, 'end_date' => $end])->assertCreated();
        }
        $col = fn (string $q, string $field): array => array_column($this->api($this->alice)->getJson('/api/v1/me/leave-requests'.$q)->assertOk()->json('data'), $field);

        $this->assertSame(['2026-12-07', '2026-12-02', '2026-11-30'], $col('', 'start_date'));
        $this->assertSame([3, 1, 1], $col('?sort=-days', 'days'));
        $this->assertSame(['2026-11-30', '2026-12-02', '2026-12-07'], $col('?sort=start_date', 'start_date'));
    }

    /** @return list<array{0: string, 1: string}> */
    private static function invalidQueries(): array
    {
        return [
            ['/leave-requests?sort=bogus', '/leave-requests'],
            ['/leave-requests?sort=reason', '/leave-requests'],
            ['/leave-requests?sort=start_date;DROP TABLE users', '/leave-requests'],
            ['/leave-requests?include=secrets', '/leave-requests'],
            ['/leave-requests?filter[status]=done', '/leave-requests'],
            ['/leave-requests?filter[year]=abc', '/leave-requests'],
            ['/leave-requests?filter[from]=2026-02-31', '/leave-requests'],
            ['/leave-requests?page[size]=0', '/leave-requests'],
            ['/leave-requests?page[size]=101', '/leave-requests'],
            ['/leave-requests?page[number]=0', '/leave-requests'],
            ['/leave-requests?page[foo]=1', '/leave-requests'],
            ['/me/leave-requests?filter[user]=01ARZ3NDEKTSV4RRFFQ69G5FAV', '/me/leave-requests'],
            ['/me/leave-requests?sort=-reason', '/me/leave-requests'],
            ['/approvals?filter[status]=approved', '/approvals'],
            ['/approvals?filter[year]=2026', '/approvals'],
            ['/me/balances?filter[year]=1999', '/me/balances'],
            ['/me/balances?filter[type]=CL', '/me/balances'],
            ['/holidays?filter[year]=x', '/holidays'],
        ];
    }

    public function test_invalid_query_parameters_are_422_validation_failed(): void
    {
        foreach (self::invalidQueries() as [$uri, $template]) {
            $user = str_starts_with($uri, '/approvals') ? $this->bob : $this->alice;
            $response = $this->api($user)->getJson('/api/v1'.$uri);
            $this->assertSame(422, $response->status(), $uri);
            $this->problem($response, 422, 'VALIDATION_FAILED', $template);
        }
    }

    /** openapi.yaml GET /leave-types declares no 422 response, but the endpoint validates `filter[]` (spec gap, architect). */
    public function test_leave_types_invalid_filter_is_422_problem(): void
    {
        $this->api($this->alice)->getJson('/api/v1/leave-types?filter[is_active]=maybe')
            ->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    /** PageNumber has no maximum in the spec: a page past the end is an empty page, never page 1 again (int overflow in OFFSET). */
    public function test_huge_page_number_does_not_wrap_around_to_the_first_page(): void
    {
        $this->apply($this->alice)->assertCreated();
        $response = $this->api($this->alice)->getJson('/api/v1/leave-requests?page[number]=9223372036854775807');

        $this->assertContains($response->status(), [200, 422]);
        if ($response->status() === 200) {
            $response->assertJsonCount(0, 'data');
        }
    }

    public function test_submit_body_validation_shapes(): void
    {
        $bad = [
            ['day_part' => 'morning'], ['start_date' => '01/12/2026'], ['end_date' => '2026-02-30'], ['leave_type_id' => self::ulid()],
            ['reason' => str_repeat('a', 1001)], ['handover_note' => str_repeat('a', 2001)], ['reason' => ['array']],
        ];
        foreach ($bad as $override) {
            $this->problem($this->apply($this->alice, $override), 422, 'VALIDATION_FAILED', '/leave-requests', 'post');
        }
        $this->problem($this->api($this->alice)->postJson('/api/v1/leave-requests', []), 422, 'VALIDATION_FAILED', '/leave-requests', 'post');
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_mass_assigned_fields_on_submit_are_ignored(): void
    {
        $forged = self::ulid();
        $response = $this->apply($this->alice, [
            'id' => $forged, 'public_id' => $forged, 'user_id' => $this->bob->id, 'user' => ['id' => $this->bob->public_id],
            'status' => 'approved', 'days' => 0.5, 'decided_by' => $this->bob->id, 'decided_at' => '2026-11-01T00:00:00Z',
            'decision_comment' => 'auto', 'cancelled_at' => '2026-11-01T00:00:00Z', 'leave_year' => 1999,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.days', 5)
            ->assertJsonPath('data.user.id', $this->alice->public_id)->assertJsonPath('data.decided_by', null)
            ->assertJsonPath('data.decision_comment', null)->assertJsonPath('data.cancelled_at', null)->assertJsonPath('data.leave_year', 2026);
        $this->assertMatchesSpec($response, '/leave-requests', 'post');
        $this->assertNotSame($forged, $response->json('data.id'));
        $row = LeaveRequest::query()->sole();
        $this->assertSame([$this->alice->id, null], [$row->user_id, $row->decided_by]);
        $this->assertSame(0, $row->ledgerEntries()->count());
    }

    public function test_real_submit_idempotency_replay_reuse_and_rule_violation_replay(): void
    {
        config(['cache.default' => 'redis']); // the real store (test Redis DB, phpunit.xml)
        // JWKS from an earlier test's random key outlives it in Redis: start from a clean key cache.
        Cache::forget(JwksCache::CACHE_KEY);
        Cache::lock(JwksCache::REFRESH_LOCK)->forceRelease();
        $key = (string) Str::uuid();
        $body = ['leave_type_id' => $this->type('EL')->public_id, 'start_date' => '2026-12-21', 'end_date' => '2026-12-24', 'day_part' => 'full', 'reason' => 'Trip'];

        $first = $this->api($this->alice)->postJson('/api/v1/leave-requests', $body, ['Idempotency-Key' => $key])->assertCreated();
        $replay = $this->api($this->alice)->postJson('/api/v1/leave-requests', $body, ['Idempotency-Key' => $key])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertMatchesSpec($replay, '/leave-requests', 'post');
        $this->assertSame($first->json(), $replay->json());

        $reused = $this->api($this->alice)->postJson('/api/v1/leave-requests', ['reason' => 'Different'] + $body, ['Idempotency-Key' => $key]);
        $this->problem($reused, 422, 'IDEMPOTENCY_KEY_REUSED', '/leave-requests', 'post');
        $this->assertSame(1, LeaveRequest::query()->count());

        // Rule violations (422) are stored too: the replay does not re-run the rules.
        $key2 = (string) Str::uuid();
        $this->problem($this->api($this->alice)->postJson('/api/v1/leave-requests', $body, ['Idempotency-Key' => $key2]), 422, 'LEAVE_OVERLAP', '/leave-requests', 'post');
        $this->api($this->alice)->postJson('/api/v1/leave-requests', $body, ['Idempotency-Key' => $key2])->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true');

        // Another user's identical key is a different slot.
        $this->api($this->bob)->postJson('/api/v1/leave-requests', $body, ['Idempotency-Key' => $key])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(2, LeaveRequest::query()->count());
    }

    /** pdo_pgsql cuts strings at U+0000: the API answers 201 echoing "Trip\0home" while the DB stores "Trip". Refuse it (422). */
    public function test_nul_byte_in_reason_is_refused_not_silently_truncated(): void
    {
        $this->problem($this->apply($this->alice, ['reason' => "Trip\u{0000}home"]), 422, 'VALIDATION_FAILED', '/leave-requests', 'post');
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_error_responses_never_carry_debug_fields_even_with_app_debug(): void
    {
        config(['app.debug' => true]);
        $id = $this->apply($this->alice)->json('data.id');
        $responses = [
            $this->api($this->alice)->postJson("/api/v1/leave-requests/{$id}/approve"),      // 403
            $this->api($this->alice)->getJson('/api/v1/leave-requests/'.self::ulid()),         // 404
            $this->apply($this->alice),                                                         // 422 overlap
            $this->api($this->alice)->getJson('/api/v1/leave-requests?sort=x'),                // 422 validation
            $this->api($this->alice)->deleteJson("/api/v1/leave-requests/{$id}"),              // 405
        ];
        $this->api($this->alice)->postJson("/api/v1/leave-requests/{$id}/cancel")->assertOk();
        $responses[] = $this->api($this->alice)->postJson("/api/v1/leave-requests/{$id}/cancel"); // 409

        foreach ($responses as $response) {
            $response->assertHeader('Content-Type', 'application/problem+json');
            $this->assertSame([], array_intersect(array_keys($response->json()), ['trace', 'exception', 'file', 'line']), (string) $response->getContent());
            $this->assertStringNotContainsString('.php', (string) $response->getContent());
        }
    }
}

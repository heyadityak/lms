<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Exceptions\LeaveRuleViolation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/** `idempotent` (EnsureIdempotency, API_GUIDELINES §7) against the real Redis cache store. */
final class IdempotencyTest extends TestCase
{
    use ValidatesOpenApi;

    private int $calls = 0;

    /** Status the test route answers with next. */
    private int $answer = 201;

    private ?TestResponse $inFlight = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'redis']); // exercise the real store (SET NX, serialisation, TTL)

        Route::middleware(['api', 'idempotent'])->prefix('api/_test')->group(function (): void {
            Route::post('/orders', function (Request $r) {
                $this->calls++;
                if ($this->answer === 422) {
                    throw new LeaveRuleViolation('INSUFFICIENT_BALANCE', 'Requested 5 days, available 4.', 'end_date');
                }

                return response()->json(['data' => ['id' => $this->calls, 'qty' => $r->input('qty')]], $this->answer, ['Location' => '/orders/'.$this->calls]);
            });
            Route::post('/slow', function () {
                $this->calls++;
                // A duplicate arriving while this request is still running.
                $this->inFlight = $this->postJson('/api/_test/slow', ['qty' => 1], ['Idempotency-Key' => request()->header('Idempotency-Key')]);

                return response()->json(['data' => ['ok' => true]], 201);
            });
        });
    }

    private function order(string $key, int $qty = 2): TestResponse
    {
        return $this->postJson('/api/_test/orders', ['qty' => $qty], ['Idempotency-Key' => $key]);
    }

    public function test_same_key_and_payload_replays_stored_response_without_reexecuting(): void
    {
        $key = Str::uuid()->toString();

        $first = $this->order($key)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->order($key)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertHeader('Location', '/orders/1')
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson($first->json());

        $this->assertSame(1, $this->calls);
    }

    public function test_same_key_with_different_payload_is_422_key_reused(): void
    {
        $key = Str::uuid()->toString();

        $this->order($key)->assertCreated();
        $response = $this->order($key, 3)
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED')
            ->assertJsonPath('errors.Idempotency-Key.0', 'Key reused with a different payload.');

        $this->assertMatchesSpec($response, '/leave-requests', 'post');
        $this->assertSame(1, $this->calls);
    }

    public function test_concurrent_duplicate_is_409_in_progress(): void
    {
        $this->postJson('/api/_test/slow', ['qty' => 1], ['Idempotency-Key' => Str::uuid()->toString()])->assertCreated();

        $this->inFlight->assertStatus(409)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('code', 'IDEMPOTENCY_IN_PROGRESS');
        $this->assertMatchesSpec($this->inFlight, '/leave-requests', 'post');
        $this->assertSame(1, $this->calls);
    }

    public function test_422_outcome_is_stored_and_replayed(): void
    {
        $key = Str::uuid()->toString();
        $this->answer = 422;

        $this->order($key)->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_BALANCE');
        $this->order($key)->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('code', 'INSUFFICIENT_BALANCE');

        $this->assertSame(1, $this->calls);
    }

    public function test_5xx_and_other_4xx_release_the_key(): void
    {
        $key = Str::uuid()->toString();

        $this->answer = 500;
        $this->order($key)->assertStatus(500);
        $this->answer = 409;
        $this->order($key)->assertStatus(409)->assertHeaderMissing('Idempotent-Replayed');
        $this->answer = 201;
        $this->order($key)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(3, $this->calls);
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $key = Str::uuid()->toString();
        [$alice, $bob] = [new User, new User];
        [$alice->id, $bob->id] = [1001, 1002];

        $this->actingAs($alice)->order($key)->assertCreated();
        $this->actingAs($bob)->order($key)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, $this->calls);
    }

    public function test_without_key_every_request_executes(): void
    {
        $this->postJson('/api/_test/orders', ['qty' => 1])->assertCreated();
        $this->postJson('/api/_test/orders', ['qty' => 1])->assertCreated();

        $this->assertSame(2, $this->calls);
    }

    public function test_invalid_key_is_422_validation_failed(): void
    {
        foreach (['short', str_repeat('a', 256), 'bad key with spaces'] as $key) {
            $this->order($key)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')->assertJsonStructure(['errors' => ['Idempotency-Key']]);
        }
        $this->assertSame(0, $this->calls);
    }
}

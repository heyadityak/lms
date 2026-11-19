<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IdempotencyTest extends TestCase
{
    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'redis']); // exercise the real store (serialisation, TTL)

        Route::middleware(['api', 'idempotent'])->post('/api/_test/orders', function (Request $r) {
            $this->calls++;

            return response()->json(['id' => $this->calls, 'qty' => $r->input('qty')], 201, ['Location' => '/orders/'.$this->calls]);
        });
    }

    public function test_same_key_and_payload_replays_stored_response_without_reexecuting(): void
    {
        $key = Str::uuid()->toString();

        $first = $this->postJson('/api/_test/orders', ['qty' => 2], ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson('/api/_test/orders', ['qty' => 2], ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertHeader('Location', '/orders/1')
            ->assertExactJson($first->json());

        $this->assertSame(1, $this->calls);
    }

    public function test_same_key_with_different_payload_is_409(): void
    {
        $key = Str::uuid()->toString();

        $this->postJson('/api/_test/orders', ['qty' => 2], ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson('/api/_test/orders', ['qty' => 3], ['Idempotency-Key' => $key])
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'idempotency_key_reused');

        $this->assertSame(1, $this->calls);
    }

    public function test_without_key_every_request_executes(): void
    {
        $this->postJson('/api/_test/orders', ['qty' => 1])->assertCreated();
        $this->postJson('/api/_test/orders', ['qty' => 1])->assertCreated();

        $this->assertSame(2, $this->calls);
    }

    public function test_invalid_key_is_400(): void
    {
        $this->postJson('/api/_test/orders', [], ['Idempotency-Key' => str_repeat('a', 256)])
            ->assertStatus(400)
            ->assertJsonPath('code', 'idempotency_key_invalid');
    }
}

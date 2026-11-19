<?php

declare(strict_types=1);

namespace Tests\Feature\WhiteLabel;

use App\Auth\JwksCache;
use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use Tests\Support\FakeKeycloak;
use Tests\TestCase;

/**
 * Phase 7 QA (AGENTS §6 scenario 18): API requests as different users on one long-lived Octane worker.
 * No identity, guard user, provisioned role, branding or setting may survive into the next request.
 */
final class OctaneIdentityIsolationTest extends TestCase
{
    use FakeKeycloak, RefreshDatabase;

    public function test_identity_guard_and_settings_do_not_bleed_between_api_requests(): void
    {
        $this->fakeKeycloak();
        // The worker has its own DB connection, so fixtures must be committed outside the test transaction.
        config(['database.connections.octane_side' => config('database.connections.pgsql')]);
        $side = $this->app['db']->connection('octane_side');
        $tag = Str::lower(Str::random(8));
        $subs = ["alice-{$tag}", "bob-{$tag}"];
        $side->table('settings')->insert(['key' => 'brand.name', 'value' => json_encode('Brand of A'), 'updated_at' => now()]);

        $http = new HttpFactory;
        $http->fake([JwksCache::url() => fn () => $http->response(['keys' => $this->publishedKeys])]);
        $token = fn (string $sub, array $roles): string => $this->jwt(['sub' => $sub, 'email' => "{$sub}@example.test", 'name' => ucfirst(strtok($sub, '-'))], $roles);
        $send = function (FakeWorker $worker, ?string $bearer, string $uri = '/api/v1/me'): void {
            $request = Request::create($uri, server: ['HTTP_ACCEPT' => 'application/json'] + ($bearer ? ['HTTP_AUTHORIZATION' => "Bearer {$bearer}"] : []));
            $worker->handle($request, new RequestContext(['request' => $request]));
        };

        try {
            $client = new FakeClient([]);
            $worker = new FakeWorker(new ApplicationFactory($this->app->basePath()), $client);
            $worker->boot();
            $worker->application()->instance(HttpFactory::class, $http); // boot-time instances are overridden by the provider binding

            $send($worker, $token($subs[0], ['hr_admin', 'admin']));                 // A: alice, hr_admin + admin flag
            $side->table('settings')->where('key', 'brand.name')->delete(); // another replica resets branding
            $send($worker, null);                                         // B: no token
            $send($worker, $token($subs[1], ['employee']));                 // C: bob, employee
            $send($worker, 'not-a-jwt');                                  // D: garbage token
            $send($worker, null, '/');                                    // E: web landing, guest
        } finally {
            $side->table('settings')->where('key', 'brand.name')->delete();
            $side->table('users')->whereIn('keycloak_sub', $subs)->delete();
            Container::setInstance($this->app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);
        }

        $this->assertSame([], $client->errors);
        [$a, $b, $c, $d, $e] = $client->responses;
        $this->assertSame(200, $a->getStatusCode(), (string) $a->getContent());
        $alice = json_decode((string) $a->getContent(), true)['data'];
        $this->assertSame(["alice-{$tag}@example.test", 'hr_admin', true], [$alice['email'], $alice['role'], $alice['is_admin']]);

        $this->assertSame(401, $b->getStatusCode(), 'Guard kept the previous request\'s user.');
        $this->assertSame(200, $c->getStatusCode());
        $bob = json_decode((string) $c->getContent(), true)['data'];
        $this->assertSame(["bob-{$tag}@example.test", 'employee', false], [$bob['email'], $bob['role'], $bob['is_admin']]);
        $this->assertSame(401, $d->getStatusCode());

        $this->assertSame(200, $e->getStatusCode());
        $html = (string) $e->getContent();
        $this->assertStringNotContainsString('Brand of A', $html);
        $this->assertStringNotContainsString("alice-{$tag}", $html);
        $this->assertStringNotContainsString("bob-{$tag}", $html);
    }
}

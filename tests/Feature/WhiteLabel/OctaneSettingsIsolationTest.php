<?php

declare(strict_types=1);

namespace Tests\Feature\WhiteLabel;

use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use Tests\TestCase;

/**
 * Two sequential requests on one real Octane worker (one long-lived app): request A runs under a brand override,
 * the override is then removed by "another replica"; request B must see the defaults, not A's memoised settings.
 */
final class OctaneSettingsIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_and_branding_do_not_bleed_between_requests(): void
    {
        // The worker has its own DB connection, so the override must be committed (outside the test transaction).
        config(['database.connections.octane_side' => config('database.connections.pgsql')]);
        $side = $this->app['db']->connection('octane_side');
        $side->table('settings')->insert([
            ['key' => 'brand.name', 'value' => json_encode('Octane Brand A'), 'updated_at' => now()],
            ['key' => 'brand.primary_color', 'value' => json_encode('#ffd700'), 'updated_at' => now()],
        ]);

        try {
            $client = new FakeClient([]);
            $worker = new FakeWorker(new ApplicationFactory($this->app->basePath()), $client);
            $worker->boot();

            $worker->handle($request = Request::create('/'), new RequestContext(['request' => $request]));
            // Another replica resets branding (Settings::forget busts the shared cache; the worker's array cache is flushed per request).
            $side->table('settings')->whereIn('key', ['brand.name', 'brand.primary_color'])->delete();
            $worker->handle($request = Request::create('/'), new RequestContext(['request' => $request]));
        } finally {
            $side->table('settings')->whereIn('key', ['brand.name', 'brand.primary_color'])->delete();
            // The worker app re-pointed the global container/facades at itself.
            Container::setInstance($this->app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);
        }

        $this->assertSame([], $client->errors);
        [$a, $b] = array_map(fn ($r): string => (string) $r->getContent(), $client->responses);
        $this->assertStringContainsString('<title>Octane Brand A</title>', $a);
        $this->assertStringContainsString('--brand-primary:#ffd700;', $a);
        $this->assertStringContainsString('<title>Leave Manager</title>', $b);
        $this->assertStringContainsString('--brand-primary:#1f6feb;', $b);
        $this->assertStringNotContainsString('Octane Brand A', $b);
    }
}

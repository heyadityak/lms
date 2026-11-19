<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** /api/openapi.yaml + /api/docs: public, behind module:api (SYSTEM_DESIGN §6.2). */
final class ApiDocsTest extends TestCase
{
    use RefreshDatabase; // module:api reads the settings table

    public function test_spec_is_served_without_auth(): void
    {
        $response = $this->get('/api/openapi.yaml')->assertOk();

        $this->assertStringStartsWith('application/yaml', (string) $response->headers->get('Content-Type'));
        $this->assertSame(file_get_contents(base_path('docs/api/openapi.yaml')), $response->streamedContent());
    }

    public function test_docs_page_loads_pinned_redoc_with_sri(): void
    {
        $this->get('/api/docs')
            ->assertOk()
            ->assertSee('spec-url="'.url('/api/openapi.yaml').'"', false)
            ->assertSee('https://cdn.jsdelivr.net/npm/redoc@2.5.0/bundles/redoc.standalone.js', false)
            ->assertSee('integrity="sha384-', false)
            ->assertSee('crossorigin="anonymous"', false);
    }
}

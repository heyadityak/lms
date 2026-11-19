<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves the contract-first spec (docs/api/openapi.yaml) and a Redoc viewer; public, behind module:api (SYSTEM_DESIGN §6.2). */
final class ApiDocsController
{
    private const REDOC_JS = 'https://cdn.jsdelivr.net/npm/redoc@2.5.0/bundles/redoc.standalone.js';

    /** sha384 of the pinned bundle (computed 2026-11-19); bump together with the version. */
    private const REDOC_SRI = 'sha384-4vOjrBu7SuDWXcAw1qFznVLA/sKL+0l4nn+J1HY8w7cpa6twQEYuh4b0Cwuo7CyX';

    public function spec(): BinaryFileResponse
    {
        $path = base_path('docs/api/openapi.yaml');
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'application/yaml; charset=utf-8']);
    }

    public function ui(): Response
    {
        $spec = e(url('/api/openapi.yaml'));
        [$js, $sri] = [self::REDOC_JS, self::REDOC_SRI];

        return response(<<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <title>LMS API</title></head>
            <body><redoc spec-url="{$spec}"></redoc><script src="{$js}" integrity="{$sri}" crossorigin="anonymous"></script></body></html>
            HTML);
    }
}

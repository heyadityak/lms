<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** GET /branding/{logo|favicon}: uploaded override from the branding disk, else the bundled default (D-15, D-43). Public. */
final class BrandingAssetController
{
    private const DEFAULTS = ['logo' => 'img/default-logo.png', 'favicon' => 'favicon.ico'];

    public function __invoke(Request $request, Settings $settings, string $asset): Response
    {
        $path = $settings->get("brand.{$asset}_path");
        $disk = Storage::disk(config('branding.disk'));
        if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
            $path = null; // never 500 on a dangling override: fall back to the default
        }

        $response = $path !== null ? $disk->response($path) : response()->file(public_path(self::DEFAULTS[$asset]));
        // URLs carry ?v=<hash|default>, so a short public max-age plus an ETag is safe (SYSTEM_DESIGN §8.2).
        $response->setPublic()->setMaxAge(300)->setEtag(sha1($path ?? 'default'));
        $response->isNotModified($request);

        return $response;
    }
}

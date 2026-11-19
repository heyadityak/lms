<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;

/** Validates responses against docs/api/openapi.yaml (D-21). $path is relative to the `/api/v1` server. */
trait ValidatesOpenApi
{
    private static ?ValidatorInterface $openApi = null;

    protected function assertMatchesSpec(TestResponse $response, string $path, string $method = 'get'): void
    {
        self::$openApi ??= ValidatorBuilder::fromYamlFile(dirname(__DIR__, 2).'/docs/api/openapi.yaml')->getValidator();

        $this->assertTrue(self::$openApi->validate($response->baseResponse, $path, $method));
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every /api/* error as RFC 9457 application/problem+json (API_GUIDELINES §4).
 * Runs after Laravel's prepareException(), so ModelNotFound/Authorization arrive as 404/403 HttpExceptions.
 */
final class ProblemJsonRenderer
{
    /** Framework status => code; every value must be in openapi.yaml ProblemCode. */
    private const CODES = [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHENTICATED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        // The spec enum has no METHOD_NOT_ALLOWED (D-40).
        405 => 'BAD_REQUEST',
        406 => 'NOT_ACCEPTABLE',
        409 => 'INVALID_TRANSITION',
        415 => 'UNSUPPORTED_MEDIA_TYPE',
        422 => 'VALIDATION_FAILED',
        429 => 'RATE_LIMITED',
        503 => 'SERVICE_UNAVAILABLE',
    ];

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api', 'api/*')) {
            return null;
        }

        $headers = [];
        $errors = [];
        $detail = null;
        $code = null;

        if ($e instanceof DomainException) {
            [$status, $code, $detail, $errors, $headers] = [$e->status, $e->problemCode, $e->getMessage(), $e->errors, $e->headers];
        } elseif ($e instanceof ValidationException) {
            [$status, $detail, $errors] = [422, $e->getMessage(), $e->errors()];
        } elseif ($e instanceof AuthenticationException) {
            [$status, $detail] = [401, $e->getMessage()];
            // RFC 6750 §3.1: no error code when the request carried no token.
            $headers['WWW-Authenticate'] = $request->bearerToken() ? 'Bearer error="invalid_token"' : 'Bearer';
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            // abort()/framework messages are developer-authored; 5xx detail is never exposed.
            $detail = $status < 500 && $e->getMessage() !== '' ? $e->getMessage() : null;
            // One fixed 404 body: "missing" and "hidden" ids must be indistinguishable, no model class names (API_GUIDELINES §5).
            $detail = $status === 404 ? 'The requested resource was not found.' : $detail;
        } else {
            // Never leak internals, whatever APP_DEBUG says (API_GUIDELINES §4). The handler still reports $e.
            $status = 500;
        }

        $code ??= self::CODES[$status] ?? ($status < 500 ? 'BAD_REQUEST' : 'INTERNAL_ERROR');

        $body = array_filter([
            'type' => 'urn:lms:problem:'.strtolower(str_replace('_', '-', $code)),
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
            'instance' => '/'.$request->path(),
            'code' => $code,
            'request_id' => $request->headers->get('X-Request-Id'),
        ], fn ($v): bool => $v !== null && $v !== '');

        if ($status === 422) {
            $body['errors'] = (object) $errors; // ValidationProblem requires the map, even when empty
        }

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json'] + $headers);
    }
}

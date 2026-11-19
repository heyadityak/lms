<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Renders every /api/* error as RFC 9457 application/problem+json with a stable `code`. */
final class ProblemJsonRenderer
{
    /** Stable codes per HTTP status for framework exceptions. */
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        415 => 'unsupported_media_type',
        422 => 'validation_failed',
        429 => 'too_many_requests',
        500 => 'internal_error',
        503 => 'service_unavailable',
    ];

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        $headers = [];
        $extra = [];
        $detail = null;

        if ($e instanceof DomainException) {
            [$status, $code, $detail] = [$e->status, $e->errorCode, $e->getMessage()];
        } elseif ($e instanceof ValidationException) {
            [$status, $detail] = [422, $e->getMessage()];
            $extra['errors'] = $e->errors();
        } elseif ($e instanceof AuthenticationException) {
            $status = 401;
            $headers['WWW-Authenticate'] = 'Bearer';
        } elseif ($e instanceof AuthorizationException) {
            $status = 403;
        } elseif ($e instanceof ModelNotFoundException) {
            $status = 404;
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            // Messages from abort(...) are developer-authored; 5xx detail is never exposed.
            $detail = $status < 500 && $e->getMessage() !== '' ? $e->getMessage() : null;
        } else {
            $status = 500;
            $detail = config('app.debug') ? $e->getMessage() : null;
        }

        $code ??= self::CODES[$status] ?? 'error';

        return new JsonResponse(array_filter([
            'type' => 'urn:lms:problem:'.$code,
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
            'instance' => '/'.ltrim($request->path(), '/'),
            'code' => $code,
            'request_id' => $request->headers->get('X-Request-Id'),
        ], fn ($v): bool => $v !== null) + $extra, $status, $headers + ['Content-Type' => 'application/problem+json']);
    }
}

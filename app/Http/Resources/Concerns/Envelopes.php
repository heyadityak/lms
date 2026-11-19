<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/** `{data, meta, links}` envelopes (API_GUIDELINES §2–3) for JsonResource subclasses. */
trait Envelopes
{
    /** Single resource; 201 adds `Location` = $self. Explicit status: JsonResource would answer 201 for any model created in this request. */
    public function envelope(int $status = 200, ?string $self = null): JsonResponse
    {
        return $this->additional(['meta' => new stdClass, 'links' => $self === null ? new stdClass : ['self' => $self]])
            ->response()->setStatusCode($status)
            ->withHeaders($status === 201 && $self !== null ? ['Location' => $self] : []);
    }

    /**
     * Page envelope (meta current_page/per_page/total/last_page, links first/prev/next/last).
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     */
    public static function page(LengthAwarePaginator $page, Request $request): JsonResponse
    {
        $query = $request->query();
        unset($query['page']['number']);
        $page->appends($query);

        return response()->json([
            'data' => static::collection($page->getCollection())->resolve($request),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'links' => ['first' => $page->url(1), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl(), 'last' => $page->url($page->lastPage())],
        ]);
    }
}

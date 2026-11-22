<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Audit\RecordAuditLog;
use App\Exceptions\DomainException;
use App\Http\Requests\LocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use stdClass;

final class LocationController
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Location::class);

        return response()->json([
            'data' => LocationResource::collection(Location::query()->orderBy('name')->get())->resolve(),
            'meta' => new stdClass,
            'links' => new stdClass,
        ]);
    }

    public function show(Location $location): JsonResponse
    {
        Gate::authorize('view', $location);

        return (new LocationResource($location))->envelope();
    }

    public function store(LocationRequest $request, RecordAuditLog $audit): JsonResponse
    {
        $location = Location::query()->create($request->validated());
        $audit->write($request->user(), 'location', $location->public_id, 'created', $request->validated());

        return (new LocationResource($location))->envelope(201, route('api.v1.locations.show', $location));
    }

    public function update(LocationRequest $request, Location $location, RecordAuditLog $audit): JsonResponse
    {
        $location->update($request->validated());
        $audit->write($request->user(), 'location', $location->public_id, 'updated', $request->validated());

        return (new LocationResource($location))->envelope();
    }

    public function destroy(Location $location, RecordAuditLog $audit): Response
    {
        Gate::authorize('delete', $location);
        if ($location->users()->exists() || $location->holidays()->exists()) {
            throw new DomainException('The location still has users or holidays.', 'LOCATION_NOT_EMPTY', 409);
        }
        $audit->write(request()->user(), 'location', $location->public_id, 'deleted', ['name' => $location->name]);
        $location->delete();

        return response()->noContent();
    }
}

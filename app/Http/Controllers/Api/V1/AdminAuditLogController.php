<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Role;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Admin Audit Logs', weight: 56)]
class AdminAuditLogController extends Controller
{
    #[QueryParameter('page', type: 'integer', default: 1, example: 1)]
    #[Response(200, type: 'array{data: list<AuditLogResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, path: string, per_page: int, to: int|null, total: int}}')]
    public function index(IndexAuditLogRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole([Role::OrganizationOwner, Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $validated = $request->validated();

        $logs = AuditLog::query()
            ->with(['actorUser.roles', 'organization', 'restaurant'])
            ->when(isset($validated['organization_id']), fn ($query) => $query->where('organization_id', $validated['organization_id']))
            ->when(isset($validated['restaurant_id']), fn ($query) => $query->where('restaurant_id', $validated['restaurant_id']))
            ->when(isset($validated['actor_user_id']), fn ($query) => $query->where('actor_user_id', $validated['actor_user_id']))
            ->when(isset($validated['action']), fn ($query) => $query->where('action', 'like', '%'.$validated['action'].'%'))
            ->when(isset($validated['search']), function ($query) use ($validated): void {
                $search = $validated['search'];

                $query->where(function ($logQuery) use ($search): void {
                    $logQuery
                        ->where('action', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%')
                        ->orWhereHas('actorUser', function ($userQuery) use ($search): void {
                            $userQuery->where('name', 'like', '%'.$search.'%')
                                ->orWhere('first_name', 'like', '%'.$search.'%')
                                ->orWhere('last_name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        });
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 25)
            ->appends($request->query());

        return response()->json([
            'data' => AuditLogResource::collection($logs->getCollection())->resolve($request),
            'links' => [
                'first' => $logs->url(1),
                'last' => $logs->url($logs->lastPage()),
                'prev' => $logs->previousPageUrl(),
                'next' => $logs->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $logs->currentPage(),
                'from' => $logs->firstItem(),
                'last_page' => $logs->lastPage(),
                'path' => $logs->path(),
                'per_page' => $logs->perPage(),
                'to' => $logs->lastItem(),
                'total' => $logs->total(),
            ],
        ]);
    }
}

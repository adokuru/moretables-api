<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Role;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Admin Audit Logs', weight: 56)]
class AdminAuditLogController extends Controller
{
    public function index(IndexAuditLogRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole([Role::OrganizationOwner, Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $validated = $request->validated();

        $logs = AuditLog::query()
            ->with('actorUser.roles')
            ->when(isset($validated['organization_id']), fn ($query) => $query->where('organization_id', $validated['organization_id']))
            ->when(isset($validated['restaurant_id']), fn ($query) => $query->where('restaurant_id', $validated['restaurant_id']))
            ->when(isset($validated['action']), fn ($query) => $query->where('action', 'like', '%'.$validated['action'].'%'))
            ->latest()
            ->paginate($validated['per_page'] ?? 25);

        return response()->json(AuditLogResource::collection($logs));
    }
}

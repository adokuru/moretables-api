<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganizationRequest;
use App\Http\Requests\Admin\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

#[Group('Admin Organizations', weight: 50)]
class AdminOrganizationController extends Controller
{
    #[QueryParameter('page', type: 'integer', default: 1, example: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 20, example: 20)]
    #[Response(200, type: 'array{data: list<OrganizationResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, path: string, per_page: int, to: int|null, total: int}}')]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);

        $organizations = Organization::query()
            ->with(['restaurants.cuisines', 'restaurants.media'])
            ->withCount('restaurants')
            ->when(
                filled($request->string('search')->toString()),
                fn ($query) => $query->where(function ($organizationQuery) use ($request): void {
                    $search = $request->string('search')->toString();

                    $organizationQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%')
                        ->orWhere('primary_contact_email', 'like', '%'.$search.'%')
                        ->orWhere('business_email', 'like', '%'.$search.'%');
                }),
            )
            ->when(
                filled($request->string('status')->toString()),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->latest()
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return response()->json([
            'data' => OrganizationResource::collection($organizations->getCollection())->resolve($request),
            'links' => [
                'first' => $organizations->url(1),
                'last' => $organizations->url($organizations->lastPage()),
                'prev' => $organizations->previousPageUrl(),
                'next' => $organizations->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $organizations->currentPage(),
                'from' => $organizations->firstItem(),
                'last_page' => $organizations->lastPage(),
                'path' => $organizations->path(),
                'per_page' => $organizations->perPage(),
                'to' => $organizations->lastItem(),
                'total' => $organizations->total(),
            ],
        ]);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $this->authorize('create', Organization::class);

        $validated = $request->validated();
        $organization = Organization::query()->create([
            ...$validated,
            'slug' => $validated['slug'] ?? str($validated['name'])->slug()->toString(),
        ]);

        return response()->json([
            'message' => 'Organization created successfully.',
            'organization' => OrganizationResource::make($organization->load(['restaurants.cuisines', 'restaurants.media'])),
        ], 201);
    }

    public function show(Organization $organization): OrganizationResource
    {
        $this->authorize('view', $organization);

        return OrganizationResource::make($organization->load(['restaurants.cuisines', 'restaurants.media'])->loadCount('restaurants'));
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('update', $organization);

        $validated = $request->validated();
        if (array_key_exists('name', $validated) && empty($validated['slug'])) {
            $validated['slug'] = str($validated['name'])->slug()->toString();
        }

        $organization->update($validated);

        return response()->json([
            'message' => 'Organization updated successfully.',
            'organization' => OrganizationResource::make(
                $organization->refresh()->load(['restaurants.cuisines', 'restaurants.media'])->loadCount('restaurants')
            ),
        ]);
    }

    public function destroy(Organization $organization): JsonResponse
    {
        $this->authorize('delete', $organization);

        $organization->delete();

        return response()->json([
            'message' => 'Organization deleted successfully.',
        ]);
    }

    /**
     * Resend the password reset link to the organization owner(s).
     *
     * Triggers Laravel's password broker, sending a fresh reset link to every
     * user that holds the OrganizationOwner role for the given organization.
     * Throttled and missing-user statuses are surfaced per recipient.
     */
    #[Response(200, type: 'array{message: string, recipients: list<array{email: string, status: string, message: string}>}')]
    #[Response(403, type: 'array{message: string}')]
    #[Response(404, type: 'array{message: string}')]
    public function resendOwnerPasswordReset(Request $request, Organization $organization): JsonResponse
    {
        $this->ensureAdminAccess($request);
        $this->authorize('view', $organization);

        $ownerIds = UserRole::query()
            ->where('organization_id', $organization->id)
            ->whereHas('role', fn ($query) => $query->where('name', Role::OrganizationOwner))
            ->pluck('user_id')
            ->unique();

        if ($ownerIds->isEmpty()) {
            return response()->json([
                'message' => 'No organization owner found for this organization.',
            ], 404);
        }

        $owners = User::query()->whereIn('id', $ownerIds)->get();

        $recipients = $owners->map(function (User $owner): array {
            $status = Password::sendResetLink(['email' => $owner->email]);

            return [
                'email' => $owner->email,
                'status' => $status,
                'message' => match ($status) {
                    Password::RESET_LINK_SENT => 'Password reset link sent successfully.',
                    Password::RESET_THROTTLED => 'Password reset link was recently sent; please wait before requesting again.',
                    Password::INVALID_USER => 'Could not locate a user with the owner email address.',
                    default => 'Unable to send password reset link.',
                },
            ];
        })->values()->all();

        $anySent = collect($recipients)->contains(
            fn (array $recipient) => $recipient['status'] === Password::RESET_LINK_SENT,
        );

        return response()->json([
            'message' => $anySent
                ? 'Password reset link resent to organization owner.'
                : 'No password reset link was sent. See recipients for details.',
            'recipients' => $recipients,
        ]);
    }
}

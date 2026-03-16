<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialLoginRequest;
use App\Http\Resources\UserResource;
use App\Services\CustomerSocialAuthService;
use App\SocialAuthProvider;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Customer Auth', weight: 10)]
class SocialAuthController extends Controller
{
    public function __construct(protected CustomerSocialAuthService $customerSocialAuthService) {}

    public function google(SocialLoginRequest $request): JsonResponse
    {
        return $this->authenticate($request, SocialAuthProvider::Google);
    }

    public function apple(SocialLoginRequest $request): JsonResponse
    {
        return $this->authenticate($request, SocialAuthProvider::Apple);
    }

    protected function authenticate(SocialLoginRequest $request, SocialAuthProvider $provider): JsonResponse
    {
        $result = $this->customerSocialAuthService->authenticate($provider, $request->validated());
        $user = $result['user']->load('roles');

        return response()->json([
            'message' => ucfirst($provider->value).' login successful.',
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'user' => UserResource::make($user),
        ]);
    }
}

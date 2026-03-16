<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['organization_owner', 'business_admin', 'dev_admin', 'super_admin']);
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->hasRole('organization_owner', organization: $organization)
            || $user->hasAnyRole(['business_admin', 'dev_admin', 'super_admin']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['business_admin', 'dev_admin', 'super_admin']);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->hasAnyRole(['business_admin', 'super_admin']);
    }
}

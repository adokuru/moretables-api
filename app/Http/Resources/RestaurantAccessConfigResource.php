<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantAccessConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'description'     => $this->description,
            'permissions'     => $this->permissions ?? [],
            'access_sections' => array_map(
                fn (string $slug): string => match ($slug) {
                    'reservations.manage'   => 'Reservations Management',
                    'tables.manage'         => 'Floor Configurations',
                    'audit_logs.view'       => 'Reporting View',
                    'reporting.export'      => 'Reporting Exporting Data',
                    'restaurants.manage'    => 'Restaurant Settings',
                    'integrations.manage'   => 'Integrations',
                    'marketing.manage'      => 'Marketing',
                    'restaurants.view'      => 'Restaurant Profile',
                    'billing.manage'        => 'Billing',
                    'communications.manage' => 'Communications Channels',
                    'messaging.manage'      => 'Direct Messaging',
                    'policies.manage'       => 'Policies',
                    'waitlist.manage'       => 'Waitlist Management',
                    'reservations.view'     => 'View Reservations',
                    'staff.manage'          => 'Staff Management',
                    default                 => $slug,
                },
                $this->permissions ?? [],
            ),
            'is_default'  => $this->is_default,
            'created_at'  => $this->created_at?->toIso8601String(),
            'updated_at'  => $this->updated_at?->toIso8601String(),
        ];
    }
}

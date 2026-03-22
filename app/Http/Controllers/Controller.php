<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;

    protected function ensureAdminAccess(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(Role::adminRoles()), 403);
    }
}

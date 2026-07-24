<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminToken
{
    /**
     * Allow only admin (User) tokens carrying the 'admin' ability.
     * Customer tokens (Customer model) and abilityless tokens are rejected.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->tokenCan('admin'),
            403,
            'Admin access required.'
        );

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->user();
        abort_unless($principal instanceof User, 401);

        $this->tenantContext->setPrincipal($principal);
        Context::addHidden('safernet_tenant', [
            'role' => $principal->role->value,
            'subcounty_id' => $principal->subcounty_id,
            'institution_id' => $principal->institution_id,
        ]);

        return $next($request);
    }
}

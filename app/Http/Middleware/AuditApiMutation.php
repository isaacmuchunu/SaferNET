<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditApiMutation
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe() || $response->getStatusCode() >= 400) {
            return $response;
        }

        $user = $request->user();

        if ($user instanceof User) {
            $subject = collect($request->route()?->parameters() ?? [])
                ->first(fn (mixed $parameter): bool => $parameter instanceof Model);

            AuditLog::create([
                'actor_id' => $user->id,
                'institution_id' => $subject?->getAttribute('institution_id') ?? $user->institution_id,
                'event' => 'api.'.($request->route()?->getName() ?? 'mutation'),
                'auditable_type' => $subject?->getMorphClass(),
                'auditable_id' => $subject?->getKey(),
                'new_values' => ['method' => $request->method(), 'status' => $response->getStatusCode()],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return $response;
    }
}

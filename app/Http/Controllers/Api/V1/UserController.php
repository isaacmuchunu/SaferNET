<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Institution;
use App\Models\User;
use App\Services\Officers\OfficerProvisioner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::query()
            ->visibleTo($request->user())
            ->with(['subcounty', 'institution'])
            ->when($request->filled('institution_id'), fn ($users) => $users->where('institution_id', $request->integer('institution_id')))
            ->when($request->filled('subcounty_id'), fn ($users) => $users->where('subcounty_id', $request->integer('subcounty_id')))
            ->when($request->filled('role'), fn ($users) => $users->where('role', $request->string('role')))
            ->when($request->filled('search'), function ($users) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $users->where(fn ($matches) => $matches->where('name', 'ilike', $search)->orWhere('email', 'ilike', $search));
            })
            ->orderBy('name');

        return UserResource::collection($query->paginate()->withQueryString());
    }

    public function store(SaveUserRequest $request, OfficerProvisioner $provisioner): UserResource
    {
        Gate::authorize('create', User::class);
        $data = $this->authorizedScope($request, $request->validated());
        unset($data['avatar']);

        if ($request->hasFile('avatar')) {
            $data['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }

        return new UserResource($provisioner->create($data)->load(['subcounty', 'institution']));
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load(['subcounty', 'institution']));
    }

    public function update(SaveUserRequest $request, User $user): UserResource
    {
        Gate::authorize('update', $user);
        $data = $request->validated();
        unset($data['avatar']);
        if (! $request->user()->hasRole(UserRole::Cde)) {
            unset($data['role'], $data['subcounty_id'], $data['institution_id']);
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $oldAvatar = $user->avatar_path;
        if ($request->hasFile('avatar')) {
            $data['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }

        $user->update($data);

        if (isset($data['avatar_path']) && $oldAvatar) {
            Storage::disk('public')->delete($oldAvatar);
        }

        return new UserResource($user->fresh()->load(['subcounty', 'institution']));
    }

    public function destroy(User $user): Response
    {
        Gate::authorize('delete', $user);
        abort_if(auth()->id() === $user->id, 422, 'You cannot delete your own account.');
        $avatar = $user->avatar_path;
        $user->delete();

        if ($avatar) {
            Storage::disk('public')->delete($avatar);
        }

        return response()->noContent();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function authorizedScope(SaveUserRequest $request, array $data): array
    {
        $actor = $request->user();
        $role = UserRole::from($data['role']);

        if ($actor->hasRole(UserRole::Hoi)) {
            abort_unless($role === UserRole::Clm, 403);
            $data['institution_id'] = $actor->institution_id;
            $data['subcounty_id'] = $actor->subcounty_id;
        } elseif ($actor->hasRole(UserRole::Scde)) {
            abort_unless(in_array($role, [UserRole::Hoi, UserRole::Clm], true), 403);
            $institution = Institution::query()->visibleTo($actor)->findOrFail($data['institution_id'] ?? null);
            $data['subcounty_id'] = $institution->subcounty_id;
        } else {
            if ($role === UserRole::Cde) {
                $data['subcounty_id'] = $data['institution_id'] = null;
            }
            if ($role === UserRole::Scde) {
                $data['institution_id'] = null;
            }
            if (in_array($role, [UserRole::Hoi, UserRole::Clm], true)) {
                $institution = Institution::query()->findOrFail($data['institution_id'] ?? null);
                $data['subcounty_id'] = $institution->subcounty_id;
            }
        }

        return $data;
    }
}

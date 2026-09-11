<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Users;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroupsController extends Controller
{
    public function index(Request $request)
    {
        $groups = Group::query()
            ->whereHas('members', fn ($query) => $query->where('users.id', $request->user()->id))
            ->with(['owner:id,display_name,avatar', 'members' => fn ($query) => $query
                ->select('users.id', 'display_name', 'avatar')->orderBy('display_name')])
            ->latest()->get();

        return response()->json(['data' => $groups->map(fn (Group $group) => $this->serialize($group, $request->user()))]);
    }

    public function store(Request $request)
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        $input = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:50'],
            'icon' => ['sometimes', 'required', 'string', Rule::in(Group::ICONS)],
            'member_ids' => ['sometimes', 'array', 'max:49'],
            'member_ids.*' => ['integer', 'distinct'],
        ]);
        $memberIds = array_values($input['member_ids'] ?? []);
        $this->ensureFriends($request->user(), $memberIds);

        $group = DB::transaction(function () use ($request, $input, $memberIds) {
            $group = Group::create([
                'owner_id' => $request->user()->id,
                'name' => $input['name'],
                'icon' => $input['icon'] ?? 'target',
            ]);
            $group->members()->attach(array_unique([$request->user()->id, ...$memberIds]));

            return $group;
        });

        return response()->json([
            'data' => $this->serialize($this->load($group), $request->user()),
        ], 201);
    }

    public function candidates(Request $request)
    {
        $friends = $request->user()->friends()->where('is_active', true)
            ->orderBy('display_name')->get(['users.id', 'display_name', 'avatar']);

        return response()->json(['data' => $friends]);
    }

    public function show(Request $request, Group $group)
    {
        $this->ensureMember($request->user(), $group);

        return response()->json(['data' => $this->serialize($this->load($group), $request->user())]);
    }

    public function update(Request $request, Group $group)
    {
        $this->ensureMember($request->user(), $group);
        $this->ensureOwner($request->user(), $group);

        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        $input = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:50'],
            'icon' => ['sometimes', 'required', 'string', Rule::in(Group::ICONS)],
        ]);
        if ($input === []) {
            throw ValidationException::withMessages([
                'group' => 'Informe ao menos name ou icon.',
            ]);
        }

        $group->update($input);

        return response()->json(['data' => $this->serialize($this->load($group), $request->user())]);
    }

    public function addMember(Request $request, Group $group)
    {
        $this->ensureOwner($request->user(), $group);
        if ($group->members()->count() >= 50) {
            throw ValidationException::withMessages(['user_id' => 'O grupo já atingiu o limite de 50 membros.']);
        }
        $input = $request->validate(['user_id' => ['required', 'integer']]);
        $this->ensureFriends($request->user(), [$input['user_id']], 'user_id');
        $group->members()->syncWithoutDetaching([$input['user_id']]);

        return response()->json(['data' => $this->serialize($this->load($group), $request->user())]);
    }

    public function removeMember(Request $request, Group $group, Users $user)
    {
        $this->ensureOwner($request->user(), $group);
        if ($user->id === $group->owner_id) {
            throw ValidationException::withMessages(['user_id' => 'O dono não pode ser removido do grupo.']);
        }
        if (! $group->members()->where('users.id', $user->id)->exists()) {
            abort(404);
        }
        $group->members()->detach($user->id);

        return response()->json(['data' => $this->serialize($this->load($group), $request->user())]);
    }

    public function destroy(Request $request, Group $group)
    {
        $this->ensureOwner($request->user(), $group);
        $group->delete();

        return response()->noContent();
    }

    private function ensureFriends(Users $owner, array $memberIds, string $field = 'member_ids'): void
    {
        if ($memberIds === []) {
            return;
        }
        $validIds = $owner->friends()->where('is_active', true)->whereIn('users.id', $memberIds)->pluck('users.id')->all();
        if (count($validIds) !== count($memberIds)) {
            throw ValidationException::withMessages([
                $field => 'Só é possível adicionar amigos ativos que usam o Killfeed.',
            ]);
        }
    }

    private function ensureOwner(Users $user, Group $group): void
    {
        abort_unless($group->owner_id === $user->id, 403);
    }

    private function ensureMember(Users $user, Group $group): void
    {
        abort_unless($group->members()->where('users.id', $user->id)->exists(), 404);
    }

    private function load(Group $group): Group
    {
        return $group->load(['owner:id,display_name,avatar', 'members' => fn ($query) => $query
            ->select('users.id', 'display_name', 'avatar')->orderBy('display_name')]);
    }

    private function serialize(Group $group, Users $viewer): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'icon' => $group->icon,
            'is_owner' => $group->owner_id === $viewer->id,
            'owner' => $group->owner->only(['id', 'display_name', 'avatar']),
            'members_count' => $group->members->count(),
            'members' => $group->members->map(fn (Users $member) => $member->only([
                'id', 'display_name', 'avatar',
            ]))->values(),
            'created_at' => $group->created_at?->toIso8601String(),
        ];
    }
}

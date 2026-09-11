<?php

namespace App\Http\Controllers;

use App\Models\ActivityFeedItem;
use Illuminate\Http\Request;

class ActivityFeedController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $items = ActivityFeedItem::query()
            ->whereHas('actor', fn ($query) => $query->where('is_active', true))
            ->where(function ($query) use ($user) {
                $query->where('actor_id', $user->id)
                    ->orWhereIn('actor_id', $user->friends()->where('is_active', true)->select('users.id'));
            })
            ->with('actor:id,display_name,avatar')
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($items->items())->map(fn (ActivityFeedItem $item) => [
                'id' => $item->id,
                'type' => $item->type,
                'actor' => $item->actor->only(['id', 'display_name', 'avatar']),
                'period' => $item->period,
                'payload' => $item->payload,
                'occurred_at' => $item->occurred_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            'links' => [
                'first' => $items->url(1),
                'last' => $items->url($items->lastPage()),
                'prev' => $items->previousPageUrl(),
                'next' => $items->nextPageUrl(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}

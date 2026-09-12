<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ChatAuthorizationService;
use App\Services\Chat\ChatService;
use App\Services\Organization\OrganizationTreeService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function directory(Request $request, OrganizationTreeService $tree, ChatAuthorizationService $auth)
    {
        $actor = $request->user();
        $users = User::query()->where('is_active', true)->where('id', '!=', $actor->id)->get()
            ->filter(fn (User $u) => $auth->canMessage($actor, $u))
            ->values()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'mobile' => $u->mobile,
                'relationship' => $tree->relationship($actor, $u),
            ]);

        return response()->json($users);
    }

    public function unread(Request $request, ChatService $chat)
    {
        return response()->json(['unread' => $chat->unreadCount($request->user())]);
    }

    public function index(Request $request, ChatService $chat)
    {
        $user = $request->user();
        $items = Conversation::query()
            ->with(['participants:id,name', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->whereHas('participantRows', fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->get();

        return response()->json([
            'conversations' => $items,
            'unread' => $chat->unreadCount($user),
        ]);
    }

    public function store(Request $request, ChatService $chat)
    {
        $data = $request->validate([
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['exists:users,id'],
            'type' => ['nullable', 'in:direct,group'],
            'title' => ['nullable', 'string'],
        ]);

        return response()->json(
            $chat->start($request->user(), $data['participant_ids'], $data['type'] ?? 'direct', $data['title'] ?? null),
            201
        );
    }

    public function messages(Request $request, Conversation $conversation, ChatAuthorizationService $auth)
    {
        $auth->assertParticipant($request->user(), $conversation);

        return response()->json(
            $conversation->messages()->with('sender:id,name')->latest()->paginate(30)
        );
    }

    public function send(Request $request, Conversation $conversation, ChatService $chat)
    {
        $data = $request->validate([
            'body' => ['required', 'string'],
            'message_type' => ['nullable', 'string'],
        ]);

        return response()->json(
            $chat->send($request->user(), $conversation, $data['body'], $data['message_type'] ?? 'text'),
            201
        );
    }

    public function read(Request $request, Conversation $conversation, ChatService $chat)
    {
        $chat->markRead($request->user(), $conversation);

        return response()->json(['ok' => true]);
    }

    public function typing(Request $request, Conversation $conversation, ChatService $chat)
    {
        $chat->typing($request->user(), $conversation, (bool) $request->boolean('started', true));

        return response()->json(['ok' => true]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ChatAuthorizationService;
use App\Services\Chat\ChatService;
use App\Services\Organization\OrganizationTreeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            ->withMax('messages', 'created_at')
            ->orderByDesc('messages_max_created_at')
            ->orderByDesc('id')
            ->get();

        $counts = $chat->unreadCountsByConversation($user);
        $items = $items->map(function (Conversation $conversation) use ($counts) {
            $conversation->setAttribute('unread_count', $counts[$conversation->id] ?? 0);

            return $conversation;
        });

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
            'body' => ['nullable', 'string'],
            'message_type' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,mp3,mp4,webm,aac,ogg'],
        ]);

        if (blank($data['body'] ?? null) && ! $request->hasFile('file')) {
            abort(422, 'متن پیام یا فایل پیوست الزامی است.');
        }

        $attachment = null;
        $type = $data['message_type'] ?? 'text';
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('chat/'.$conversation->id, 'public');
            $mime = (string) $file->getMimeType();
            $attachment = [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'name' => $file->getClientOriginalName(),
                'mime' => $mime,
                'size' => $file->getSize(),
            ];
            $type = str_starts_with($mime, 'image/') ? 'image' : 'file';
        }

        $body = trim((string) ($data['body'] ?? '')) ?: (string) ($attachment['name'] ?? '');

        return response()->json(
            $chat->send($request->user(), $conversation, $body, $type, $attachment),
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

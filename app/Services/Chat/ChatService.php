<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class ChatService
{
    public function __construct(private readonly ChatAuthorizationService $auth) {}

    public function start(User $actor, array $participantIds, string $type = 'direct', ?string $title = null): Conversation
    {
        $ids = collect($participantIds)->push($actor->id)->unique()->values();
        foreach ($ids as $id) {
            if ((int) $id === $actor->id) {
                continue;
            }
            $this->auth->assertCanMessage($actor, User::query()->findOrFail($id));
        }

        if ($type === 'direct' && $ids->count() === 2) {
            $existing = Conversation::query()
                ->where('type', 'direct')
                ->whereHas('participantRows', fn ($q) => $q->where('user_id', $ids[0]))
                ->whereHas('participantRows', fn ($q) => $q->where('user_id', $ids[1]))
                ->first();
            if ($existing) {
                return $existing->load('participants');
            }
        }

        $conversation = Conversation::query()->create([
            'type' => $type,
            'title' => $title,
            'created_by' => $actor->id,
        ]);

        foreach ($ids as $id) {
            $conversation->participantRows()->create([
                'user_id' => $id,
                'joined_at' => now(),
            ]);
        }

        $this->broadcast('conversation.created', [
            'conversation_id' => $conversation->id,
            'participant_ids' => $ids->all(),
        ]);

        return $conversation->load('participants');
    }

    public function send(User $actor, Conversation $conversation, string $body, string $type = 'text', ?array $attachment = null): Message
    {
        $this->auth->assertParticipant($actor, $conversation);

        $message = $conversation->messages()->create([
            'sender_user_id' => $actor->id,
            'body' => $body,
            'message_type' => $type,
            'attachment' => $attachment,
        ]);

        $this->broadcast('message.sent', [
            'conversation_id' => $conversation->id,
            'message' => $message->load('sender:id,name'),
            'participant_ids' => $conversation->participantRows()->pluck('user_id'),
        ]);

        return $message;
    }

    public function markRead(User $user, Conversation $conversation): void
    {
        $this->auth->assertParticipant($user, $conversation);
        $ids = $conversation->messages()->where('sender_user_id', '!=', $user->id)->pluck('id');
        foreach ($ids as $id) {
            MessageRead::query()->firstOrCreate(
                ['message_id' => $id, 'user_id' => $user->id],
                ['read_at' => now()]
            );
        }

        $this->broadcast('message.read', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
    }

    public function typing(User $user, Conversation $conversation, bool $started = true): void
    {
        $this->auth->assertParticipant($user, $conversation);
        $this->broadcast($started ? 'typing.started' : 'typing.stopped', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'name' => $user->name,
        ]);
    }

    public function unreadCount(User $user): int
    {
        return Message::query()
            ->whereHas('conversation.participantRows', fn ($q) => $q->where('user_id', $user->id))
            ->where('sender_user_id', '!=', $user->id)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->count();
    }

    private function broadcast(string $event, array $payload): void
    {
        try {
            Http::timeout(1)
                ->withHeaders(['X-WS-Secret' => config('finopal.ws_secret')])
                ->post(rtrim((string) config('finopal.ws_server'), '/').'/broadcast', [
                    'event' => $event,
                    'payload' => $payload,
                ]);
        } catch (\Throwable) {
            // Realtime is best-effort; messages are already persisted.
        }
    }
}

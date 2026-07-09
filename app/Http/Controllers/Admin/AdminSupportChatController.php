<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportChat;
use App\Models\SupportMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSupportChatController extends Controller
{
    /** GET /admin/support-chats?status=open — threads, most recently active first. */
    public function index(Request $request): JsonResponse
    {
        $q = SupportChat::with('user:id,name,email')->orderByDesc('last_message_at')->orderByDesc('id');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        $items = $q->limit(300)->get()->map(fn (SupportChat $c) => [
            'id' => $c->id,
            'status' => $c->status,
            'unread_for_admin' => $c->unread_for_admin,
            'last_message_at' => $c->last_message_at,
            'user_name' => $c->displayName(),
            'user_email' => $c->displayEmail(),
            'is_guest' => $c->user_id === null,
        ]);

        return $this->success([
            'items' => $items,
            'stats' => [
                'open' => SupportChat::where('status', 'open')->count(),
                'unread' => SupportChat::where('unread_for_admin', '>', 0)->count(),
            ],
        ]);
    }

    /** GET /admin/support-chats/{id} — full thread; marks it read for the admin. */
    public function show(int $id): JsonResponse
    {
        $chat = SupportChat::with('user:id,name,email')->findOrFail($id);
        if ($chat->unread_for_admin > 0) {
            $chat->update(['unread_for_admin' => 0]);
        }

        return $this->success($this->payload($chat));
    }

    /** POST /admin/support-chats/{id}/messages — admin replies. */
    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $chat = SupportChat::findOrFail($id);

        SupportMessage::create([
            'chat_id' => $chat->id,
            'sender_user_id' => $request->user()->id,
            'is_admin' => true,
            'body' => $data['body'],
        ]);

        $chat->update([
            'status' => 'open',
            'last_message_at' => now(),
            'unread_for_user' => $chat->unread_for_user + 1,
            'unread_for_admin' => 0,
        ]);

        return $this->success($this->payload($chat->fresh()));
    }

    /** POST /admin/support-chats/{id}/close */
    public function close(int $id): JsonResponse
    {
        $chat = SupportChat::findOrFail($id);
        $chat->update(['status' => 'closed']);

        return $this->success(['id' => $chat->id, 'status' => $chat->status]);
    }

    private function payload(SupportChat $chat): array
    {
        return [
            'id' => $chat->id,
            'status' => $chat->status,
            'user_name' => $chat->displayName(),
            'user_email' => $chat->displayEmail(),
            'is_guest' => $chat->user_id === null,
            'messages' => $chat->messages()->orderBy('id')->get()
                ->map(fn (SupportMessage $m) => [
                    'id' => $m->id,
                    'is_admin' => $m->is_admin,
                    'body' => $m->body,
                    'created_at' => $m->created_at,
                ]),
        ];
    }
}

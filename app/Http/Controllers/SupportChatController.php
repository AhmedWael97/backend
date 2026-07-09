<?php

namespace App\Http\Controllers;

use App\Models\SupportChat;
use App\Models\SupportMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Live customer-service chat (user side). One open thread per user; a superadmin
 * answers from the admin dashboard. The first message of a new chat emails the
 * support address so nobody waits unnoticed.
 */
class SupportChatController extends Controller
{
    /** GET /support/chat[?read=1] — my thread + messages (created lazily). */
    public function show(Request $request): JsonResponse
    {
        $chat = $this->chatFor($request);

        // Only an actual read clears the badge — background polls must not,
        // or the unread count would vanish before the user ever sees it.
        if ($request->boolean('read') && $chat->unread_for_user > 0) {
            $chat->update(['unread_for_user' => 0]);
        }

        return $this->success($this->payload($chat));
    }

    /** POST /support/chat/messages — user sends a message. */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $chat = $this->chatFor($request);
        $isFirst = $chat->messages()->count() === 0;

        SupportMessage::create([
            'chat_id' => $chat->id,
            'sender_user_id' => $request->user()->id,
            'is_admin' => false,
            'body' => $data['body'],
        ]);

        $chat->update([
            'status' => 'open',
            'last_message_at' => now(),
            'unread_for_admin' => $chat->unread_for_admin + 1,
        ]);

        if ($isFirst) {
            $this->notifySupport($request, $chat, $data['body']);
        }

        return $this->success($this->payload($chat->fresh()));
    }

    private function chatFor(Request $request): SupportChat
    {
        return SupportChat::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['status' => 'open']
        );
    }

    private function payload(SupportChat $chat): array
    {
        return [
            'id' => $chat->id,
            'status' => $chat->status,
            'unread_for_user' => $chat->unread_for_user,
            'messages' => $chat->messages()->orderBy('id')->get()
                ->map(fn (SupportMessage $m) => [
                    'id' => $m->id,
                    'is_admin' => $m->is_admin,
                    'body' => $m->body,
                    'created_at' => $m->created_at,
                ]),
        ];
    }

    /** Best-effort: a failed email must never block the user's message. */
    private function notifySupport(Request $request, SupportChat $chat, string $body): void
    {
        $to = (string) config('services.support.notify_email');
        if ($to === '') {
            return;
        }

        $user = $request->user();
        $text = "New support chat started on EYE.\n\n"
            . "From: {$user->name} <{$user->email}> (user #{$user->id})\n"
            . "Chat: #{$chat->id}\n\n"
            . "Message:\n{$body}\n\n"
            . "Reply here: " . rtrim((string) config('app.frontend_url'), '/') . "/en/admin/support-chats\n";

        try {
            Mail::raw($text, function ($m) use ($to, $user) {
                $m->to($to)->subject("New support chat — {$user->email}");
            });
        } catch (\Throwable $e) {
            Log::warning('Support chat email failed', ['msg' => $e->getMessage()]);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\SupportChat;
use App\Models\SupportMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Support chat for logged-out visitors on the marketing site.
 * The browser keeps `guest_token`; it is the only credential for that thread,
 * so it is generated server-side and never derived from user input.
 */
class GuestSupportChatController extends Controller
{
    /** POST /support/guest/chat — start a thread (name + email + first message). */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $chat = SupportChat::create([
            'user_id' => null,
            'guest_token' => Str::random(48),
            'guest_name' => $data['name'],
            'guest_email' => $data['email'],
            'status' => 'open',
            'last_message_at' => now(),
            'unread_for_admin' => 1,
        ]);

        SupportMessage::create([
            'chat_id' => $chat->id,
            'sender_user_id' => null,
            'is_admin' => false,
            'body' => $data['body'],
        ]);

        $this->notifySupport($chat, $data['body']);

        return $this->success($this->payload($chat->fresh(), withToken: true));
    }

    /** GET /support/guest/chat/{token}[?read=1] */
    public function show(Request $request, string $token): JsonResponse
    {
        $chat = $this->find($token);

        if ($request->boolean('read') && $chat->unread_for_user > 0) {
            $chat->update(['unread_for_user' => 0]);
        }

        return $this->success($this->payload($chat));
    }

    /** POST /support/guest/chat/{token}/messages */
    public function send(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $chat = $this->find($token);

        SupportMessage::create([
            'chat_id' => $chat->id,
            'sender_user_id' => null,
            'is_admin' => false,
            'body' => $data['body'],
        ]);

        $chat->update([
            'status' => 'open',
            'last_message_at' => now(),
            'unread_for_admin' => $chat->unread_for_admin + 1,
        ]);

        return $this->success($this->payload($chat->fresh()));
    }

    private function find(string $token): SupportChat
    {
        return SupportChat::where('guest_token', $token)->firstOrFail();
    }

    private function payload(SupportChat $chat, bool $withToken = false): array
    {
        $out = [
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

        // Only ever handed back once, when the thread is created.
        if ($withToken) {
            $out['guest_token'] = $chat->guest_token;
        }

        return $out;
    }

    private function notifySupport(SupportChat $chat, string $body): void
    {
        $to = (string) config('services.support.notify_email');
        if ($to === '') {
            return;
        }

        $text = "New support chat started on EYE (website visitor).\n\n"
            . "From: {$chat->guest_name} <{$chat->guest_email}>\n"
            . "Chat: #{$chat->id}\n\n"
            . "Message:\n{$body}\n\n"
            . "Reply here: " . rtrim((string) config('app.frontend_url'), '/') . "/en/admin/support-chats\n";

        try {
            Mail::raw($text, function ($m) use ($to, $chat) {
                $m->to($to)->subject("New support chat — {$chat->guest_email}");
            });
        } catch (\Throwable $e) {
            Log::warning('Guest support chat email failed', ['msg' => $e->getMessage()]);
        }
    }
}

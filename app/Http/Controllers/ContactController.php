<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /** POST /contact — public contact form submission. */
    public function store(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $message = ContactMessage::create([
            ...$data,
            'status' => 'new',
            'ip' => $request->ip(),
        ]);

        // Best-effort ping to superadmins so they see it in the panel.
        foreach (User::where('role', 'superadmin')->get() as $admin) {
            try {
                $notifications->send($admin, 'contact_message', [
                    'title' => 'New contact message',
                    'message' => "{$message->name}: {$message->subject}",
                    'contact_id' => $message->id,
                ]);
            } catch (\Throwable $e) {
                // ignore — the message is already stored
            }
        }

        return $this->success(['id' => $message->id], 'Message sent.');
    }
}

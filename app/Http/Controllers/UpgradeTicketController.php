<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\UpgradeTicket;
use App\Models\UpgradeTicketMessage;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * User-facing plan-upgrade tickets: request an upgrade and chat with support.
 * Deliberately NOT behind the `subscribed` gate — a user whose trial has lapsed
 * must still be able to request an upgrade.
 */
class UpgradeTicketController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /** GET /upgrade-tickets — the user's tickets. */
    public function index(Request $request): JsonResponse
    {
        $tickets = UpgradeTicket::where('user_id', $request->user()->id)
            ->with('requestedPlan:id,name,slug')
            ->latest('last_message_at')
            ->latest('id')
            ->get();

        return $this->success($tickets);
    }

    /** POST /upgrade-tickets — open a request (optional plan + first message + attachment). */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'message' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt'],
        ]);

        $plan = !empty($data['plan_id']) ? Plan::find($data['plan_id']) : null;
        $subject = $plan ? "Upgrade to {$plan->name}" : 'Plan upgrade request';

        $ticket = UpgradeTicket::create([
            'user_id' => $user->id,
            'requested_plan_id' => $plan?->id,
            'subject' => $subject,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $this->addMessage($request, $ticket, $user, false, $data['message']);
        $this->notifySuperAdmins($ticket, "New upgrade request from {$user->name}: {$subject}");

        return $this->success($ticket->load('messages', 'requestedPlan:id,name,slug'), 201);
    }

    /** GET /upgrade-tickets/{id} — ticket + messages (owner only). */
    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = UpgradeTicket::where('user_id', $request->user()->id)
            ->with(['messages.sender:id,name', 'requestedPlan:id,name,slug'])
            ->findOrFail($id);

        return $this->success($ticket);
    }

    /** POST /upgrade-tickets/{id}/messages — user reply (body and/or attachment). */
    public function reply(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $ticket = UpgradeTicket::where('user_id', $user->id)->findOrFail($id);

        if (in_array($ticket->status, ['closed', 'resolved'], true)) {
            // Re-open on a new user message.
            $ticket->status = 'open';
        }

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt'],
        ]);
        if (empty($data['body']) && !$request->hasFile('attachment')) {
            return $this->error('Message or attachment is required.', 422);
        }

        $msg = $this->addMessage($request, $ticket, $user, false, $data['body'] ?? null);
        $this->notifySuperAdmins($ticket, "New reply from {$user->name} on: {$ticket->subject}");

        return $this->success($msg->load('sender:id,name'), 201);
    }

    // ── shared helpers (also used by the admin controller via the model) ──────

    private function addMessage(Request $request, UpgradeTicket $ticket, User $sender, bool $isAdmin, ?string $body): UpgradeTicketMessage
    {
        $path = $name = $mime = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file->store("upgrade-tickets/{$ticket->id}", 'public');
            $name = $file->getClientOriginalName();
            $mime = $file->getClientMimeType();
        }

        $msg = $ticket->messages()->create([
            'sender_user_id' => $sender->id,
            'is_admin' => $isAdmin,
            'body' => $body,
            'attachment_path' => $path,
            'attachment_name' => $name,
            'attachment_mime' => $mime,
        ]);

        $ticket->update(['last_message_at' => now()]);

        return $msg;
    }

    private function notifySuperAdmins(UpgradeTicket $ticket, string $body): void
    {
        try {
            $admins = User::where('role', 'superadmin')->get();
            foreach ($admins as $admin) {
                $this->notifications->send($admin, 'upgrade_ticket', [
                    'title' => 'Upgrade request',
                    'body' => $body,
                    'action_url' => "/admin/upgrade-tickets?id={$ticket->id}",
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

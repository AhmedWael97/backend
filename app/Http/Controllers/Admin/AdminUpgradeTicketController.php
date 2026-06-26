<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UpgradeTicket;
use App\Models\UpgradeTicketMessage;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin side of plan-upgrade tickets: reply (with attachments), and apply
 * the plan to the user directly from the ticket (manual upgrade path).
 */
class AdminUpgradeTicketController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /** GET /admin/upgrade-tickets?status= */
    public function index(Request $request): JsonResponse
    {
        $tickets = UpgradeTicket::with(['user:id,name,email', 'requestedPlan:id,name,slug'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('last_message_at')
            ->latest('id')
            ->get();

        return $this->success($tickets);
    }

    /** GET /admin/upgrade-tickets/{id} */
    public function show(int $id): JsonResponse
    {
        $ticket = UpgradeTicket::with([
            'user:id,name,email',
            'requestedPlan:id,name,slug',
            'messages.sender:id,name',
        ])->findOrFail($id);

        return $this->success($ticket);
    }

    /** POST /admin/upgrade-tickets/{id}/messages — admin reply (body and/or attachment). */
    public function reply(Request $request, int $id): JsonResponse
    {
        $ticket = UpgradeTicket::findOrFail($id);
        $admin = $request->user();

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt'],
        ]);
        if (empty($data['body']) && !$request->hasFile('attachment')) {
            return $this->error('Message or attachment is required.', 422);
        }

        $path = $name = $mime = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file->store("upgrade-tickets/{$ticket->id}", 'public');
            $name = $file->getClientOriginalName();
            $mime = $file->getClientMimeType();
        }

        $msg = $ticket->messages()->create([
            'sender_user_id' => $admin->id,
            'is_admin' => true,
            'body' => $data['body'] ?? null,
            'attachment_path' => $path,
            'attachment_name' => $name,
            'attachment_mime' => $mime,
        ]);

        if ($ticket->status === 'open') {
            $ticket->status = 'pending_user';
        }
        $ticket->update(['last_message_at' => now()]);

        $this->notifyUser($ticket, 'Support replied to your upgrade request.');

        return $this->success($msg->load('sender:id,name'), 201);
    }

    /** POST /admin/upgrade-tickets/{id}/resolve — apply the plan and close the ticket. */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $ticket = UpgradeTicket::findOrFail($id);
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);
        $days = (int) ($data['duration_days'] ?? 30);

        // Cancel any active subscription, then grant the new plan.
        Subscription::where('user_id', $ticket->user_id)->where('status', 'active')
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $sub = Subscription::create([
            'user_id' => $ticket->user_id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($days),
            'notes' => "Manual upgrade via ticket #{$ticket->id}",
        ]);

        // System message in the thread + mark resolved.
        $ticket->messages()->create([
            'sender_user_id' => $request->user()->id,
            'is_admin' => true,
            'is_system' => true,
            'body' => "Your account was upgraded to {$plan->name} for {$days} days.",
        ]);
        $ticket->update(['status' => 'resolved', 'resolved_at' => now(), 'last_message_at' => now()]);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'upgrade_ticket.resolve',
            'target_type' => 'UpgradeTicket',
            'target_id' => $ticket->id,
            'before' => null,
            'after' => ['plan_id' => $plan->id, 'subscription_id' => $sub->id, 'days' => $days],
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->notifyUser($ticket, "Your account was upgraded to {$plan->name}.");

        return $this->success(['message' => 'Plan applied and ticket resolved.', 'subscription_id' => $sub->id]);
    }

    private function notifyUser(UpgradeTicket $ticket, string $body): void
    {
        try {
            if ($ticket->user) {
                $this->notifications->send($ticket->user, 'upgrade_ticket', [
                    'title' => 'Upgrade request update',
                    'body' => $body,
                    'action_url' => "/settings/upgrade?id={$ticket->id}",
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

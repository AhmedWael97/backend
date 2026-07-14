<?php

namespace App\Http\Controllers\Social;

use App\Http\Controllers\Controller;
use App\Models\SocialInboxItem;
use App\Services\AnthropicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backend for the social-manager Chrome extension. The extension's content
 * scripts scrape visible comments/DMs/mentions from a page the user has open
 * and is already logged into themselves — we never see or store a platform
 * password/cookie/session. This is just a unified read/draft surface over
 * what the extension already saw in the user's own browser.
 *
 *   GET  /api/v1/social/inbox              — list items
 *   POST /api/v1/social/inbox/sync         — extension pushes newly-seen items (upsert)
 *   POST /api/v1/social/inbox/{id}/draft   — AI-draft a reply
 *   POST /api/v1/social/inbox/{id}/status  — mark read/replied/dismissed
 */
class SocialInboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = SocialInboxItem::where('user_id', $request->user()->id);
        if ($platform = $request->query('platform')) {
            $q->where('platform', $platform);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        return $this->success($q->orderByDesc('created_at')->limit(200)->get());
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'max:100'],
            'items.*.platform' => ['required', 'in:facebook,x,instagram'],
            'items.*.item_type' => ['required', 'in:comment,dm,mention'],
            'items.*.external_id' => ['required', 'string', 'max:255'],
            'items.*.author_name' => ['nullable', 'string', 'max:255'],
            'items.*.author_handle' => ['nullable', 'string', 'max:255'],
            'items.*.message' => ['nullable', 'string', 'max:10000'],
            'items.*.page_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $userId = $request->user()->id;
        $created = 0;
        foreach ($data['items'] as $item) {
            $row = SocialInboxItem::firstOrNew([
                'user_id' => $userId,
                'platform' => $item['platform'],
                'external_id' => $item['external_id'],
            ]);
            if (!$row->exists) {
                $row->fill([
                    'item_type' => $item['item_type'],
                    'author_name' => $item['author_name'] ?? null,
                    'author_handle' => $item['author_handle'] ?? null,
                    'message' => $item['message'] ?? null,
                    'page_url' => $item['page_url'] ?? null,
                    'status' => 'unread',
                ]);
                $row->save();
                $created++;
            }
        }

        return $this->success(['synced' => count($data['items']), 'new' => $created]);
    }

    public function draft(Request $request, AnthropicService $ai, int $id): JsonResponse
    {
        $item = SocialInboxItem::where('user_id', $request->user()->id)->findOrFail($id);

        $system = 'You write short, friendly replies to social media comments/DMs for a business account '
            . '(under 60 words). Match the tone of the incoming message. No hype, no false claims. '
            . 'Return ONLY JSON: {"reply": "..."}.';
        $userMsg = "Platform: {$item->platform}\nFrom: " . ($item->author_name ?: 'a follower')
            . "\nMessage: " . ($item->message ?: '(no text)') . "\n\nWrite a reply.";

        $reply = '';
        try {
            $res = $ai->complete($system, $userMsg, 300);
            $reply = (string) ($res['reply'] ?? '');
        } catch (\Throwable $e) {
            report($e);
        }
        if ($reply === '') {
            $reply = 'Thanks for reaching out! We\'ll get back to you shortly.';
        }

        $item->update(['draft_reply' => $reply, 'status' => 'drafted']);

        return $this->success(['reply' => $reply]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:read,replied,dismissed']]);
        $item = SocialInboxItem::where('user_id', $request->user()->id)->findOrFail($id);
        $item->update(['status' => $data['status']]);
        return $this->success($item);
    }

    /** Per-platform/status counts + a 14-day daily volume series, for the dashboard page. */
    public function summary(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $byPlatform = SocialInboxItem::where('user_id', $userId)
            ->selectRaw('platform, count(*) as total, sum(case when status = \'unread\' then 1 else 0 end) as unread, sum(case when status = \'replied\' then 1 else 0 end) as replied')
            ->groupBy('platform')
            ->get();

        $daily = SocialInboxItem::where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays(14))
            ->selectRaw('to_char(created_at, \'YYYY-MM-DD\') as day, platform, count(*) as total')
            ->groupBy('day', 'platform')
            ->orderBy('day')
            ->get();

        return $this->success(['by_platform' => $byPlatform, 'daily' => $daily]);
    }
}

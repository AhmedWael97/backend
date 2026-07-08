<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminContactController extends Controller
{
    /** GET /admin/contact-messages — list, newest first. */
    public function index(Request $request): JsonResponse
    {
        $items = ContactMessage::latest()->limit(500)->get();

        return $this->success([
            'items' => $items,
            'stats' => [
                'total' => ContactMessage::count(),
                'new' => ContactMessage::where('status', 'new')->count(),
            ],
        ]);
    }

    /** POST /admin/contact-messages/{id}/read — mark as read. */
    public function markRead(int $id): JsonResponse
    {
        $m = ContactMessage::findOrFail($id);
        $m->update(['status' => 'read']);

        return $this->success(['id' => $m->id, 'status' => $m->status]);
    }

    /** DELETE /admin/contact-messages/{id} */
    public function destroy(int $id): JsonResponse
    {
        ContactMessage::findOrFail($id)->delete();

        return $this->success(['deleted' => true]);
    }
}

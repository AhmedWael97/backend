<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\SavedView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedViewController extends Controller
{
    public function index(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return $this->success(
            SavedView::where('domain_id', $domain->id)
                ->where('user_id', $request->user()->id)
                ->latest()
                ->get()
        );
    }

    public function store(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
        ]);

        $view = SavedView::create([
            'user_id' => $request->user()->id,
            'domain_id' => $domain->id,
            'name' => $data['name'],
            'filters' => $data['filters'],
        ]);

        return $this->success($view, 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        SavedView::where('user_id', $request->user()->id)
            ->findOrFail($id)
            ->delete();

        return $this->success(['message' => 'Saved view deleted.']);
    }
}

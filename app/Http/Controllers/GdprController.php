<?php

namespace App\Http\Controllers;

use App\Jobs\DeleteVisitorDataJob;
use App\Models\DataDeletionRequest;
use App\Models\Domain;
use App\Models\VisitorOptout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GdprController extends Controller
{
    /**
     * DELETE /api/gdpr/visitor
     * Queue deletion of all visitor data for a given visitor_id + domain pair.
     */
    public function deleteVisitor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain_id' => ['required', 'integer'],
            'visitor_id' => ['required', 'string', 'max:255'],
        ]);

        $domain = Domain::where('id', $data['domain_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $deletion = DataDeletionRequest::create([
            'domain_id' => $domain->id,
            'visitor_id' => $data['visitor_id'],
            'requested_at' => now(),
            'status' => 'pending',
        ]);

        DeleteVisitorDataJob::dispatch($deletion->id)->onQueue('default');

        return $this->success(['message' => 'Deletion request queued.', 'id' => $deletion->id], 202);
    }

    /**
     * GET /api/gdpr/optout-status?domain_id={}&visitor_id={}
     */
    public function optoutStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain_id' => ['required', 'integer'],
            'visitor_id' => ['required', 'string', 'max:255'],
        ]);

        $domain = Domain::where('id', $data['domain_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $optedOut = VisitorOptout::where('domain_id', $domain->id)
            ->where('visitor_id', $data['visitor_id'])
            ->exists();

        return $this->success(['opted_out' => $optedOut]);
    }
}

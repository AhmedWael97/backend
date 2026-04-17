<?php

namespace App\Http\Controllers\Tracker;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\VisitorOptout;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OptoutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = $request->input('t') ?? $request->header('X-Eye-Token');
        $visitorId = $request->input('vid');

        if (!$token || !$visitorId) {
            return response('', 400);
        }

        $domain = Domain::where('script_token', $token)
            ->orWhere('previous_script_token', $token)
            ->where('active', true)
            ->first();

        if (!$domain) {
            return response('', 401);
        }

        VisitorOptout::updateOrCreate([
            'domain_id' => $domain->id,
            'visitor_id' => $visitorId,
        ]);

        return response('', 204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Eye-Token',
        ]);
    }
}

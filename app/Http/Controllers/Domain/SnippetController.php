<?php

namespace App\Http\Controllers\Domain;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SnippetController extends Controller
{
    /**
     * Return the HTML snippet the user should paste into their site <head>.
     */
    public function __invoke(Request $request, Domain $domain): Response|array
    {
        $user = $request->user();

        if (!$user->canAccessDomain($domain)) {
            abort(404);
        }

        return response($domain->installSnippet(), 200, ['Content-Type' => 'text/plain']);
    }
}

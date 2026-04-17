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
        if ($domain->user_id !== $request->user()->id) {
            abort(404);
        }

        $trackerUrl = rtrim(config('app.url'), '/') . '/tracker/eye.min.js';
        $token = $domain->script_token;

        $snippet = <<<HTML
<!-- EYE Analytics -->
<script>
  window.EYE_TOKEN = "{$token}";
</script>
<script src="{$trackerUrl}" defer></script>
HTML;

        return response($snippet, 200, ['Content-Type' => 'text/plain']);
    }
}

<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\Request;

abstract class BaseAnalyticsController extends Controller
{
    /**
     * Return the domain if it belongs to the authenticated user, otherwise abort 404.
     */
    protected function ownedDomain(Request $request, Domain $domain): Domain
    {
        if ($domain->user_id !== $request->user()->id) {
            abort(404);
        }

        return $domain;
    }
}

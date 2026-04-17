<?php

namespace App\Http\Controllers\Tracker;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class CorsPreflightController extends Controller
{
    public function __invoke(): Response
    {
        return response('', 204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Eye-Token',
            'Access-Control-Max-Age' => '86400',
        ]);
    }
}

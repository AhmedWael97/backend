<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = AuditLog::with(['admin'])->latest();

        if ($adminId = $request->query('admin_id'))
            $query->where('admin_id', $adminId);
        if ($action = $request->query('action'))
            $query->where('action', $action);
        if ($from = $request->query('from'))
            $query->where('created_at', '>=', $from);
        if ($to = $request->query('to'))
            $query->where('created_at', '<=', $to);

        return response()->json($query->paginate(50));
    }
}

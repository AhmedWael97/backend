<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['user', 'plan', 'paymentMethod']);

        if ($status = $request->query('status'))
            $query->where('status', $status);
        if ($method = $request->query('method'))
            $query->whereHas('paymentMethod', fn($q) => $q->where('type', $method));
        if ($from = $request->query('from'))
            $query->where('created_at', '>=', $from);
        if ($to = $request->query('to'))
            $query->where('created_at', '<=', $to);

        return response()->json($query->latest()->paginate(50));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => Payment::with(['user', 'plan'])->findOrFail($id)]);
    }

    public function refund(Request $request, int $id): JsonResponse
    {
        $payment = Payment::findOrFail($id);

        if ($payment->status !== 'paid') {
            return response()->json(['message' => 'Only paid payments can be refunded.'], 422);
        }

        $before = ['status' => $payment->status];
        $payment->update(['status' => 'refunded']);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'payment.refund',
            'target_type' => 'Payment',
            'target_id' => $id,
            'before' => $before,
            'after' => ['status' => 'refunded'],
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return response()->json(['message' => 'Payment refunded.']);
    }
}

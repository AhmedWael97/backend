<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentMethodController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->success(PaymentMethod::all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'name_ar' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'in:stripe,paypal,manual,bank_transfer'],
            'config' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $method = PaymentMethod::create($data);
        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'payment_method.create',
            'target_type' => 'PaymentMethod',
            'target_id' => $method->id,
            'before' => null,
            'after' => $data,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        return $this->success($method, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $method = PaymentMethod::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'name_ar' => ['nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'in:stripe,paypal,manual,bank_transfer'],
            'config' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $before = $method->only(array_keys($data));
        $method->update($data);
        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'payment_method.update',
            'target_type' => 'PaymentMethod',
            'target_id' => $method->id,
            'before' => $before,
            'after' => $data,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $this->success($method);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $method = PaymentMethod::findOrFail($id);
        $before = $method->toArray();
        $method->delete();
        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'payment_method.delete',
            'target_type' => 'PaymentMethod',
            'target_id' => $id,
            'before' => $before,
            'after' => null,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $this->success(['message' => 'Payment method deleted.']);
    }
}

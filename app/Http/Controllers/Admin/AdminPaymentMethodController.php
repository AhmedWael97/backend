<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentMethodController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => PaymentMethod::all()]);
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

        return response()->json(['data' => PaymentMethod::create($data)], 201);
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

        $method->update($data);

        return response()->json(['data' => $method]);
    }

    public function destroy(int $id): JsonResponse
    {
        PaymentMethod::findOrFail($id)->delete();

        return response()->json(['message' => 'Payment method deleted.']);
    }
}

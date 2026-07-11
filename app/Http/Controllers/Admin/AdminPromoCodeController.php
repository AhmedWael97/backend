<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPromoCodeController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->success(PromoCode::withCount('redemptions')->orderByDesc('created_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:promo_codes,code'],
            'campaign_name' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
        ]);
        if ($data['discount_type'] === 'percent' && $data['discount_value'] > 100) {
            return $this->error('Percent discount cannot exceed 100.', 422);
        }
        $data['code'] = strtoupper($data['code']);
        $data['created_by'] = $request->user()->id;
        $data['is_active'] = true;

        return $this->success(PromoCode::create($data), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $promo = PromoCode::findOrFail($id);
        $data = $request->validate([
            'campaign_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $promo->update($data);

        return $this->success($promo);
    }

    public function destroy(int $id): JsonResponse
    {
        PromoCode::findOrFail($id)->delete();

        return $this->success(['deleted' => true]);
    }
}

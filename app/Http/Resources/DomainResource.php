<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'active' => $this->active,
            'script_verified' => $this->isScriptVerified(),
            'token_in_grace_period' => $this->isTokenInGracePeriod(),
            'script_token' => $this->script_token,
            'settings' => $this->settings,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'exclusions' => $this->whenLoaded('exclusions', fn() => $this->exclusions->map(fn($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'value' => $e->value,
            ])),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'store' => $this->when($this->role === 'merchant', function (): ?array {
                $store = $this->store;

                if ($store === null) {
                    return null;
                }

                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                ];
            }),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\NfcCard\IndexMerchantNfcCardRequest;
use App\Http\Resources\MerchantNfcCardResource;
use App\Models\NfcCard;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class NfcCardController extends Controller
{
    public function index(IndexMerchantNfcCardRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $query = NfcCard::query();

        $search = $validated['search'] ?? null;
        if ($search !== null && $search !== '') {
            $query->where('label', 'like', "%{$search}%");
        }

        if (($validated['type'] ?? null) !== null) {
            $query->where('type', $validated['type']);
        }

        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        $cards = $query
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return MerchantNfcCardResource::collection($cards);
    }

    public function show(Request $request, NfcCard $nfcCard): MerchantNfcCardResource
    {
        Gate::forUser($this->actor($request))->authorize('view', $nfcCard);

        return MerchantNfcCardResource::make($nfcCard);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403, 'Akses tidak tersedia.');
        }

        return $user;
    }
}

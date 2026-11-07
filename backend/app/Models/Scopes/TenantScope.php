<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function __construct(private readonly ?string $ownerRelation = null) {}

    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->allowsPlatformAccess()) {
            return;
        }

        $storeId = $context->storeId();

        if ($storeId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        if ($this->ownerRelation !== null) {
            $builder->whereHas($this->ownerRelation, function (Builder $owner) use ($storeId): void {
                $owner->where('store_id', $storeId);
            });

            return;
        }

        $builder->where($model->qualifyColumn('store_id'), $storeId);
    }
}

<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;

trait ScopesToCurrentTenant
{
    protected static function bootScopesToCurrentTenant(): void
    {
        static::addGlobalScope(new TenantScope(static::tenantOwnerRelation()));
    }

    protected static function tenantOwnerRelation(): ?string
    {
        return null;
    }
}

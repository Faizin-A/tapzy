<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

abstract class TenantOwnedResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $routeModels = collect($this->route()?->parameters() ?? [])
            ->filter(fn (mixed $parameter): bool => $parameter instanceof \Illuminate\Database\Eloquent\Model);

        $ability = match ($this->method()) {
            'GET', 'HEAD' => 'view',
            'DELETE' => 'delete',
            default => 'update',
        };

        if ($routeModels->isNotEmpty()) {
            return $routeModels->every(fn ($model): bool => Gate::forUser($user)->allows($ability, $model))
                && $this->boundParentChildRelationsMatch();
        }

        $collectionAbility = in_array($this->method(), ['GET', 'HEAD'], true) ? 'viewAny' : 'create';

        return Gate::forUser($user)->allows($collectionAbility, $this->resourceModelClass());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    final public function rules(): array
    {
        return array_merge($this->resourceRules(), ['store_id' => ['prohibited']]);
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    abstract protected function resourceModelClass(): string;

    /** @return array<string, array<int, mixed>> */
    protected function resourceRules(): array
    {
        return [];
    }

    /**
     * @return list<array{parent: string, relation: string, child: string}>
     */
    protected function boundParentChildRelations(): array
    {
        return [];
    }

    protected function tenantStoreId(): int
    {
        $context = app(TenantContext::class);
        $user = $this->user();

        if (! $user instanceof User
            || $user->role !== 'merchant'
            || $context->storeId() === null
            || $context->actorId() !== (int) $user->getKey()) {
            throw new AuthorizationException('Tenant context tidak tersedia.');
        }

        return $context->storeId();
    }

    protected function existsInTenant(string $table, string $column = 'id'): Exists
    {
        $storeId = $this->tenantStoreId();

        if ($table === 'stores' && $column === 'id') {
            return Rule::exists($table, $column)->where(
                fn (QueryBuilder $query) => $query->where('id', $storeId)
            );
        }

        return Rule::exists($table, $column)->where(
            fn (QueryBuilder $query) => $query->where('store_id', $storeId)
        );
    }

    protected function existsThroughTenantOwner(
        string $table,
        string $column,
        string $ownerTable,
        string $ownerForeignKey,
        string $ownerKey = 'id',
    ): Exists {
        $storeId = $this->tenantStoreId();
        $tenantOwnerIds = DB::table($ownerTable)
            ->select($ownerKey)
            ->where('store_id', $storeId);

        return Rule::exists($table, $column)->where(
            fn (QueryBuilder $query) => $query->whereIn($ownerForeignKey, $tenantOwnerIds)
        );
    }

    private function boundParentChildRelationsMatch(): bool
    {
        foreach ($this->boundParentChildRelations() as $binding) {
            $parent = $this->route($binding['parent']);
            $child = $this->route($binding['child']);

            if (! $parent instanceof Model || ! $child instanceof Model || ! method_exists($parent, $binding['relation'])) {
                return false;
            }

            $relationship = $parent->{$binding['relation']}();

            if (! $relationship instanceof HasOneOrMany) {
                return false;
            }

            $foreignKey = $relationship->getForeignKeyName();
            $parentKey = $relationship->getLocalKeyName();

            if ((string) $child->getAttribute($foreignKey) !== (string) $parent->getAttribute($parentKey)) {
                return false;
            }
        }

        return true;
    }
}

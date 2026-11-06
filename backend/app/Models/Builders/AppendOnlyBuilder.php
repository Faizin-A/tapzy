<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

class AppendOnlyBuilder extends Builder
{
    public function update(array $values)
    {
        $this->reject('update');
    }

    public function delete()
    {
        $this->reject('delete');
    }

    public function forceDelete()
    {
        $this->reject('force delete');
    }

    public function deleteOrFail()
    {
        $this->reject('delete');
    }

    public function updateOrFail(array $values = [], array $options = [])
    {
        $this->reject('update');
    }

    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        $this->reject('update or insert');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $this->reject('upsert');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->reject('increment');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->reject('decrement');
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        $this->reject('increment');
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        $this->reject('decrement');
    }

    public function touch($column = null)
    {
        $this->reject('touch');
    }

    public function truncate()
    {
        $this->reject('truncate');
    }

    private function reject(string $operation): never
    {
        throw new LogicException("Append-only records do not allow {$operation} operations.");
    }
}

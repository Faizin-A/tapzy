<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLE_INDEX = 'users_role_index';

    private const STORE_INDEX = 'users_store_id_index';

    private const DELETED_AT_INDEX = 'users_deleted_at_index';

    private const STORE_FOREIGN_KEY = 'users_store_id_foreign';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('stores')) {
            throw new RuntimeException('The users and stores tables must exist before this migration runs.');
        }

        $hasRole = Schema::hasColumn('users', 'role');
        $hasStoreId = Schema::hasColumn('users', 'store_id');

        if (DB::table('users')->exists() && (! $hasRole || ! $hasStoreId)) {
            throw new RuntimeException(
                'Cannot safely add role/store columns while users already exist. '
                .'Classify existing accounts and assign merchant stores before migrating.'
            );
        }

        if ($hasRole && $hasStoreId && DB::table('users')->where(function ($query) {
            $query->where(function ($query) {
                $query->where('role', 'super_admin')->whereNotNull('store_id');
            })->orWhere(function ($query) {
                $query->where('role', 'merchant')->whereNull('store_id');
            })->orWhereNotIn('role', ['super_admin', 'merchant']);
        })->exists()) {
            throw new RuntimeException('Existing users violate the role/store invariant; correct them before migrating.');
        }

        $nameColumn = collect(Schema::getColumns('users'))->firstWhere('name', 'name');

        if ($nameColumn === null) {
            throw new RuntimeException('The users.name column is missing.');
        }

        if ($nameColumn['type'] !== 'varchar(150)') {
            if (DB::getDriverName() === 'mysql'
                && DB::table('users')->whereRaw('CHAR_LENGTH(`name`) > 150')->exists()) {
                throw new RuntimeException('Cannot reduce users.name to 150 characters without truncating existing data.');
            }

            Schema::table('users', function (Blueprint $table) {
                $table->string('name', 150)->change();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->enum('role', ['super_admin', 'merchant'])->default('merchant');
            }

            if (! Schema::hasColumn('users', 'store_id')) {
                $table->unsignedBigInteger('store_id')->nullable();
            }

            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        $indexes = Schema::getIndexListing('users');

        if (! in_array(self::ROLE_INDEX, $indexes, true)) {
            Schema::table('users', fn (Blueprint $table) => $table->index('role', self::ROLE_INDEX));
        }

        if (! in_array(self::STORE_INDEX, $indexes, true)) {
            Schema::table('users', fn (Blueprint $table) => $table->index('store_id', self::STORE_INDEX));
        }

        if (! in_array(self::DELETED_AT_INDEX, $indexes, true)) {
            Schema::table('users', fn (Blueprint $table) => $table->index('deleted_at', self::DELETED_AT_INDEX));
        }

        $storeForeignKey = collect(Schema::getForeignKeys('users'))
            ->first(fn (array $foreignKey) => $foreignKey['columns'] === ['store_id']);

        if ($storeForeignKey !== null) {
            if ($storeForeignKey['foreign_table'] !== 'stores'
                || $storeForeignKey['foreign_columns'] !== ['id']
                || $storeForeignKey['on_delete'] !== 'restrict'
                || $storeForeignKey['on_update'] !== 'cascade') {
                throw new RuntimeException('An incompatible foreign key already exists for users.store_id.');
            }
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('store_id', self::STORE_FOREIGN_KEY)
                    ->references('id')
                    ->on('stores')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            });
        }

        // MySQL rejects a CHECK referencing store_id when that column participates
        // in a foreign-key referential action. The model enforces this invariant.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $storeForeignKeys = collect(Schema::getForeignKeys('users'))
            ->filter(fn (array $foreignKey) => $foreignKey['columns'] === ['store_id']);
        $storeForeignKey = $storeForeignKeys->first();

        if ($storeForeignKey !== null && $storeForeignKey['name'] === self::STORE_FOREIGN_KEY) {
            Schema::table('users', fn (Blueprint $table) => $table->dropForeign($storeForeignKey['name']));
        } elseif ($storeForeignKey !== null) {
            throw new RuntimeException(
                'Refusing to drop a users.store_id foreign key that is not owned by this migration.'
            );
        }

        foreach ([self::ROLE_INDEX, self::STORE_INDEX, self::DELETED_AT_INDEX] as $index) {
            if (Schema::hasIndex('users', $index)) {
                Schema::table('users', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }

        $columnsToDrop = array_values(array_filter(
            ['role', 'store_id', 'deleted_at'],
            fn (string $column) => Schema::hasColumn('users', $column)
        ));

        if ($columnsToDrop !== []) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn($columnsToDrop));
        }

        $nameColumn = collect(Schema::getColumns('users'))->firstWhere('name', 'name');

        if ($nameColumn !== null && $nameColumn['type'] === 'varchar(150)') {
            if (DB::getDriverName() === 'mysql'
                && DB::table('users')->whereRaw('CHAR_LENGTH(`name`) > 255')->exists()) {
                throw new RuntimeException('Cannot restore users.name to 255 characters without truncating data.');
            }

            Schema::table('users', fn (Blueprint $table) => $table->string('name')->change());
        }
    }
};

<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

trait InteractsWithPrefixedMySqlDatabase
{
    private ?string $originalConnectionName = null;

    private ?string $prefixedConnectionName = null;

    private ?string $prefixedTablePrefix = null;

    protected function setUpPrefixedMySqlDatabase(string $namespace): void
    {
        $this->originalConnectionName = (string) config('database.default');
        $suffix = bin2hex(random_bytes(4));
        $normalizedNamespace = preg_replace('/[^a-z0-9_]+/', '_', strtolower($namespace)) ?: 'nfc';

        $this->prefixedConnectionName = 'tapzy_mysql_'.$normalizedNamespace.'_'.$suffix;
        $this->prefixedTablePrefix = $normalizedNamespace.'_'.$suffix.'_';

        $originalConfig = config('database.connections.'.$this->originalConnectionName);

        if (! is_array($originalConfig)) {
            throw new \RuntimeException('The default database connection configuration is missing.');
        }

        $config = $originalConfig;
        $config['prefix'] = $this->prefixedTablePrefix;
        $config['prefix_indexes'] = true;

        try {
            config(['database.connections.'.$this->prefixedConnectionName => $config]);

            DB::purge($this->prefixedConnectionName);
            DB::setDefaultConnection($this->prefixedConnectionName);

            $this->assertPrefixedMySqlDatabaseIsSafe();
        } catch (Throwable $exception) {
            $this->restoreOriginalConnection();

            $this->originalConnectionName = null;
            $this->prefixedConnectionName = null;
            $this->prefixedTablePrefix = null;

            throw $exception;
        }
    }

    protected function assertPrefixedMySqlDatabaseIsSafe(): void
    {
        $connection = DB::connection();

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('tapzy_test', $connection->getDatabaseName());
        $this->assertSame('127.0.0.1', (string) $connection->getConfig('host'));
        $this->assertSame('3307', (string) $connection->getConfig('port'));
        $this->assertSame($this->prefixedTablePrefix, $connection->getTablePrefix());
        $this->assertTrue((bool) $connection->getConfig('prefix_indexes'));
    }

    protected function tearDownPrefixedMySqlDatabase(array $tables): void
    {
        if ($this->prefixedConnectionName === null || $this->prefixedTablePrefix === null) {
            return;
        }

        try {
            if (config('database.connections.'.$this->prefixedConnectionName) !== null) {
                DB::setDefaultConnection($this->prefixedConnectionName);

                $this->assertPrefixedMySqlDatabaseIsSafe();

                foreach ($tables as $table) {
                    Schema::dropIfExists($table);
                }
            }
        } finally {
            $this->restoreOriginalConnection();

            $this->originalConnectionName = null;
            $this->prefixedConnectionName = null;
            $this->prefixedTablePrefix = null;
        }
    }

    private function restoreOriginalConnection(): void
    {
        if ($this->prefixedConnectionName !== null) {
            DB::purge($this->prefixedConnectionName);
        }

        if ($this->originalConnectionName !== null) {
            DB::setDefaultConnection($this->originalConnectionName);
        }
    }
}

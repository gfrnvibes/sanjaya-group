<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareTestDatabase extends Command
{
    protected $signature = 'test:prepare {--database=sanjaya_test}';

    protected $description = 'Buat database PostgreSQL terpisah untuk integration test tanpa mengubah database development';

    public function handle(): int
    {
        $name = (string) $this->option('database');
        if (! preg_match('/^[a-z][a-z0-9_]*_test$/', $name) || $name === DB::connection()->getDatabaseName()) {
            $this->error('Gunakan nama database terpisah berakhiran _test.');

            return self::FAILURE;
        }
        if (! DB::scalar('SELECT 1 FROM pg_database WHERE datname = ?', [$name])) {
            DB::statement('CREATE DATABASE "'.$name.'"');
        }
        $this->info('Database test siap: '.$name);

        return self::SUCCESS;
    }
}

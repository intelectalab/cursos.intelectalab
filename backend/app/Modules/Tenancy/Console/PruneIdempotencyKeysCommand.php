<?php

namespace App\Modules\Tenancy\Console;

use App\Shared\Idempotency\IdempotencyKey;
use Illuminate\Console\Command;

class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Elimina las claves de idempotencia que ya pasaron su plazo de vigencia';

    public function handle(): int
    {
        $deleted = IdempotencyKey::query()
            ->where('created_at', '<', now()->subHours(IdempotencyKey::RETENTION_HOURS))
            ->delete();

        $this->info("Claves eliminadas: {$deleted}");

        return self::SUCCESS;
    }
}

<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Application\SyncAccessCatalog;
use Illuminate\Console\Command;

class SyncAccessCatalogCommand extends Command
{
    protected $signature = 'access:sync';

    protected $description = 'Sincroniza el catálogo de permisos y los roles de sistema de todas las organizaciones';

    public function handle(SyncAccessCatalog $sync): int
    {
        $updated = $sync->handle();

        $this->info("Catálogo sincronizado. Roles de sistema actualizados: {$updated}.");

        return self::SUCCESS;
    }
}

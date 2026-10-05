<?php

namespace Tests\Unit;

use App\Modules\Identity\Domain\Catalog\PermissionCatalog;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use PHPUnit\Framework\TestCase;

class PermissionCatalogTest extends TestCase
{
    public function test_wildcards_cover_required_permissions(): void
    {
        $this->assertTrue(PermissionCatalog::matches('*', 'courses.publish'));
        $this->assertTrue(PermissionCatalog::matches('tickets.*', 'tickets.create'));
        $this->assertTrue(PermissionCatalog::matches('courses.read', 'courses.read'));

        $this->assertFalse(PermissionCatalog::matches('tickets.*', 'tickets'));
        $this->assertFalse(PermissionCatalog::matches('tickets.*', 'ticketsx.create'));
        $this->assertFalse(PermissionCatalog::matches('courses.read', 'courses.read.all'));
        $this->assertFalse(PermissionCatalog::matches('courses.read', 'courses.create'));
    }

    public function test_every_role_permission_exists_in_the_catalog(): void
    {
        $catalog = PermissionCatalog::all();

        foreach (RoleCatalog::defaults() as $code => $role) {
            foreach ($role['permissions'] as $permission) {
                $this->assertArrayHasKey($permission, $catalog, "{$code}: {$permission}");
            }
        }
    }
}

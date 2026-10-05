<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeScaleTest extends TestCase
{
    use RefreshDatabase;

    private function settingsPayload(int $gradeScale): array
    {
        return [
            'name' => 'Academia Acme',
            'primary_currency' => 'PEN',
            'timezone' => 'America/Lima',
            'session_minutes' => 30,
            'grade_scale' => $gradeScale,
        ];
    }

    public function test_scales_are_created_edited_and_deleted_with_audit(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');
        $url = $this->tenantUrl('acme', '/settings/grade-scales');

        $this->withToken($token)->getJson($url)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.max_score', 20)
            ->assertJsonPath('data.0.built_in', true)
            ->assertJsonPath('data.0.in_use', true)
            ->assertJsonPath('meta.grade_scale', 20);

        $id = $this->withToken($token)->postJson($url, ['name' => 'Sobre 10', 'max_score' => 10])
            ->assertCreated()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.name', 'Sobre 10')
            ->assertJsonPath('data.2.built_in', false)
            ->json('data.2.id');

        $this->withToken($token)->putJson("{$url}/{$id}", ['name' => 'Sobre 7', 'max_score' => 7])
            ->assertOk()
            ->assertJsonPath('data.2.name', 'Sobre 7')
            ->assertJsonPath('data.2.max_score', 7);

        $this->withToken($token)->deleteJson("{$url}/{$id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame(
            ['grade_scales.created', 'grade_scales.deleted', 'grade_scales.updated'],
            AuditEvent::query()->where('action', 'like', 'grade_scales.%')->orderBy('action')->pluck('action')->all()
        );
    }

    public function test_scale_names_and_maximums_cannot_repeat(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');
        $url = $this->tenantUrl('acme', '/settings/grade-scales');

        $this->withToken($token)->postJson($url, ['name' => 'Sobre 10', 'max_score' => 10])->assertCreated();

        $invalid = [
            ['name' => 'Sobre 10', 'max_score' => 5],
            ['name' => 'Otra', 'max_score' => 10],
            ['name' => 'Otra', 'max_score' => 20],
            ['name' => 'Vigesimal', 'max_score' => 5],
            ['name' => 'Otra', 'max_score' => 0],
            ['name' => 'Otra', 'max_score' => 1001],
            ['name' => 'Otra', 'max_score' => 4.5],
        ];

        foreach ($invalid as $payload) {
            $this->withToken($token)->postJson($url, $payload)->assertStatus(422);
        }
    }

    public function test_only_existing_scales_can_be_used_and_a_scale_in_use_is_protected(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');
        $settings = $this->tenantUrl('acme', '/settings');
        $url = $this->tenantUrl('acme', '/settings/grade-scales');

        $this->withToken($token)->putJson($settings, $this->settingsPayload(10))
            ->assertStatus(422)
            ->assertJsonStructure(['details' => ['grade_scale']]);

        $id = $this->withToken($token)->postJson($url, ['name' => 'Sobre 10', 'max_score' => 10])->json('data.2.id');

        $this->withToken($token)->putJson($settings, $this->settingsPayload(10))
            ->assertOk()
            ->assertJsonPath('data.grade_scale', 10);

        $this->withToken($token)->deleteJson("{$url}/{$id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'GRADE_SCALE_IN_USE');

        // Al cambiar la nota máxima de la escala en uso, la configuración la sigue
        $this->withToken($token)->putJson("{$url}/{$id}", ['name' => 'Sobre 10', 'max_score' => 5])
            ->assertOk()
            ->assertJsonPath('meta.grade_scale', 5)
            ->assertJsonPath('data.2.in_use', true);

        $this->withToken($token)->putJson($settings, $this->settingsPayload(20))->assertOk();
        $this->withToken($token)->deleteJson("{$url}/{$id}")->assertOk();
    }

    public function test_scales_are_isolated_and_require_permission(): void
    {
        $alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');
        $student = $this->createUser($alfa, RoleCatalog::STUDENT);

        $alfaUrl = $this->tenantUrl('alfa', '/settings/grade-scales');
        $betaUrl = $this->tenantUrl('beta', '/settings/grade-scales');

        $id = $this->withToken($this->login('alfa', 'admin@alfa.com'))
            ->postJson($alfaUrl, ['name' => 'Sobre 10', 'max_score' => 10])
            ->json('data.2.id');

        $betaToken = $this->login('beta', 'admin@beta.com');
        $this->withToken($betaToken)->getJson($betaUrl)->assertJsonCount(2, 'data');
        $this->withToken($betaToken)->putJson("{$betaUrl}/{$id}", ['name' => 'Ajena', 'max_score' => 9])->assertNotFound();
        $this->withToken($betaToken)->deleteJson("{$betaUrl}/{$id}")->assertNotFound();
        // El mismo nombre y nota máxima se pueden repetir en otra organización
        $this->withToken($betaToken)->postJson($betaUrl, ['name' => 'Sobre 10', 'max_score' => 10])->assertCreated();

        $studentToken = $this->login('alfa', $student->email);
        $this->withToken($studentToken)->getJson($alfaUrl)->assertForbidden();
        $this->withToken($studentToken)->postJson($alfaUrl, ['name' => 'Sobre 5', 'max_score' => 5])->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M09 (20/10/2026): el SuperAdmin marca en un mapa el punto del local (o pega un enlace de Google
 * Maps) y «Cómo llegar» abre Google Maps exactamente ahí.
 */
class UbicacionNegocioTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = User::create(['role' => 'superadmin', 'nombre' => 'Sara Super', 'email' => 'super@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge(['direccion' => 'Esquina frente al malecón', 'telefono' => '3227259965'], $cambios);
    }

    public function test_el_superadmin_guarda_el_punto_y_como_llegar_abre_ese_punto(): void
    {
        $this->getJson('/api/configuracion')->assertJsonPath('negocio.latitud', null)
            ->assertJsonPath('negocio.mapa_url', 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(config('fragua_real.negocio.direccion')));

        Sanctum::actingAs($this->super);
        $this->putJson('/api/negocio', $this->datos(['latitud' => 1.6145213, 'longitud' => -75.6063117]))->assertOk()
            ->assertJsonPath('latitud', 1.6145213)
            ->assertJsonPath('longitud', -75.6063117)
            ->assertJsonPath('mapa_url', 'https://www.google.com/maps/search/?api=1&query=1.6145213%2C-75.6063117');

        // Se puede quitar el punto.
        $this->putJson('/api/negocio', $this->datos(['latitud' => null, 'longitud' => null]))->assertOk()->assertJsonPath('latitud', null);
        // Los dos o ninguno, y en rango.
        $this->putJson('/api/negocio', $this->datos(['latitud' => 1.6]))->assertStatus(422)->assertJsonValidationErrors(['longitud']);
        $this->putJson('/api/negocio', $this->datos(['latitud' => 95, 'longitud' => 10]))->assertStatus(422)->assertJsonValidationErrors(['latitud']);
    }

    public function test_el_administrador_no_cambia_la_ubicacion(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/negocio', $this->datos(['latitud' => 1.6, 'longitud' => -75.6]))->assertForbidden();
        $this->postJson('/api/negocio/ubicacion-desde-enlace', ['enlace' => '1.6, -75.6'])->assertForbidden();
        // Dirección y teléfono sí.
        $this->putJson('/api/negocio', $this->datos())->assertOk();
    }

    public function test_lee_las_coordenadas_de_enlaces_de_google_maps(): void
    {
        Sanctum::actingAs($this->super);
        $largo = 'https://www.google.com/maps/place/Fragua+Real/@1.6144,-75.6062,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d1.6145213!4d-75.6063117';
        $this->postJson('/api/negocio/ubicacion-desde-enlace', ['enlace' => $largo])->assertOk()
            ->assertExactJson(['latitud' => 1.6145213, 'longitud' => -75.6063117]);
        $this->postJson('/api/negocio/ubicacion-desde-enlace', ['enlace' => 'https://maps.google.com/?q=1.61,-75.60'])->assertOk()
            ->assertJsonPath('latitud', 1.61);
        $this->postJson('/api/negocio/ubicacion-desde-enlace', ['enlace' => '1.6144, -75.6062'])->assertOk()
            ->assertJsonPath('longitud', -75.6062);

        // Enlace corto de «Compartir»: se sigue hasta la página de Google, que trae el punto.
        Http::fake(['maps.app.goo.gl/*' => Http::response('<meta content="https://www.google.com/maps/place/X/@1.6,-75.6,17z/data=!3d1.6145!4d-75.6063">', 200)]);
        $this->postJson('/api/negocio/ubicacion-desde-enlace', ['enlace' => 'https://maps.app.goo.gl/abc123'])->assertOk()
            ->assertExactJson(['latitud' => 1.6145, 'longitud' => -75.6063]);

        // Otro sitio: no se consulta y no se acepta.
        $this->postJson('/api/negocio/ubicacion-desde-enlace', ['enlace' => 'https://ejemplo.com/mapa'])->assertStatus(422);
        Http::assertNotSent(fn ($pedido) => str_contains($pedido->url(), 'ejemplo.com'));
    }
}

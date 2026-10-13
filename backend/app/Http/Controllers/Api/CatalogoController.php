<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Catalogo\StoreCategoriaRequest;
use App\Http\Requests\Api\Catalogo\StoreComboRequest;
use App\Http\Requests\Api\Catalogo\StoreExtraRequest;
use App\Http\Requests\Api\Catalogo\StoreProductoRequest;
use App\Http\Requests\Api\Catalogo\StoreVarianteRequest;
use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Configuracion;
use App\Models\Extra;
use App\Models\Producto;
use App\Models\Variante;
use App\Services\CatalogoService;
use App\Services\ComboService;
use App\Services\PromocionService;
use Illuminate\Http\Request;

/**
 * M04 — Menú y Catálogo. Agrupa categorías, productos, variantes/extras (a través del
 * producto) y combos. Las promociones activas se consultan aquí también porque son parte
 * del catálogo que ve el Cliente, aunque su gestión de reglas vive en PromocionService.
 */
class CatalogoController extends Controller
{
    public function __construct(
        private readonly ComboService $comboService,
        private readonly PromocionService $promocionService,
        private readonly CatalogoService $catalogoService,
    ) {
    }

    // ---- Categorías ----

    // Mismo criterio que productos(): el catálogo público (Cliente/invitado) solo ve
    // categorías activas; el Administrador necesita ver también las inactivas para poder
    // reactivarlas (si no, una vez desactivada una categoría desaparecería para siempre
    // de la gestión).
    //
    // BUG encontrado y corregido junto con esto: esta ruta (y productos()) es pública
    // — vive fuera del grupo auth:sanctum de routes/api.php porque Cliente/invitado
    // deben poder consultarla sin sesión — y el guard por defecto de la app es "web"
    // (config/auth.php), no "sanctum". $request->user() resuelve con el guard por
    // defecto, así que SIEMPRE daba null aquí, sin importar el token Bearer enviado:
    // el filtro por rol de productos() (ya existente antes de este cambio) nunca
    // distinguía Admin/Mesero de un invitado. Se resuelve pidiendo el guard "sanctum"
    // explícitamente — sí lee el header Authorization por sí solo, sin depender de
    // que el middleware auth:sanctum haya corrido antes.
    // Fase 2, tarea 3 (RF-M04-020/021, RN-M04-09): la consulta pública trae solo lo que se puede
    // pedir también DENTRO de cada categoría. Antes, con la categoría activa, venían todos sus
    // productos (incluidos los dados de baja y los agotados) a cualquiera sin sesión.
    public function categorias(Request $request)
    {
        $usuario = $request->user('sanctum');
        $esPublico = ! $usuario || $usuario->esCliente();

        $query = Categoria::with(['productos' => fn ($q) => $esPublico
            ? $q->where('activo', true)->where('disponible', true)->orderBy('nombre')
            : $q->orderBy('nombre')]);
        if ($esPublico) {
            $query->where('activo', true);
        }

        return response()->json($query->orderBy('nombre')->get());
    }

    public function storeCategoria(StoreCategoriaRequest $request)
    {
        $categoria = Categoria::create($request->validated());
        $this->catalogoService->auditar('crear_categoria', $categoria, null, $request->user());

        return response()->json($categoria, 201);
    }

    // El backend solo permitía crear categorías, no editarlas (ni el nombre ni
    // reactivarlas/desactivarlas) — necesario para que la Vista Administrador tenga CRUD
    // completo de categorías.
    // RF-M04-003: desactivar una categoría oculta sus productos (y los combos que los usan) en
    // todos los canales; no se borra nada.
    public function updateCategoria(StoreCategoriaRequest $request, Categoria $categoria)
    {
        $antes = $categoria->getAttributes();
        $categoria->update($request->validated());
        $this->catalogoService->auditar('editar_categoria', $categoria, $antes, $request->user());

        return response()->json($categoria);
    }

    // ---- Productos ----

    // RN-M04-08 / RF-M04-018: el catálogo público solo lista lo activo y disponible.
    // Ver la nota en categorias() sobre por qué usa $request->user('sanctum') en vez de
    // $request->user() — esta ruta también es pública, así que el guard por defecto
    // ("web") nunca autenticaba al Admin/Mesero aquí.
    // Fase 1, tarea 5: RF-M04-025, HU-M04-013 — ?q= busca por nombre y ?categoria_id= filtra por
    // categoría, para el Administrador, el Mesero y el Cliente. El público (Cliente o sin sesión)
    // solo ve lo que se puede pedir: producto activo y disponible, de una categoría activa, y sus
    // variantes activas y no agotadas (RF-M04-018/020/021). El personal ve también lo agotado,
    // para poder volver a habilitarlo (RF-M04-017).
    public function productos(Request $request)
    {
        $usuario = $request->user('sanctum');
        $esPublico = ! $usuario || $usuario->esCliente();

        $query = Producto::with([
            'variantes' => fn ($q) => $esPublico ? $q->where('activa', true)->where('disponible', true) : $q,
            'extrasEspecificos' => fn ($q) => $esPublico ? $q->where('activo', true) : $q,
            'extrasGenerales:id,nombre,precio,alcance,activo',
            'categoria',
        ]);

        if ($esPublico) {
            $query->where('activo', true)->where('disponible', true)
                ->whereHas('categoria', fn ($q) => $q->where('activo', true));
        }
        if ($request->filled('q')) {
            $texto = '%'.mb_strtolower(trim((string) $request->input('q'))).'%';
            $query->whereRaw('LOWER(nombre) LIKE ?', [$texto]);
        }
        if ($request->filled('categoria_id')) {
            $query->where('categoria_id', $request->integer('categoria_id'));
        }

        $productos = $query->orderBy('nombre')->get();
        // Fase 2, tarea 3 (RF-M04-010/011): la misma lista de extras para Menú, QR y Mesero.
        $this->catalogoService->conExtrasDisponibles($productos);

        // Fase 2, tarea 1 (RF-M04-035): cada producto trae las promociones automáticas de producto o
        // de categoría que le aplican ahora, para mostrar el precio rebajado en el menú, en el QR y
        // al Mesero. Las promociones al total del pedido se ven en el carrito (cotización).
        $reglas = $this->promocionService->automaticasVigentes();
        $productos->each(fn (Producto $p) => $p->setAttribute('promociones_vigentes', array_values(array_map(
            fn ($r) => ['nombre' => $r['nombre'], 'tipo_valor' => $r['tipo_valor'], 'valor' => $r['valor']],
            array_filter($reglas, fn ($r) => ($r['alcance'] === 'producto' && in_array($p->id, $r['productos'], true))
                || ($r['alcance'] === 'categoria' && in_array((int) $p->categoria_id, $r['categorias'], true))),
        ))));

        return response()->json($productos);
    }

    public function storeProducto(StoreProductoRequest $request)
    {
        $data = $request->validated();
        $generales = $data['extras_generales'] ?? null;
        unset($data['extras_generales']);

        $producto = Producto::create($data);
        if ($generales !== null) {
            $producto->extrasGenerales()->sync($generales);
        }
        $this->catalogoService->auditar('crear_producto', $producto, null, $request->user());

        return response()->json($producto->load(['categoria', 'extrasGenerales:id,nombre']), 201);
    }

    // Fase 2, tarea 3: al dar de baja un producto se desactivan sus combos (RF-M04-027, RN-M04-12);
    // `extras_generales` asocia los extras generales que ofrece (RF-M04-011). Cada cambio, incluido
    // el de precio, queda en la auditoría (RF-M04-022).
    public function updateProducto(StoreProductoRequest $request, Producto $producto)
    {
        $data = $request->validated();
        $generales = $data['extras_generales'] ?? null;
        unset($data['extras_generales']);

        $antes = $producto->getAttributes();
        $estabaActivo = $producto->activo;
        $producto->update($data);

        $extra = [];
        if ($generales !== null) {
            $cambio = $producto->extrasGenerales()->sync($generales);
            if ($cambio['attached'] || $cambio['detached']) {
                $extra['extras_generales'] = $producto->extrasGenerales()->pluck('extras.id')->all();
            }
        }
        if ($estabaActivo && ! $producto->activo) {
            $desactivados = $this->catalogoService->desactivarCombosDe($producto, $request->user());
            if ($desactivados) {
                $extra['combos_desactivados'] = $desactivados;
            }
        }
        $this->catalogoService->auditar('editar_producto', $producto, $antes, $request->user(), $extra);

        $producto->load(['categoria', 'extrasGenerales:id,nombre']);
        // Para avisar en pantalla qué combos se desactivaron con la baja.
        $producto->setAttribute('combos_desactivados', $extra['combos_desactivados'] ?? []);

        return response()->json($producto);
    }

    // RF-M04-016/017: alternar agotado/disponible no requiere el permiso de edición
    // completa de updateProducto() (exclusivo Administrador) — el Mesero también puede
    // hacerlo desde el piso, sin tocar el resto del producto.
    public function toggleDisponible(Request $request, Producto $producto)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);
        $request->validate(['disponible' => ['required', 'boolean']]);

        $producto->disponible = $request->boolean('disponible');
        $producto->save();

        // Fase 1, tarea 5: quién marcó agotado o disponible, y cuándo (M00, auditoría).
        app(\App\Services\AuditoriaService::class)->registrar('cambiar_disponibilidad', 'Producto', $producto->id, [
            'nombre' => $producto->nombre, 'disponible' => $producto->disponible,
        ], $request->user());

        return response()->json($producto->load(['variantes', 'categoria']));
    }

    // RF-M04-016/017 (Fase 1, tarea 5): lo mismo para una variante (ej. se acabó el tamaño grande).
    public function toggleDisponibleVariante(Request $request, Variante $variante)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);
        $request->validate(['disponible' => ['required', 'boolean']]);

        $variante->disponible = $request->boolean('disponible');
        $variante->save();

        app(\App\Services\AuditoriaService::class)->registrar('cambiar_disponibilidad', 'Variante', $variante->id, [
            'nombre' => $variante->producto?->nombre.' ('.$variante->nombre.')', 'disponible' => $variante->disponible,
        ], $request->user());

        return response()->json($variante);
    }

    public function promocionesDeProducto(Producto $producto)
    {
        return response()->json($this->promocionService->activasParaProducto($producto));
    }

    // ---- Variantes (M04 parte 2) ----
    // Se manejan siempre en el contexto de su producto (ya vienen incluidas en
    // productos() vía with('variantes')) — no hay un listado propio, solo alta/edición.

    public function storeVariante(StoreVarianteRequest $request)
    {
        $variante = Variante::create($request->validated());
        $this->catalogoService->auditar('crear_variante', $variante, null, $request->user());

        return response()->json($variante, 201);
    }

    public function updateVariante(StoreVarianteRequest $request, Variante $variante)
    {
        $antes = $variante->getAttributes();
        $variante->update($request->validated());
        $this->catalogoService->auditar('editar_variante', $variante, $antes, $request->user());

        return response()->json($variante);
    }

    // ---- Extras (M04 parte 2) ----

    // Único listado propio: a diferencia de las variantes y los extras "específicos" (que
    // viajan dentro de su producto vía extrasEspecificos), los extras "generales" no tienen
    // producto_id — no hay forma de descubrirlos salvo pidiéndolos todos aquí.
    // Fase 2, tarea 3: cada extra general trae los productos a los que se asoció (vacío = todo el menú).
    public function extras()
    {
        return response()->json(Extra::with(['producto', 'productos:id,nombre'])->orderBy('nombre')->get());
    }

    public function storeExtra(StoreExtraRequest $request)
    {
        $extra = Extra::create($request->validated());
        $this->catalogoService->auditar('crear_extra', $extra, null, $request->user());

        return response()->json($extra->load(['producto', 'productos:id,nombre']), 201);
    }

    public function updateExtra(StoreExtraRequest $request, Extra $extra)
    {
        $antes = $extra->getAttributes();
        $extra->update($request->validated());
        $this->catalogoService->auditar('editar_extra', $extra, $antes, $request->user());

        return response()->json($extra->load(['producto', 'productos:id,nombre']));
    }

    // ---- Combos ----

    // Lista lo que el Cliente puede pedir hoy: vigente Y sin componentes agotados.
    public function combos()
    {
        return response()->json($this->comboService->disponibles());
    }

    // Gestión (Administrador): a diferencia de combos(), esta trae TODOS los combos —
    // incluidos los inactivos o fuera de vigencia — porque si no, uno desactivado o
    // vencido desaparecería para siempre de la pantalla de edición.
    public function combosAdmin()
    {
        return response()->json(Combo::with('productos')->latest()->get());
    }

    public function storeCombo(StoreComboRequest $request)
    {
        $data = $request->validated();
        $productos = $data['productos'];
        unset($data['productos']);
        $this->exigirComponentesActivos($data, $productos);

        $combo = Combo::create($data);
        foreach ($productos as $p) {
            $combo->productos()->attach($p['producto_id'], ['cantidad' => $p['cantidad']]);
        }
        $this->catalogoService->auditar('crear_combo', $combo, null, $request->user(), ['componentes' => $productos]);

        return response()->json($combo->load('productos'), 201);
    }

    public function updateCombo(StoreComboRequest $request, Combo $combo)
    {
        $data = $request->validated();
        $productos = $data['productos'];
        unset($data['productos']);
        $this->exigirComponentesActivos($data + ['activo' => $combo->activo], $productos);

        $antes = $combo->getAttributes();
        $combo->update($data);
        $cambio = $combo->productos()->sync(
            collect($productos)->mapWithKeys(fn (array $p) => [$p['producto_id'] => ['cantidad' => $p['cantidad']]])
        );
        $this->catalogoService->auditar('editar_combo', $combo, $antes, $request->user(),
            ($cambio['attached'] || $cambio['detached'] || $cambio['updated']) ? ['componentes' => $productos] : []);

        return response()->json($combo->load('productos'));
    }

    // RN-M04-12, RF-M04-027: un combo con un componente dado de baja no puede quedar activo: hay que
    // quitar o reemplazar ese componente primero.
    private function exigirComponentesActivos(array $data, array $productos): void
    {
        if (! ($data['activo'] ?? true)) {
            return;
        }
        $inactivos = $this->catalogoService->componentesInactivos(array_column($productos, 'producto_id'));
        if ($inactivos->isNotEmpty()) {
            $mensaje = 'El combo no puede quedar activo: '.$inactivos->join(', ', ' y ').($inactivos->count() > 1 ? ' están dados' : ' está dado').' de baja. Quítalo o reemplázalo, o deja el combo inactivo.';
            abort(response()->json(['message' => $mensaje, 'errors' => ['productos' => [$mensaje]]], 422));
        }
    }

    // ---- Configuración pública ----

    // Valores "vigentes" que el frontend necesita mostrar ANTES de crear el pedido/reserva
    // (costo de domicilio en el Checkout, depósito en Reservar) — tabla `configuraciones`,
    // editable por el Administrador (ver ConfiguracionController). Son solo lectura: el
    // servidor sigue siendo quien fija el valor real al crear el Pedido/Reserva
    // (PedidoController/ReservaController), nunca el body del Cliente.
    public function configuracion()
    {
        return response()->json([
            'valor_domicilio' => Configuracion::obtenerNumerico(
                'valor_domicilio',
                (float) config('fragua_real.valor_domicilio_default')
            ),
            'deposito_reserva' => Configuracion::obtenerNumerico(
                'deposito_reserva',
                (float) config('fragua_real.deposito_reserva_default')
            ),
        ]);
    }
}

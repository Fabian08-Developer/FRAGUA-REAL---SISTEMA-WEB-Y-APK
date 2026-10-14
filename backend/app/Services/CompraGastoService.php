<?php

namespace App\Services;

use App\Exceptions\AutorizacionRequerida;
use App\Models\CierreDiario;
use App\Models\CompraGasto;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * M03 — Compras, Gastos y Cierre Diario.
 *
 * El Documento Técnico de Arquitectura no incluía un Service para M03 aunque ya existía
 * un CompraGastoController entregado — este Service llena ese vacío para que la regla
 * RN-M03-01 (valor > 0) y RN-M03-04 (anulación, no borrado) no queden repetidas en el
 * Controller.
 *
 * Fase 2, tarea 4 (12/10/2026): cantidad, unidad y observaciones (RF-M03-03/04, RN-M03-02);
 * soporte JPG/PNG/PDF en almacenamiento privado (RF-M03-07, RNF-M03-06/11); filtros y totales
 * del historial (RF-M03-13, HU-M03-03); y trazabilidad de cada alta, corrección y anulación
 * (RF-M03-19, RNF-M03-04): quién y cuándo en el registro, y antes/después en la auditoría.
 *
 * Fase 2, tarea 5 (RF-M03-20, RN-M03-08, HU-M03-09): registrar, corregir o anular algo de un día que
 * ya tiene cierre formal exige que el Administrador confirme con su contraseña y escriba el motivo
 * (`password_confirmacion` y `motivo_correccion`; al anular, el motivo es el de la anulación). El
 * cierre no se reescribe: la corrección queda como un ajuste del cierre (CierreDiarioService).
 */
class CompraGastoService
{
    public const DISCO_SOPORTES = 'local';

    private const CAMPOS_EDITABLES = [
        'tipo', 'categoria', 'descripcion', 'cantidad', 'unidad', 'valor', 'metodo_pago',
        'proveedor', 'numero_factura', 'observaciones', 'fecha',
    ];

    public function __construct(private readonly CajaService $cajaService)
    {
    }

    /** @return array<string, array<int, string>> categorías V1 por tipo (M03, sección 9). */
    public static function categorias(): array
    {
        return config('fragua_real.categorias_compras_gastos');
    }

    public function registrar(array $data, User $actor, ?UploadedFile $soporte = null): CompraGasto
    {
        $valor = (float) $data['valor'];
        if ($valor <= 0) {
            throw new RuntimeException('El valor de la compra/gasto debe ser mayor a 0 (RN-M03-01).');
        }
        $fecha = \Illuminate\Support\Carbon::parse($data['fecha'])->toDateString();
        $cierres = $this->autorizarDiasCerrados([$fecha], $data, $actor, $data['motivo_correccion'] ?? null);

        return DB::transaction(function () use ($data, $actor, $soporte, $valor, $cierres, $fecha) {
            $compraGasto = new CompraGasto();
            $compraGasto->fill(array_intersect_key($data, array_flip(self::CAMPOS_EDITABLES)));
            $compraGasto->valor = $valor;
            $compraGasto->registrado_por = $actor->id;
            $compraGasto->estado = 'activo';
            $compraGasto->save();

            if ($soporte) {
                $this->guardarArchivo($compraGasto, $soporte);
            }

            // RF-M06-013: un gasto en efectivo sale de la caja del día.
            $this->cajaService->recalcularSiEstaAbierto($compraGasto->fecha->toDateString());

            app(AuditoriaService::class)->registrar('registrar_compra_gasto', 'CompraGasto', $compraGasto->id, [
                'tipo' => $compraGasto->tipo,
                'categoria' => $compraGasto->categoria,
                'valor' => (float) $compraGasto->valor,
                'metodo_pago' => $compraGasto->metodo_pago,
                'fecha' => $compraGasto->fecha->toDateString(),
                'con_soporte' => $compraGasto->tiene_soporte,
            ], $actor);

            $this->ajustarCierres($cierres, $compraGasto, 'registrar', [$fecha => $this->delta($compraGasto->tipo, $valor)], [
                'descripcion' => $compraGasto->descripcion, 'valor' => $valor, 'tipo' => $compraGasto->tipo,
            ], (string) ($data['motivo_correccion'] ?? ''), $actor);

            return $compraGasto->fresh(['registradoPorUsuario']);
        });
    }

    // RF-M03-14: editar un registro activo (no anulado) — sigue sin poder borrarse
    // (RN-M03-04); anular() es el único camino para "quitar" un registro definitivamente.
    // RF-M03-19: queda quién y cuándo corrigió, y en la auditoría qué cambió.
    public function editar(CompraGasto $compraGasto, array $data, ?User $actor = null): CompraGasto
    {
        if (! $compraGasto->estaActivo()) {
            throw new RuntimeException('No se puede editar una compra/gasto ya anulada.');
        }

        if (array_key_exists('valor', $data) && (float) $data['valor'] <= 0) {
            throw new RuntimeException('El valor de la compra/gasto debe ser mayor a 0 (RN-M03-01).');
        }

        $fechaAnterior = $compraGasto->fecha?->toDateString();
        $antes = $compraGasto->getAttributes();
        $tipoAnterior = $compraGasto->tipo;
        $valorAnterior = (float) $compraGasto->valor;

        $compraGasto->fill(array_intersect_key($data, array_flip(self::CAMPOS_EDITABLES)));
        if (! $compraGasto->isDirty()) {
            return $compraGasto->load(['registradoPorUsuario', 'editadoPorUsuario']);
        }
        $fechaNueva = $compraGasto->fecha->toDateString();
        $cierres = $this->autorizarDiasCerrados(array_unique([$fechaAnterior, $fechaNueva]), $data, $actor, $data['motivo_correccion'] ?? null);

        $compraGasto->editado_por = $actor?->id;
        $compraGasto->editado_at = now();
        $compraGasto->save();

        // El valor, el método o la fecha pueden haber cambiado: se recalcula la caja de la
        // fecha anterior y la de la nueva (RF-M06-013).
        if ($fechaAnterior) {
            $this->cajaService->recalcularSiEstaAbierto($fechaAnterior);
        }
        $this->cajaService->recalcularSiEstaAbierto($compraGasto->fecha->toDateString());

        $cambios = [];
        foreach ($compraGasto->getChanges() as $campo => $valor) {
            if (in_array($campo, ['updated_at', 'editado_at', 'editado_por'], true)) {
                continue;
            }
            $cambios[$campo] = ['antes' => $antes[$campo] ?? null, 'despues' => $valor];
        }
        app(AuditoriaService::class)->registrar('editar_compra_gasto', 'CompraGasto', $compraGasto->id, ['cambios' => $cambios], $actor);

        // Lo que cambió en cada día cerrado: sale lo de antes y entra lo de ahora.
        $deltas = [];
        $this->sumarDelta($deltas, $fechaAnterior, $this->delta($tipoAnterior, -$valorAnterior));
        $this->sumarDelta($deltas, $fechaNueva, $this->delta($compraGasto->tipo, (float) $compraGasto->valor));
        $this->ajustarCierres($cierres, $compraGasto, 'editar', $deltas, ['cambios' => $cambios], (string) ($data['motivo_correccion'] ?? ''), $actor);

        return $compraGasto->load(['registradoPorUsuario', 'editadoPorUsuario']);
    }

    // RN-M03-04: no se borra un registro de compra/gasto, se anula con motivo.
    public function anular(CompraGasto $compraGasto, string $motivo, ?User $actor = null, ?string $passwordConfirmacion = null): CompraGasto
    {
        if (! $compraGasto->estaActivo()) {
            throw new RuntimeException('Esta compra/gasto ya está anulada.');
        }
        $fecha = $compraGasto->fecha->toDateString();
        $cierres = $this->autorizarDiasCerrados([$fecha], ['password_confirmacion' => $passwordConfirmacion], $actor, $motivo);

        $compraGasto->estado = 'anulado';
        $compraGasto->anulado_motivo = $motivo;
        $compraGasto->anulado_por = $actor?->id;
        $compraGasto->anulado_at = now();
        $compraGasto->save();

        $this->cajaService->recalcularSiEstaAbierto($compraGasto->fecha->toDateString());

        app(AuditoriaService::class)->registrar('anular_compra_gasto', 'CompraGasto', $compraGasto->id, [
            'motivo' => $motivo,
            'valor' => (float) $compraGasto->valor,
            'tipo' => $compraGasto->tipo,
            'fecha' => $compraGasto->fecha->toDateString(),
        ], $actor);

        $this->ajustarCierres($cierres, $compraGasto, 'anular', [$fecha => $this->delta($compraGasto->tipo, -(float) $compraGasto->valor)], [
            'descripcion' => $compraGasto->descripcion, 'valor' => (float) $compraGasto->valor, 'tipo' => $compraGasto->tipo,
        ], $motivo, $actor);

        return $compraGasto->load(['registradoPorUsuario', 'anuladoPorUsuario']);
    }

    // ───────────── Correcciones sobre días cerrados (Fase 2, tarea 5) ─────────────

    /**
     * RN-M03-08, RF-M03-20: si alguna de esas fechas ya tiene cierre formal, exige la contraseña del
     * Administrador que hace el cambio y un motivo. Devuelve los cierres afectados, por fecha.
     *
     * @param  array<int, string|null>  $fechas
     * @return array<string, CierreDiario>
     */
    private function autorizarDiasCerrados(array $fechas, array $data, ?User $actor, ?string $motivo): array
    {
        $fechas = array_values(array_filter(array_unique($fechas)));
        $cierres = CierreDiario::whereIn(DB::raw('DATE(fecha)'), $fechas)->get()
            ->keyBy(fn (CierreDiario $c) => $c->fecha->toDateString())->all();
        if (! $cierres) {
            return [];
        }

        $dias = implode(' y ', array_map(fn ($f) => \Illuminate\Support\Carbon::parse($f)->format('d/m/Y'), array_keys($cierres)));
        // Fase 7, tarea 2: el Mesero autorizado no corrige días cerrados (RN-M03-08).
        if ($actor && ! $actor->esAdministrador()) {
            throw new RuntimeException("El día {$dias} ya tiene cierre: pídele al Administrador que lo registre.");
        }
        $password = $data['password_confirmacion'] ?? null;
        if (! $actor || ! $actor->esAdministrador() || empty($password) || ! Hash::check($password, $actor->password)) {
            throw new AutorizacionRequerida(
                "El día {$dias} ya tiene cierre. Para cambiarlo confirma tu contraseña de Administrador y escribe el motivo (RN-M03-08).",
                ['fechas_cerradas' => array_keys($cierres)],
            );
        }
        if (trim((string) $motivo) === '') {
            throw new AutorizacionRequerida("El día {$dias} ya tiene cierre: escribe el motivo de la corrección.", ['fechas_cerradas' => array_keys($cierres)]);
        }

        return $cierres;
    }

    /** @param array<string, CierreDiario> $cierres  @param array<string, array{0: float, 1: float}> $deltas */
    private function ajustarCierres(array $cierres, CompraGasto $registro, string $accion, array $deltas, array $detalle, string $motivo, ?User $actor): void
    {
        foreach ($cierres as $fecha => $cierre) {
            [$compras, $gastos] = $deltas[$fecha] ?? [0.0, 0.0];
            // Solo hay cierres aquí si autorizarDiasCerrados() ya verificó al Administrador.
            app(CierreDiarioService::class)->registrarAjuste($cierre, $registro, $accion, $compras, $gastos, $detalle, $motivo, $actor);
        }
    }

    /** @return array{0: float, 1: float} [compras, gastos] */
    private function delta(string $tipo, float $valor): array
    {
        return $tipo === 'compra' ? [$valor, 0.0] : [0.0, $valor];
    }

    private function sumarDelta(array &$deltas, ?string $fecha, array $delta): void
    {
        if (! $fecha) {
            return;
        }
        $actual = $deltas[$fecha] ?? [0.0, 0.0];
        $deltas[$fecha] = [round($actual[0] + $delta[0], 2), round($actual[1] + $delta[1], 2)];
    }

    /**
     * RF-M03-07, HU-M03-02: adjunta o reemplaza el soporte. El archivo anterior no se borra
     * (queda nombrado en la auditoría), para no perder evidencia.
     */
    public function adjuntarSoporte(CompraGasto $compraGasto, UploadedFile $archivo, ?User $actor = null): CompraGasto
    {
        if (! $compraGasto->estaActivo()) {
            throw new RuntimeException('No se puede adjuntar un soporte a un registro anulado.');
        }

        $anterior = $compraGasto->soporte_nombre;
        $this->guardarArchivo($compraGasto, $archivo);

        app(AuditoriaService::class)->registrar('adjuntar_soporte_compra_gasto', 'CompraGasto', $compraGasto->id, [
            'archivo' => $compraGasto->soporte_nombre,
            'reemplaza' => $anterior,
        ], $actor);

        return $compraGasto->load(['registradoPorUsuario', 'editadoPorUsuario']);
    }

    private function guardarArchivo(CompraGasto $compraGasto, UploadedFile $archivo): void
    {
        $extension = strtolower($archivo->extension() ?: $archivo->getClientOriginalExtension());
        $ruta = $archivo->storeAs(
            "soportes-compras-gastos/{$compraGasto->id}",
            now()->format('Ymd-His').'-'.Str::random(8).'.'.$extension,
            self::DISCO_SOPORTES,
        );
        if (! $ruta) {
            throw new RuntimeException('No se pudo guardar el soporte. Intenta de nuevo.');
        }

        $compraGasto->soporte_path = $ruta;
        $compraGasto->soporte_nombre = mb_substr($archivo->getClientOriginalName(), 0, 255);
        $compraGasto->soporte_mime = $archivo->getMimeType();
        $compraGasto->save();
    }

    public function existeSoporte(CompraGasto $compraGasto): bool
    {
        return $compraGasto->soporte_path !== null && Storage::disk(self::DISCO_SOPORTES)->exists($compraGasto->soporte_path);
    }

    /**
     * RF-M03-13, HU-M03-03: historial filtrado por fecha (desde/hasta), tipo, categoría, forma de
     * pago, proveedor y estado.
     */
    public function consulta(array $filtros): Builder
    {
        return CompraGasto::query()
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '>=', $v))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '<=', $v))
            ->when($filtros['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($filtros['categoria'] ?? null, fn ($q, $v) => $q->where('categoria', $v))
            ->when($filtros['metodo_pago'] ?? null, fn ($q, $v) => $q->where('metodo_pago', $v))
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($filtros['proveedor'] ?? null, fn ($q, $v) => $q->whereRaw('LOWER(proveedor) LIKE ?', ['%'.mb_strtolower(trim($v)).'%']));
    }

    /** Totales de lo filtrado: solo registros ACTIVOS (RN-M03-04, RF-M03-18). */
    public function totales(Builder $consulta): array
    {
        $activos = (clone $consulta)->where('estado', 'activo');
        $porTipo = (clone $activos)->selectRaw('tipo, COALESCE(SUM(valor), 0) as total, COUNT(*) as cantidad')->groupBy('tipo')->get()->keyBy('tipo');

        return [
            'total_compras' => (float) ($porTipo['compra']->total ?? 0),
            'total_gastos' => (float) ($porTipo['gasto']->total ?? 0),
            'cantidad_activos' => (int) $porTipo->sum('cantidad'),
        ];
    }

    public function totalPorTipoYFecha(string $tipo, \DateTimeInterface $desde, \DateTimeInterface $hasta): float
    {
        return (float) CompraGasto::where('tipo', $tipo)
            ->where('estado', 'activo')
            // whereDate: en SQLite la columna DATE se guarda con hora ("2026-10-13 00:00:00").
            ->whereDate('fecha', '>=', $desde->format('Y-m-d'))
            ->whereDate('fecha', '<=', $hasta->format('Y-m-d'))
            ->sum('valor');
    }
}

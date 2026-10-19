<?php

namespace App\Services;

use App\Models\ReporteExportacion;
use App\Models\User;
use App\Support\Documentos\PiePagina;
use App\Support\Documentos\RegistroImpresiones;
use App\Support\Reportes\PeriodoReporte;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * M08 — Fase 5, tarea 3 (13/10/2026): exportación real de reportes. RF-M08-011 (PDF), RF-M08-012
 * (Excel), RF-M08-014 (auditoría), RNF-M08-006 (descargar sin regenerar), HU-M08-009/010,
 * CA-M08-009/010 (el archivo dice lo mismo que la pantalla).
 *
 * Antes (hasta la Fase 4) el "Excel" era un CSV y el PDF una sola página armada a mano que se
 * cortaba. Ahora:
 *  - documento(): arma, a partir del reporte calculado por ReporteService::calcular() (el mismo
 *    cálculo de la pantalla), un documento con nombres en español: título, periodo, marca de
 *    parcial (RN-M08-05), resumen, comparación con el periodo anterior si se pidió (RF-M08-010,
 *    RN-M08-04) y las tablas del detalle.
 *  - PDF con plantilla (resources/views/reportes/pdf.blade.php), de las páginas que haga falta,
 *    con encabezados de tabla repetidos y "Página n de m".
 *  - Excel .xlsx real (phpoffice/phpspreadsheet): hoja "Resumen" y una hoja por tabla, con
 *    números como números, formato de pesos, encabezados fijos y filtros.
 *  - exportar() guarda el archivo en el disco privado y lo registra en `reporte_exportaciones`;
 *    descargar() lo entrega tal cual cuantas veces se quiera (RNF-M08-006), sin recalcular, y
 *    cada descarga queda en la auditoría. El documento dice cuándo y quién lo generó, así una
 *    descarga posterior nunca se confunde con datos de hoy.
 */
class ReporteExportService
{
    public const DISCO = 'local';

    public const NOMBRES = [
        'ventas-totales' => 'Ventas totales',
        'ventas-por-producto' => 'Ventas por producto y categoría',
        'ventas-por-tipo-y-metodo' => 'Ventas por tipo y método de pago',
        'compras-y-gastos' => 'Compras y gastos',
        'resultado-operativo' => 'Resultado operativo',
        'uso-de-mesas' => 'Uso de mesas',
    ];

    // Métricas del resumen: clave del reporte => [texto, tipo].
    private const METRICAS = [
        'monto_total' => ['Ventas', 'moneda'],
        'cantidad_pedidos' => ['Pedidos finalizados', 'numero'],
        'unidades_totales' => ['Unidades vendidas', 'numero'],
        'total_compras' => ['Compras', 'moneda'],
        'total_gastos' => ['Otros gastos', 'moneda'],
        'total_ventas' => ['Ventas', 'moneda'],
        'otros_ingresos' => ['Depósitos retenidos (no-show)', 'moneda'],
        'resultado_operativo' => ['Resultado operativo', 'moneda'],
        'reservas_creadas' => ['Reservas del periodo', 'numero'],
        'reservas_canceladas' => ['Reservas canceladas', 'numero'],
        'reservas_no_show' => ['Reservas no-show', 'numero'],
        'visitas' => ['Visitas a las mesas', 'numero'],
        'pedidos_locales' => ['Pedidos en el local', 'numero'],
        'mesas_con_uso' => ['Mesas con uso', 'numero'],
    ];

    private const TIPOS_VENTA = ['alimento' => 'Alimentos', 'bebida' => 'Bebidas', 'otro' => 'Otros'];
    private const METODOS = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'en_linea' => 'En línea', 'deposito_reserva' => 'Depósito de reserva'];
    private const ESTADOS_RESERVA = ['pendiente_pago' => 'Pendiente de pago', 'confirmada' => 'Confirmada', 'cancelada' => 'Cancelada', 'no_show' => 'No asistió', 'completada' => 'Completada'];

    public function __construct(private readonly ReporteService $reportes)
    {
    }

    /** Calcula, arma, guarda y registra la exportación (RF-M08-011/012/014). */
    public function exportar(string $reporte, string $formato, PeriodoReporte $periodo, bool $comparar, int $limite, User $actor): ReporteExportacion
    {
        $doc = $this->documento($reporte, $periodo, $comparar, $limite, $actor);

        $extension = $formato === 'excel' ? 'xlsx' : 'pdf';
        $contenido = $formato === 'excel' ? $this->excel($doc) : $this->pdf($doc);
        $ruta = 'reportes/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        Storage::disk(self::DISCO)->put($ruta, $contenido);

        $exportacion = ReporteExportacion::create([
            'reporte' => $reporte,
            'formato' => $formato,
            'desde' => $periodo->desdeFecha,
            'hasta' => $periodo->hastaFecha,
            'parcial' => $periodo->parcial,
            'comparar' => $comparar,
            'archivo' => $ruta,
            'nombre_archivo' => "{$reporte}_{$periodo->desdeFecha}_a_{$periodo->hastaFecha}.{$extension}",
            'bytes' => strlen($contenido),
            'generado_por' => $actor->id,
        ]);

        $this->reportes->registrarExportacion($reporte, $periodo->aArray(), $formato, $actor, [
            'exportacion_id' => $exportacion->id,
            'comparar' => $comparar,
        ]);

        return $exportacion;
    }

    /**
     * RNF-M08-006: el archivo tal como se generó. Con $actor es una descarga posterior: suma y queda
     * en la auditoría (la primera entrega, al exportar, ya quedó como "exportar_reporte").
     */
    public function contenidoGuardado(ReporteExportacion $exportacion, ?User $actor): string
    {
        $disco = Storage::disk(self::DISCO);
        if (! $disco->exists($exportacion->archivo)) {
            throw new RuntimeException('El archivo de esta exportación ya no está en el servidor. Vuelve a exportar el reporte.');
        }
        if (! $actor) {
            return $disco->get($exportacion->archivo);
        }

        $exportacion->increment('descargas');
        app(AuditoriaService::class)->registrar('descargar_reporte', 'Reporte', $exportacion->id, [
            'reporte' => $exportacion->reporte,
            'formato' => $exportacion->formato,
            'periodo' => ['desde' => $exportacion->desde->toDateString(), 'hasta' => $exportacion->hasta->toDateString()],
            'descarga' => $exportacion->descargas,
        ], $actor);

        return $disco->get($exportacion->archivo);
    }

    public static function tipoContenido(string $formato): string
    {
        return $formato === 'excel'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/pdf';
    }

    /**
     * El documento: lo que dicen el PDF y el Excel.
     *
     * @return array{titulo: string, periodo: string, parcial: bool, generado: string, generado_por: string, resumen: list<array{0: string, 1: float|int, 2: string}>, comparacion: ?array, tablas: list<array>}
     */
    public function documento(string $reporte, PeriodoReporte $periodo, bool $comparar, int $limite, User $actor): array
    {
        $datos = $this->reportes->calcular($reporte, $periodo, $limite);

        $resumen = [];
        foreach ($datos['resumen'] as $clave => $valor) {
            [$texto, $tipo] = self::METRICAS[$clave] ?? [Str::ucfirst(str_replace('_', ' ', $clave)), 'numero'];
            $resumen[] = [$texto, $valor, $tipo];
        }

        $comparacion = null;
        if ($comparar) {
            $anterior = $periodo->anterior();
            $variacion = $this->reportes->compararResumen($datos['resumen'], $this->reportes->calcular($reporte, $anterior, $limite)['resumen']);
            $comparacion = [
                'periodo' => $this->textoPeriodo($anterior->desdeFecha, $anterior->hastaFecha),
                'filas' => collect($variacion)->map(function ($v, $clave) {
                    [$texto, $tipo] = self::METRICAS[$clave] ?? [$clave, 'numero'];

                    return [$texto, $v['actual'], $v['anterior'], $v['variacion_absoluta'], $v['variacion_porcentual'], $tipo];
                })->values()->all(),
            ];
        }

        return [
            'reporte' => $reporte,
            'titulo' => self::NOMBRES[$reporte],
            'periodo' => $this->textoPeriodo($periodo->desdeFecha, $periodo->hastaFecha),
            'parcial' => $periodo->parcial,
            'generado' => now()->setTimezone('America/Bogota')->format('d/m/Y g:i a'),
            'generado_por' => $actor->nombre,
            'resumen' => $resumen,
            'comparacion' => $comparacion,
            'tablas' => $this->tablas($reporte, $datos, $limite),
            'nota' => $reporte === 'resultado-operativo'
                ? 'Resultado operativo estimado (ventas − compras − otros gastos). No es la utilidad contable del negocio.'
                : null,
        ];
    }

    private function tablas(string $reporte, array $datos, int $limite): array
    {
        $col = fn (string $texto, string $tipo = 'texto') => ['texto' => $texto, 'tipo' => $tipo];
        $d = $datos['detalle'] ?? [];
        $valor = fn ($fila, string $clave) => is_array($fila) ? ($fila[$clave] ?? null) : ($fila->{$clave} ?? null);

        return match ($reporte) {
            'ventas-por-producto' => [
                [
                    'titulo' => "Los {$limite} más vendidos",
                    'columnas' => [$col('Producto'), $col('Unidades', 'numero'), $col('Monto vendido', 'moneda')],
                    'filas' => collect($d['mas_vendidos'] ?? [])->map(fn ($p) => [$this->nombreProducto($p), (int) $p['unidades_vendidas'], (float) $p['monto_vendido']])->all(),
                ],
                [
                    'titulo' => "Los {$limite} menos vendidos",
                    'columnas' => [$col('Producto'), $col('Unidades', 'numero'), $col('Monto vendido', 'moneda')],
                    'filas' => collect($d['menos_vendidos'] ?? [])->map(fn ($p) => [$this->nombreProducto($p), (int) $p['unidades_vendidas'], (float) $p['monto_vendido']])->all(),
                ],
                [
                    'titulo' => 'Por categoría',
                    'columnas' => [$col('Categoría'), $col('Unidades', 'numero'), $col('Monto vendido', 'moneda')],
                    'filas' => collect($d['por_categoria'] ?? [])->map(fn ($c) => [$valor($c, 'nombre'), (int) $valor($c, 'unidades_vendidas'), (float) $valor($c, 'monto_vendido')])->all(),
                ],
                [
                    'titulo' => 'Todos los productos',
                    'columnas' => [$col('Producto'), $col('Unidades', 'numero'), $col('Monto vendido', 'moneda')],
                    'filas' => collect($d['por_producto'] ?? [])->map(fn ($p) => [$this->nombreProducto($p), (int) $p['unidades_vendidas'], (float) $p['monto_vendido']])->all(),
                ],
            ],
            'ventas-por-tipo-y-metodo' => [
                [
                    'titulo' => 'Por tipo de venta',
                    'columnas' => [$col('Tipo de venta'), $col('Unidades', 'numero'), $col('Monto vendido', 'moneda')],
                    'filas' => collect($d['por_tipo'] ?? [])->map(fn ($t) => [self::TIPOS_VENTA[$valor($t, 'tipo_venta')] ?? (string) $valor($t, 'tipo_venta'), (int) $valor($t, 'unidades_vendidas'), (float) $valor($t, 'monto_vendido')])->all(),
                ],
                [
                    'titulo' => 'Por método de pago',
                    'columnas' => [$col('Método de pago'), $col('Pagos', 'numero'), $col('Monto', 'moneda')],
                    'filas' => collect($d['por_metodo'] ?? [])->map(fn ($m) => [self::METODOS[$valor($m, 'metodo')] ?? (string) $valor($m, 'metodo'), (int) $valor($m, 'cantidad'), (float) $valor($m, 'monto_total')])->all(),
                ],
            ],
            'compras-y-gastos' => [
                [
                    'titulo' => 'Por categoría',
                    'columnas' => [$col('Tipo'), $col('Categoría'), $col('Registros', 'numero'), $col('Total', 'moneda')],
                    'filas' => collect($d['por_categoria'] ?? [])
                        ->sortBy(fn ($c) => [$valor($c, 'tipo'), $valor($c, 'categoria')])->values()
                        ->map(fn ($c) => [$valor($c, 'tipo') === 'compra' ? 'Compra' : 'Otro gasto', $valor($c, 'categoria') ?? 'Sin categoría', (int) $valor($c, 'cantidad'), (float) $valor($c, 'total')])->all(),
                ],
            ],
            'uso-de-mesas' => [
                [
                    'titulo' => 'Reservas por estado',
                    'columnas' => [$col('Estado'), $col('Reservas', 'numero')],
                    'filas' => collect($d['por_estado'] ?? [])->map(fn ($e) => [self::ESTADOS_RESERVA[$valor($e, 'estado')] ?? (string) $valor($e, 'estado'), (int) $valor($e, 'cantidad')])->all(),
                ],
                [
                    'titulo' => 'Ocupación por mesa',
                    'columnas' => [$col('Mesa'), $col('Visitas', 'numero'), $col('Pedidos', 'numero'), $col('Ventas', 'moneda'), $col('Minutos promedio por visita'), $col('Reservas completadas', 'numero')],
                    'filas' => collect($d['ocupacion_por_mesa'] ?? [])->map(fn ($m) => [
                        (string) $valor($m, 'nombre'), (int) $valor($m, 'visitas'), (int) $valor($m, 'pedidos_locales'), (float) $valor($m, 'ventas'),
                        $valor($m, 'minutos_promedio') === null ? '—' : (int) $valor($m, 'minutos_promedio'), (int) $valor($m, 'reservas_completadas'),
                    ])->all(),
                ],
            ],
            default => [],
        };
    }

    private function nombreProducto(array $p): string
    {
        return ($p['es_combo'] ? 'Combo: ' : '').$p['nombre'];
    }

    private function textoPeriodo(string $desde, string $hasta): string
    {
        $fecha = fn (string $f) => Carbon::parse($f)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');

        return $desde === $hasta ? "El {$fecha($desde)}" : "Del {$fecha($desde)} al {$fecha($hasta)}";
    }

    // ───────────── PDF (RF-M08-011) ─────────────

    public function pdf(array $doc): string
    {
        $pdf = Pdf::loadView('reportes.pdf', ['doc' => $doc, 'negocio' => config('fragua_real.negocio')])
            ->setOption(RegistroImpresiones::OPCIONES_PDF)
            ->setPaper('letter', 'portrait');

        return PiePagina::numerar($pdf)->output();
    }

    // ───────────── Excel .xlsx (RF-M08-012) ─────────────

    private const FORMATO_PESOS = '"$"#,##0;[Red]-"$"#,##0';
    private const FORMATO_PORCENTAJE = '0.0"%"';

    public function excel(array $doc): string
    {
        $libro = new Spreadsheet();
        $libro->getProperties()->setCreator('Fragua Real Burger')->setTitle($doc['titulo'])->setSubject($doc['periodo']);

        $hoja = $libro->getActiveSheet()->setTitle('Resumen');
        $hoja->setCellValue('A1', $doc['titulo'])->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $hoja->setCellValue('A2', $doc['periodo']);
        $hoja->setCellValue('A3', "Generado el {$doc['generado']} por {$doc['generado_por']}");
        $fila = 4;
        if ($doc['parcial']) {
            $hoja->setCellValue("A{$fila}", 'Periodo en curso: las cifras son parciales.')->getStyle("A{$fila}")->getFont()->setBold(true)->getColor()->setRGB('A3261C');
            $fila++;
        }
        $fila++;

        $hoja->fromArray(['Métrica', 'Valor'], null, "A{$fila}");
        $this->estiloEncabezado($hoja, "A{$fila}:B{$fila}");
        foreach ($doc['resumen'] as [$texto, $valor, $tipo]) {
            $fila++;
            $hoja->setCellValue("A{$fila}", $texto);
            $hoja->setCellValue("B{$fila}", $valor);
            $this->formatoNumero($hoja, "B{$fila}", $tipo);
        }

        if ($doc['comparacion']) {
            $fila += 2;
            $hoja->setCellValue("A{$fila}", 'Comparación con el periodo anterior: '.mb_strtolower($doc['comparacion']['periodo']))->getStyle("A{$fila}")->getFont()->setBold(true);
            $fila++;
            $hoja->fromArray(['Métrica', 'Este periodo', 'Periodo anterior', 'Variación', 'Variación %'], null, "A{$fila}");
            $this->estiloEncabezado($hoja, "A{$fila}:E{$fila}");
            foreach ($doc['comparacion']['filas'] as [$texto, $actual, $anterior, $absoluta, $porcentaje, $tipo]) {
                $fila++;
                $hoja->fromArray([$texto, $actual, $anterior, $absoluta], null, "A{$fila}", true);
                foreach (['B', 'C', 'D'] as $c) {
                    $this->formatoNumero($hoja, "{$c}{$fila}", $tipo);
                }
                if ($porcentaje === null) {
                    $hoja->setCellValue("E{$fila}", 'sin dato anterior');
                } else {
                    $hoja->setCellValue("E{$fila}", $porcentaje);
                    $hoja->getStyle("E{$fila}")->getNumberFormat()->setFormatCode(self::FORMATO_PORCENTAJE);
                }
            }
        }

        if ($doc['nota']) {
            $fila += 2;
            $hoja->setCellValue("A{$fila}", $doc['nota'])->getStyle("A{$fila}")->getFont()->setItalic(true);
        }
        // La columna A lleva títulos largos que se desbordan a la derecha: ancho fijo, no automático.
        $hoja->getColumnDimension('A')->setWidth(34);
        foreach (['B', 'C', 'D', 'E'] as $c) {
            $hoja->getColumnDimension($c)->setWidth(18);
        }

        $nombresUsados = ['Resumen'];
        foreach ($doc['tablas'] as $tabla) {
            $nombre = $this->nombreHoja($tabla['titulo'], $nombresUsados);
            $nombresUsados[] = $nombre;
            $hojaTabla = $libro->createSheet()->setTitle($nombre);
            $columnas = count($tabla['columnas']);
            $ultima = chr(ord('A') + $columnas - 1);

            $hojaTabla->fromArray(array_column($tabla['columnas'], 'texto'), null, 'A1');
            $this->estiloEncabezado($hojaTabla, "A1:{$ultima}1");
            $hojaTabla->fromArray($tabla['filas'] ?: [['Sin datos en el periodo.']], null, 'A2', true);
            $hasta = max(2, count($tabla['filas']) + 1);
            foreach ($tabla['columnas'] as $i => $columna) {
                $letra = chr(ord('A') + $i);
                $this->formatoNumero($hojaTabla, "{$letra}2:{$letra}{$hasta}", $columna['tipo']);
                $hojaTabla->getColumnDimension($letra)->setAutoSize(true);
            }
            $hojaTabla->freezePane('A2');
            if ($tabla['filas']) {
                $hojaTabla->setAutoFilter("A1:{$ultima}{$hasta}");
            }
        }
        $libro->setActiveSheetIndex(0);

        $temporal = tempnam(sys_get_temp_dir(), 'reporte');
        (new Xlsx($libro))->save($temporal);
        $contenido = file_get_contents($temporal);
        @unlink($temporal);
        $libro->disconnectWorksheets();

        return $contenido;
    }

    private function estiloEncabezado(Worksheet $hoja, string $rango): void
    {
        $estilo = $hoja->getStyle($rango);
        $estilo->getFont()->setBold(true);
        $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1ECE4');
        $estilo->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function formatoNumero(Worksheet $hoja, string $rango, string $tipo): void
    {
        if ($tipo === 'moneda') {
            $hoja->getStyle($rango)->getNumberFormat()->setFormatCode(self::FORMATO_PESOS);
        } elseif ($tipo === 'numero') {
            $hoja->getStyle($rango)->getNumberFormat()->setFormatCode('#,##0');
        }
    }

    // Excel: máximo 31 caracteres, sin : \ / ? * [ ] y sin repetir.
    private function nombreHoja(string $titulo, array $usados): string
    {
        $base = mb_substr(trim(preg_replace('/[:\\\\\/?*\[\]]/u', ' ', $titulo)), 0, 28);
        $nombre = $base;
        for ($n = 2; in_array($nombre, $usados, true); $n++) {
            $nombre = "{$base} {$n}";
        }

        return $nombre;
    }
}

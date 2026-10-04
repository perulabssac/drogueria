<?php

namespace App\Services;

use App\Models\Importacion;
use App\Models\Laboratorio;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Importa productos y su stock inicial desde un Excel (una fila por lote).
 * 1) plantilla(): Excel con las columnas, listas desplegables e instrucciones.
 * 2) analizar(): lee el archivo y valida cada fila, sin guardar nada (vista previa).
 * 3) importar(): si no hay errores, crea/actualiza productos e ingresa los lotes al kárdex.
 */
class ImportacionService
{
    /** Máximo de filas por archivo (para no colgar el servidor). */
    public const MAX_FILAS = 3000;

    /** clave => [encabezado, ejemplo 1, ejemplo 2, ancho]. El * marca las obligatorias. */
    public const COLUMNAS = [
        'codigo' => ['Código*', 'AMOX500', 'IBU400SUS', 12],
        'nombre' => ['Nombre*', 'AMOXICILINA', 'IBUPROFENO', 28],
        'concentracion' => ['Concentración', '500 MG', '100 MG/5 ML', 14],
        'presentacion' => ['Presentación', 'CJA X 100 CAP', 'FCO X 60 ML', 16],
        'principio_activo' => ['Principio activo', 'AMOXICILINA', 'IBUPROFENO', 20],
        'forma_farmaceutica' => ['Forma farmacéutica', 'CÁPSULA', 'SUSPENSIÓN', 16],
        'laboratorio' => ['Laboratorio', 'PORTUGAL', 'MEDIFARMA', 16],
        'categoria' => ['Categoría', 'ANTIBIÓTICOS', 'ANALGÉSICOS', 16],
        'registro_sanitario' => ['Registro sanitario', 'EN-01234', 'EE-05678', 14],
        'codigo_barras' => ['Código de barras', '7750000000017', '', 16],
        'unidad_venta' => ['Unidad de venta*', 'CJA', 'FCO', 10],
        'precio_venta' => ['Precio venta*', 25, 12.5, 10],
        'fraccionable' => ['¿Se vende suelto?', 'SI', 'NO', 10],
        'unidades_por_presentacion' => ['Unidades por presentación', 100, '', 12],
        'unidad_fraccion' => ['Unidad suelta', 'CAP', '', 10],
        'precio_fraccion' => ['Precio unidad suelta', 0.3, '', 11],
        'igv' => ['IGV', 'GRAVADO', 'EXONERADO', 12],
        'condicion_venta' => ['Condición de venta', 'CON RECETA', 'LIBRE', 16],
        'controlado' => ['¿Controlado?', 'NO', 'NO', 10],
        'cadena_frio' => ['¿Cadena de frío?', 'NO', 'NO', 10],
        'stock_minimo' => ['Stock mínimo', 5, 10, 9],
        'costo' => ['Costo sin IGV (por presentación)', 18.5, 8.2, 14],
        'lote' => ['N° lote', 'L2401', 'B5566', 10],
        'vencimiento' => ['Vencimiento (dd/mm/aaaa)', '31/12/2027', '30/06/2027', 14],
        'cantidad' => ['Cantidad (presentaciones)', 10, 24, 12],
        'sueltas' => ['Unidades sueltas', 40, '', 10],
    ];

    private const IGV = ['GRAVADO' => '10', 'EXONERADO' => '20', 'INAFECTO' => '30'];

    private const CONDICIONES = ['LIBRE' => 'sin_receta', 'CON RECETA' => 'con_receta', 'RECETA RETENIDA' => 'receta_retenida'];

    public function __construct(private InventarioService $inventario) {}

    // ======================= 1. PLANTILLA =======================

    public function plantilla(): Spreadsheet
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Productos');
        $claves = array_keys(self::COLUMNAS);
        $ultima = Coordinate::stringFromColumnIndex(count($claves));

        foreach ($claves as $i => $clave) {
            [$titulo, , , $ancho] = self::COLUMNAS[$clave];
            $columna = Coordinate::stringFromColumnIndex($i + 1);
            $hoja->setCellValue("{$columna}1", $titulo);
            $hoja->getColumnDimension($columna)->setWidth($ancho + 4);
            // Texto para que no se pierdan ceros (códigos, lotes, código de barras)
            if (in_array($clave, ['codigo', 'codigo_barras', 'lote', 'registro_sanitario', 'vencimiento'], true)) {
                $hoja->getStyle("{$columna}2:{$columna}".(self::MAX_FILAS + 1))->getNumberFormat()->setFormatCode('@');
            }
        }

        $encabezado = $hoja->getStyle("A1:{$ultima}1");
        $encabezado->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $encabezado->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF047857');
        $encabezado->getAlignment()->setWrapText(true)->setVertical('center');
        $hoja->getRowDimension(1)->setRowHeight(32);
        $hoja->freezePane('C2');

        // Listas desplegables
        $this->lista($hoja, 'unidad_venta', array_keys(Producto::UNIDADES_VENTA));
        $this->lista($hoja, 'unidad_fraccion', array_keys(Producto::UNIDADES_FRACCION));
        $this->lista($hoja, 'igv', array_keys(self::IGV));
        $this->lista($hoja, 'condicion_venta', array_keys(self::CONDICIONES));
        foreach (['fraccionable', 'controlado', 'cadena_frio'] as $clave) {
            $this->lista($hoja, $clave, ['SI', 'NO']);
        }

        $this->hojaInstrucciones($libro, $claves);
        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    /** Guarda la plantilla en un archivo temporal y devuelve su ruta. */
    public function guardarPlantilla(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'plantilla').'.xlsx';
        (new Xlsx($this->plantilla()))->save($ruta);

        return $ruta;
    }

    private function lista($hoja, string $clave, array $opciones): void
    {
        $columna = Coordinate::stringFromColumnIndex(array_search($clave, array_keys(self::COLUMNAS), true) + 1);
        $validacion = (new DataValidation())->setType(DataValidation::TYPE_LIST);
        $validacion->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Valor no válido')
            ->setError('Elige un valor de la lista.')
            ->setFormula1('"'.implode(',', $opciones).'"');
        $hoja->setDataValidation("{$columna}2:{$columna}".(self::MAX_FILAS + 1), $validacion);
    }

    private function hojaInstrucciones(Spreadsheet $libro, array $claves): void
    {
        $hoja = $libro->createSheet()->setTitle('Instrucciones');
        $lineas = [
            ['CÓMO LLENAR LA PLANTILLA'],
            ['• Una fila por cada LOTE. Si un producto tiene 3 lotes, repite el código en 3 filas (los datos del producto se toman de la primera).'],
            ['• Si solo quieres registrar el producto (sin stock), deja vacías las columnas de lote, vencimiento y cantidad.'],
            ['• Cantidad: en presentaciones enteras (cajas, frascos...). Si es fraccionable, las tabletas sueltas van en "Unidades sueltas".'],
            ['• Costo: por presentación y SIN IGV. Se usa para valorizar el inventario y calcular la utilidad.'],
            ['• Vencimiento: en formato dd/mm/aaaa (ej. 31/12/2027). No se importan lotes vencidos.'],
            ['• Si el código ya existe en el sistema, solo se agrega el stock (y se actualizan los datos si marcas esa opción al importar).'],
            ['• Valores permitidos — Unidad de venta: '.implode(', ', array_keys(Producto::UNIDADES_VENTA)).
                ' · Unidad suelta: '.implode(', ', array_keys(Producto::UNIDADES_FRACCION)).
                ' · IGV: '.implode(', ', array_keys(self::IGV)).
                ' · Condición: '.implode(', ', array_keys(self::CONDICIONES)).' · Sí/No: SI, NO'],
            [''],
            ['EJEMPLOS (no los copies a la hoja Productos tal cual: son solo de referencia)'],
        ];
        foreach ($lineas as $i => $linea) {
            $hoja->setCellValue('A'.($i + 1), $linea[0]);
        }
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $hoja->getStyle('A10')->getFont()->setBold(true);

        // Encabezados y 2 filas de ejemplo
        $fila = 11;
        foreach ($claves as $i => $clave) {
            $columna = Coordinate::stringFromColumnIndex($i + 1);
            $hoja->setCellValue("{$columna}{$fila}", self::COLUMNAS[$clave][0]);
            $hoja->setCellValue($columna.($fila + 1), self::COLUMNAS[$clave][1]);
            $hoja->setCellValue($columna.($fila + 2), self::COLUMNAS[$clave][2]);
            $hoja->getColumnDimension($columna)->setWidth(self::COLUMNAS[$clave][3] + 4);
        }
        $ultima = Coordinate::stringFromColumnIndex(count($claves));
        $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->getFont()->setBold(true);
        $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
    }

    // ======================= 2. ANÁLISIS (VISTA PREVIA) =======================

    /**
     * Lee y valida el archivo sin guardar nada.
     *
     * @return array{filas: array, resumen: array}
     */
    public function analizar(string $ruta, int $sucursalId, bool $actualizarExistentes = false): array
    {
        $filas = $this->leer($ruta);

        $existentes = Producto::query()
            ->whereIn('codigo', collect($filas)->pluck('codigo')->filter()->unique())
            ->get()
            ->keyBy(fn (Producto $p) => mb_strtoupper($p->codigo));

        $productos = [];   // código => datos del producto (de su primera fila)
        $lotesVistos = []; // "código|lote" => fila
        $resultado = [];

        foreach ($filas as $f) {
            $errores = [];
            $avisos = [];
            $codigo = $f['codigo'];
            $existente = $existentes[$codigo] ?? null;

            // --- Datos del producto (solo de la primera fila de cada código) ---
            if ($codigo === '') {
                $errores[] = 'Falta el código.';
            } elseif (! isset($productos[$codigo])) {
                [$datos, $erroresProducto] = $this->datosProducto($f, $existente, $actualizarExistentes);
                // Un producto que ya existe y no se va a actualizar solo recibe stock: sus datos del Excel no importan
                if (! $existente || $actualizarExistentes) {
                    $errores = [...$errores, ...$erroresProducto];
                }
                $productos[$codigo] = $datos;
            }
            $producto = $productos[$codigo] ?? null;

            // Unidades por presentación con las que se calcula el stock
            if ($existente && ! $actualizarExistentes) {
                $factor = $existente->factor();
                $unidades = ['unidad_venta' => $existente->unidad_venta, 'unidad_fraccion' => $existente->unidad_fraccion];
            } else {
                $factor = $producto && $producto['fraccionable'] ? max(1, (int) $producto['unidades_por_presentacion']) : 1;
                $unidades = $producto;
            }

            // --- Lote ---
            $lote = null;
            $tieneLote = $f['lote'] !== '' || $f['vencimiento'] !== '' || $f['cantidad'] !== '' || $f['sueltas'] !== '';
            if ($tieneLote) {
                [$lote, $erroresLote, $avisosLote] = $this->datosLote($f, $factor, $existente, $sucursalId);
                $errores = [...$errores, ...$erroresLote];
                $avisos = [...$avisos, ...$avisosLote];

                if ($lote) {
                    $clave = $codigo.'|'.$lote['numero_lote'];
                    if (isset($lotesVistos[$clave])) {
                        $errores[] = "El lote {$lote['numero_lote']} ya está en la fila {$lotesVistos[$clave]}.";
                    }
                    $lotesVistos[$clave] = $f['fila'];
                }
            } elseif ($existente) {
                $avisos[] = 'Producto ya registrado y sin lote: esta fila no agrega nada (salvo que actualices datos).';
            }

            $resultado[] = [
                'fila' => $f['fila'],
                'codigo' => $codigo,
                'nombre' => $existente && ! $actualizarExistentes
                    ? trim($existente->nombre.' '.$existente->concentracion)
                    : trim(($producto['nombre'] ?? $f['nombre']).' '.($producto['concentracion'] ?? '')),
                'estado' => $existente ? 'existente' : 'nuevo',
                'lote' => $lote['numero_lote'] ?? null,
                'vencimiento' => $lote['fecha_vencimiento'] ?? null,
                'cantidad' => $lote ? $this->textoCantidad($lote['cantidad'], $factor, $unidades) : null,
                'unidades' => $lote['cantidad'] ?? 0,
                'valor' => $lote ? round($lote['cantidad'] / $factor * $lote['costo_unitario'], 2) : 0,
                'errores' => $errores,
                'avisos' => $avisos,
                // datos internos para importar (no se muestran)
                '_producto' => $producto,
                '_lote' => $lote,
            ];
        }

        $codigosNuevos = collect($resultado)->where('estado', 'nuevo')->pluck('codigo')->unique();

        return [
            'filas' => $resultado,
            'resumen' => [
                'filas' => count($resultado),
                'productos_nuevos' => $codigosNuevos->count(),
                'productos_existentes' => collect($resultado)->where('estado', 'existente')->pluck('codigo')->unique()->count(),
                'lotes' => collect($resultado)->whereNotNull('lote')->count(),
                'valor' => round(collect($resultado)->sum('valor'), 2),
                'con_error' => collect($resultado)->filter(fn ($r) => $r['errores'])->count(),
                'con_aviso' => collect($resultado)->filter(fn ($r) => $r['avisos'])->count(),
            ],
        ];
    }

    /** Lee la primera hoja: fila 1 = encabezados; devuelve las filas con datos. */
    private function leer(string $ruta): array
    {
        try {
            $libro = IOFactory::load($ruta);
        } catch (Throwable) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el archivo. Usa la plantilla en formato .xlsx.']);
        }

        $hoja = $libro->getSheetByName('Productos') ?? $libro->getSheet(0);
        $datos = $hoja->toArray(null, true, false, false);
        $encabezados = array_map(fn ($v) => trim((string) $v), $datos[0] ?? []);
        $esperados = array_map(fn ($c) => $c[0], array_values(self::COLUMNAS));

        if (array_slice($encabezados, 0, count($esperados)) !== $esperados) {
            throw ValidationException::withMessages(['archivo' => 'Las columnas no coinciden con la plantilla. Descarga la plantilla y copia tus datos en ella.']);
        }

        $claves = array_keys(self::COLUMNAS);
        $filas = [];
        foreach (array_slice($datos, 1) as $i => $valores) {
            $fila = [];
            foreach ($claves as $j => $clave) {
                $valor = $valores[$j] ?? null;
                $fila[$clave] = is_string($valor) ? trim($valor) : ($valor ?? '');
            }
            // Fila totalmente vacía: se ignora
            if (collect($fila)->every(fn ($v) => $v === '' || $v === null)) {
                continue;
            }
            $fila['codigo'] = mb_strtoupper((string) $fila['codigo']);
            $fila['fila'] = $i + 2;
            $filas[] = $fila;
        }

        if (! $filas) {
            throw ValidationException::withMessages(['archivo' => 'El archivo no tiene filas con datos.']);
        }
        if (count($filas) > self::MAX_FILAS) {
            throw ValidationException::withMessages(['archivo' => 'Máximo '.self::MAX_FILAS.' filas por archivo. Divídelo en varios.']);
        }

        return $filas;
    }

    /** @return array{0: array, 1: array<int, string>} datos del producto y errores */
    private function datosProducto(array $f, ?Producto $existente, bool $actualizar): array
    {
        $errores = [];
        $texto = fn ($v, $max = 255) => mb_substr(mb_strtoupper(trim((string) $v)), 0, $max) ?: null;
        $siNo = fn ($v) => in_array(mb_strtoupper(trim((string) $v)), ['SI', 'SÍ', 'S', '1', 'X'], true);

        $unidad = mb_strtoupper(trim((string) $f['unidad_venta']));
        $fraccionable = $siNo($f['fraccionable']);
        $igv = mb_strtoupper(trim((string) $f['igv'])) ?: 'GRAVADO';
        $condicion = mb_strtoupper(trim((string) $f['condicion_venta'])) ?: 'LIBRE';

        $datos = [
            'codigo' => mb_substr($f['codigo'], 0, 30),
            'nombre' => $texto($f['nombre']),
            'concentracion' => $texto($f['concentracion'], 100),
            'presentacion' => $texto($f['presentacion'], 100),
            'principio_activo' => $texto($f['principio_activo']),
            'forma_farmaceutica' => $texto($f['forma_farmaceutica'], 100),
            'laboratorio' => $texto($f['laboratorio']),
            'categoria' => $texto($f['categoria'], 60),
            'registro_sanitario' => $texto($f['registro_sanitario'], 30),
            'codigo_barras' => trim((string) $f['codigo_barras']) ?: null,
            'unidad_venta' => $unidad,
            'unidad_sunat' => Producto::unidadSunat($unidad),
            'precio_venta' => $this->numero($f['precio_venta']),
            'fraccionable' => $fraccionable,
            'unidades_por_presentacion' => $fraccionable ? (int) $this->numero($f['unidades_por_presentacion']) : 1,
            'unidad_fraccion' => $fraccionable ? mb_strtoupper(trim((string) $f['unidad_fraccion'])) : null,
            'precio_fraccion' => $fraccionable ? $this->numero($f['precio_fraccion']) : null,
            'tipo_afectacion_igv' => self::IGV[$igv] ?? null,
            'condicion_venta' => self::CONDICIONES[$condicion] ?? null,
            'controlado' => $siNo($f['controlado']),
            'cadena_frio' => $siNo($f['cadena_frio']),
            'stock_minimo' => (int) ($this->numero($f['stock_minimo']) ?? 0),
            'costo' => $this->numero($f['costo']) ?? 0,
        ];

        if (! $datos['nombre']) {
            $errores[] = 'Falta el nombre.';
        }
        if (! isset(Producto::UNIDADES_VENTA[$unidad])) {
            $errores[] = 'Unidad de venta no válida ('.($unidad ?: 'vacía').').';
        }
        if (! $datos['precio_venta'] || $datos['precio_venta'] <= 0) {
            $errores[] = 'Falta el precio de venta.';
        }
        if (! $datos['tipo_afectacion_igv']) {
            $errores[] = "IGV no válido ({$igv}): usa GRAVADO, EXONERADO o INAFECTO.";
        }
        if (! $datos['condicion_venta']) {
            $errores[] = "Condición de venta no válida ({$condicion}).";
        }
        if ($fraccionable) {
            if ($datos['unidades_por_presentacion'] < 2) {
                $errores[] = 'Si se vende suelto, indica cuántas unidades trae la presentación (2 o más).';
            }
            if (! isset(Producto::UNIDADES_FRACCION[$datos['unidad_fraccion']])) {
                $errores[] = 'Unidad suelta no válida.';
            }
            if (! $datos['precio_fraccion'] || $datos['precio_fraccion'] <= 0) {
                $errores[] = 'Falta el precio de la unidad suelta.';
            }
        }
        if ($actualizar && $existente && $existente->lotes()->exists() && $existente->factor() !== ($fraccionable ? $datos['unidades_por_presentacion'] : 1)) {
            $errores[] = "El producto ya existe con {$existente->factor()} unidad(es) por presentación y tiene lotes: no se puede cambiar.";
        }

        return [$datos, $errores];
    }

    /** @return array{0: ?array, 1: array<int, string>, 2: array<int, string>} lote, errores y avisos */
    private function datosLote(array $f, int $factor, ?Producto $existente, int $sucursalId): array
    {
        $errores = [];
        $avisos = [];
        $numero = mb_strtoupper(trim((string) $f['lote']));
        $vence = $this->fecha($f['vencimiento']);
        $cantidad = $this->numero($f['cantidad']) ?? 0;
        $sueltas = $this->numero($f['sueltas']) ?? 0;

        if ($numero === '') {
            $errores[] = 'Falta el número de lote.';
        }
        if (! $vence) {
            $errores[] = 'Vencimiento no válido (usa dd/mm/aaaa).';
        } elseif ($vence->lte(today())) {
            $errores[] = 'El lote está vencido: no se importa (regístralo como baja de vencidos).';
        } elseif ($vence->lte(today()->addDays(90))) {
            $avisos[] = 'Vence en menos de 90 días.';
        }
        if ($cantidad < 0 || $sueltas < 0 || ($cantidad + $sueltas) <= 0) {
            $errores[] = 'La cantidad debe ser mayor a cero.';
        }
        if ($cantidad != floor($cantidad)) {
            $errores[] = 'La cantidad de presentaciones debe ser entera (las sueltas van en su columna).';
        }
        if ($sueltas > 0 && $factor === 1) {
            $errores[] = 'Hay unidades sueltas pero el producto no se vende suelto.';
        } elseif ($sueltas >= $factor && $factor > 1) {
            $errores[] = "Las unidades sueltas deben ser menos de {$factor} (si no, súmalas a la cantidad).";
        }

        // Si el lote ya existe en el sistema, debe tener el mismo vencimiento
        if ($existente && $numero !== '' && $vence) {
            $actual = Lote::query()->where('producto_id', $existente->id)->where('sucursal_id', $sucursalId)->where('numero_lote', $numero)->first();
            if ($actual && ! $actual->fecha_vencimiento->isSameDay($vence)) {
                $errores[] = "El lote {$numero} ya existe con vencimiento {$actual->fecha_vencimiento->format('d/m/Y')}.";
            } elseif ($actual) {
                $avisos[] = 'El lote ya existe: se sumará la cantidad.';
            }
        }

        if ($errores) {
            return [null, $errores, $avisos];
        }

        return [[
            'numero_lote' => mb_substr($numero, 0, 50),
            'fecha_vencimiento' => $vence->toDateString(),
            'cantidad' => $cantidad * $factor + $sueltas, // en unidades mínimas
            'costo_unitario' => $this->numero($f['costo']) ?? 0,
        ], [], $avisos];
    }

    private function textoCantidad(float $unidades, int $factor, ?array $producto): string
    {
        $enteras = intdiv((int) $unidades, $factor);
        $resto = $unidades - $enteras * $factor;
        $texto = $enteras.' '.($producto['unidad_venta'] ?? '');

        return $resto > 0 ? "{$texto} + {$resto} ".($producto['unidad_fraccion'] ?? '') : $texto;
    }

    /** "1,250.50", "1250,50", 1250.5 → 1250.5; vacío → null */
    private function numero(mixed $valor): ?float
    {
        if ($valor === '' || $valor === null) {
            return null;
        }
        if (is_numeric($valor)) {
            return (float) $valor;
        }
        $limpio = str_replace([' ', 'S/', 's/'], '', (string) $valor);
        // Si usa coma como decimal (1250,50) y no tiene punto
        if (str_contains($limpio, ',') && ! str_contains($limpio, '.')) {
            $limpio = str_replace(',', '.', $limpio);
        }
        $limpio = str_replace(',', '', $limpio);

        return is_numeric($limpio) ? (float) $limpio : null;
    }

    /** Fecha de Excel (número de serie) o texto dd/mm/aaaa, dd-mm-aaaa o aaaa-mm-dd. */
    private function fecha(mixed $valor): ?Carbon
    {
        if ($valor === '' || $valor === null) {
            return null;
        }
        try {
            if (is_numeric($valor)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $valor))->startOfDay();
            }
            $texto = str_replace(['-', '.'], '/', trim((string) $valor));
            foreach (['d/m/Y', 'j/n/Y', 'Y/m/d', 'd/m/y'] as $formato) {
                try {
                    $fecha = Carbon::createFromFormat("!{$formato}", $texto);
                } catch (Throwable) {
                    continue;
                }
                // Que no haya "corregido" una fecha imposible (31/02 → 03/03)
                if ($fecha && $fecha->format($formato) === $texto) {
                    return $fecha;
                }
            }
        } catch (Throwable) {
            // fecha ilegible
        }

        return null;
    }

    // ======================= 3. IMPORTACIÓN =======================

    /** Importa todo en una sola transacción (o nada, si algo falla). */
    public function importar(string $ruta, string $nombreArchivo, User $usuario, bool $actualizarExistentes): Importacion
    {
        $sucursalId = $usuario->sucursal_id;
        $analisis = $this->analizar($ruta, $sucursalId, $actualizarExistentes);

        if ($analisis['resumen']['con_error'] > 0) {
            throw ValidationException::withMessages(['archivo' => 'El archivo tiene filas con errores. Corrígelas y vuelve a subirlo.']);
        }

        return DB::transaction(function () use ($analisis, $nombreArchivo, $usuario, $sucursalId, $actualizarExistentes) {
            $importacion = Importacion::create([
                'sucursal_id' => $sucursalId,
                'user_id' => $usuario->id,
                'archivo' => mb_substr($nombreArchivo, 0, 150),
            ]);

            $productos = [];   // código => Producto ya creado/actualizado en esta importación
            $nuevos = 0;
            $actualizados = 0;
            $lotes = 0;
            $valor = 0;

            foreach ($analisis['filas'] as $fila) {
                $codigo = $fila['codigo'];

                if (! isset($productos[$codigo])) {
                    $producto = Producto::query()->where('codigo', $codigo)->lockForUpdate()->first();
                    if (! $producto) {
                        $producto = Producto::create([...$this->conLaboratorio($fila['_producto']), 'activo' => true]);
                        $nuevos++;
                    } elseif ($actualizarExistentes) {
                        $producto->update($this->conLaboratorio($fila['_producto']));
                        $actualizados++;
                    }
                    $productos[$codigo] = $producto;
                }

                if ($lote = $fila['_lote']) {
                    $this->inventario->ingresarLote(
                        [...$lote, 'producto_id' => $productos[$codigo]->id, 'sucursal_id' => $sucursalId],
                        $usuario->id,
                        'inventario_inicial',
                        $importacion,
                    );
                    $lotes++;
                    $valor += $fila['valor'];
                }
            }

            $importacion->update([
                'productos_nuevos' => $nuevos,
                'productos_actualizados' => $actualizados,
                'lotes' => $lotes,
                'valor' => round($valor, 2),
            ]);

            return $importacion;
        });
    }

    /** Cambia el nombre del laboratorio por su id (lo crea si no existe). */
    private function conLaboratorio(array $datos): array
    {
        $datos['laboratorio_id'] = $datos['laboratorio']
            ? Laboratorio::firstOrCreate(['nombre' => $datos['laboratorio']])->id
            : null;
        unset($datos['laboratorio']);

        return $datos;
    }
}
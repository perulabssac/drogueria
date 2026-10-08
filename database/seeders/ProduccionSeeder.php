<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Serie;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Instalación limpia para un cliente nuevo (se ejecuta UNA sola vez en el servidor):
 *   php artisan migrate
 *   php artisan db:seed --class=ProduccionSeeder
 *
 * Crea solo lo mínimo: empresa, sucursal, series en 0, "Clientes varios",
 * el usuario de soporte de Perú Labs y el administrador del negocio.
 * Los datos se escriben en la terminal: ninguna contraseña queda guardada en el código.
 */
class ProduccionSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->exists() || Empresa::query()->exists()) {
            $this->command->error('La base de datos ya tiene datos: este seeder es solo para una instalación nueva.');

            return;
        }

        $this->command->info('=== Datos de la empresa (deben coincidir con la ficha RUC de SUNAT) ===');
        $ruc = $this->preguntar('RUC', '/^\d{11}$/', 'El RUC tiene 11 dígitos.');
        $razonSocial = $this->preguntar('Razón social');
        $nombreComercial = $this->preguntar('Nombre comercial', null, null, $razonSocial);
        $direccion = $this->preguntar('Dirección fiscal (sin distrito, ej. JR. MICAELA BASTIDAS 285)');
        $ubigeo = $this->preguntar('Ubigeo (6 dígitos, ej. 120114)', '/^\d{6}$/', 'El ubigeo tiene 6 dígitos.');
        $departamento = $this->preguntar('Departamento');
        $provincia = $this->preguntar('Provincia');
        $distrito = $this->preguntar('Distrito');

        Empresa::create([
            'ruc' => $ruc,
            'razon_social' => mb_strtoupper($razonSocial),
            'nombre_comercial' => mb_strtoupper($nombreComercial),
            'direccion' => mb_strtoupper($direccion),
            'ubigeo' => $ubigeo,
            'departamento' => mb_strtoupper($departamento),
            'provincia' => mb_strtoupper($provincia),
            'distrito' => mb_strtoupper($distrito),
            'entorno' => 'beta', // pasa a producción desde Configuración, con el certificado real
        ]);

        $sucursal = Sucursal::create([
            'nombre' => 'Principal',
            'direccion' => mb_strtoupper($direccion),
            'codigo_establecimiento' => '0000',
        ]);

        // Series en 0: el primer comprobante será el número 1
        $series = [['01', 'F001'], ['03', 'B001'], ['07', 'FC01'], ['07', 'BC01'], ['NV', 'NV01']];
        foreach ($series as [$tipo, $serie]) {
            Serie::firstOrCreate(['tipo_comprobante' => $tipo, 'serie' => $serie], ['sucursal_id' => $sucursal->id]);
        }

        Cliente::clientesVarios();

        $this->command->info('=== Usuario de soporte (Perú Labs, súper administrador) ===');
        $correoSoporte = $this->preguntar('Correo', '/^\S+@\S+\.\S+$/', 'Correo no válido.', 'sistemas@perulabs.net');
        User::create([
            'name' => 'Soporte Perú Labs',
            'email' => $correoSoporte,
            'password' => $this->clave(),
            'rol' => 'admin',
            'sucursal_id' => $sucursal->id,
        ])->forceFill(['es_superadmin' => true])->save();

        $this->command->info('=== Administrador del negocio (dueño o dueña) ===');
        $nombre = $this->preguntar('Nombre completo');
        $correo = $this->preguntar('Correo', '/^\S+@\S+\.\S+$/', 'Correo no válido.');
        User::create([
            'name' => $nombre,
            'email' => $correo,
            'password' => $this->clave(),
            'rol' => 'admin',
            'sucursal_id' => $sucursal->id,
        ]);

        $this->command->newLine();
        $this->command->info('Instalación lista. Siguiente: ingresar al sistema y completar Configuración (logo, certificado, clave SOL).');
    }

    /** Pregunta hasta recibir un valor válido. */
    private function preguntar(string $pregunta, ?string $formato = null, ?string $error = null, ?string $porDefecto = null): string
    {
        while (true) {
            $valor = trim((string) $this->command->ask($pregunta, $porDefecto));
            if ($valor !== '' && (! $formato || preg_match($formato, $valor))) {
                return $valor;
            }
            $this->command->error($error ?? 'Este dato es obligatorio.');
        }
    }

    /** Pide una contraseña segura dos veces, sin mostrarla en pantalla. */
    private function clave(): string
    {
        while (true) {
            $clave = (string) $this->command->secret('Contraseña (mínimo 10 caracteres, con letras y números; no se muestra)');
            if (mb_strlen($clave) < 10 || ! preg_match('/[A-Za-z]/', $clave) || ! preg_match('/\d/', $clave)) {
                $this->command->error('Muy débil: usa al menos 10 caracteres, con letras y números.');

                continue;
            }
            if ($clave !== (string) $this->command->secret('Repite la contraseña')) {
                $this->command->error('Las contraseñas no coinciden.');

                continue;
            }

            return $clave;
        }
    }
}
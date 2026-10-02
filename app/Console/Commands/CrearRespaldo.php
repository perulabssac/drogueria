<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Respaldo completo: base de datos + archivos privados (certificado digital, XML y CDR de SUNAT).
 * - Se guarda como ZIP (cifrado si hay RESPALDO_CLAVE) en RESPALDO_CARPETA.
 * - Borra los respaldos con más de RESPALDO_DIAS días.
 * - Si hay RESPALDO_CORREO, envía por correo una copia de la base de datos (cifrada).
 * Se ejecuta solo cada noche (ver routes/console.php) y también a mano.
 */
class CrearRespaldo extends Command
{
    protected $signature = 'respaldo:crear {--sin-correo : No enviar la copia por correo}';

    protected $description = 'Crea un respaldo de la base de datos y de los archivos de SUNAT';

    public function handle(): int
    {
        $carpeta = rtrim(config('respaldo.carpeta'), '\\/');
        $marca = now()->format('Y-m-d_His');
        $temporal = storage_path("app/tmp-respaldo-{$marca}");

        try {
            File::ensureDirectoryExists($carpeta);
            File::ensureDirectoryExists($temporal);

            // 1. Exportar la base de datos
            $this->info('Exportando la base de datos...');
            $sql = "{$temporal}/base-de-datos.sql";
            $this->exportarBaseDeDatos($sql);

            // 2. ZIP completo: base de datos + certificado + XML/CDR
            $this->info('Comprimiendo...');
            $completo = "{$carpeta}/respaldo-{$marca}.zip";
            $this->comprimir($completo, $sql, storage_path('app/private'));
            $this->info('Respaldo creado: '.$completo.' ('.$this->tamano($completo).')');

            // 3. Copia por correo (solo base de datos)
            if (config('respaldo.correo') && ! $this->option('sin-correo')) {
                $soloBaseDeDatos = "{$temporal}/base-de-datos-{$marca}.zip";
                $this->comprimir($soloBaseDeDatos, $sql, null);
                $this->enviarPorCorreo($soloBaseDeDatos, $marca);
            }

            // 4. Borrar respaldos antiguos
            $this->limpiarAntiguos($carpeta);

            Log::info("Respaldo creado: {$completo}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No se pudo crear el respaldo: '.$e->getMessage());
            Log::error('Respaldo fallido: '.$e->getMessage());
            $this->avisarFallo($e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($temporal);
        }
    }

    /** Usa mysqldump. La contraseña va en un archivo temporal (no queda visible en la lista de procesos). */
    private function exportarBaseDeDatos(string $destino): void
    {
        $db = config('database.connections.'.config('database.default'));

        if (! in_array($db['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('El respaldo automático funciona con MySQL/MariaDB.');
        }

        $credenciales = dirname($destino).'/mysql.cnf';
        File::put($credenciales, implode("\n", [
            '[client]',
            'user="'.$db['username'].'"',
            'password="'.$db['password'].'"',
            'host="'.$db['host'].'"',
            'port='.$db['port'],
            '',
        ]));

        try {
            $resultado = Process::timeout(900)->run([
                config('respaldo.mysqldump'),
                "--defaults-extra-file={$credenciales}", // debe ir primero
                '--single-transaction',                   // copia consistente sin bloquear las ventas
                '--routines',
                '--triggers',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                "--result-file={$destino}",
                $db['database'],
            ]);
        } finally {
            File::delete($credenciales);
        }

        if ($resultado->failed() || ! File::exists($destino) || File::size($destino) === 0) {
            throw new RuntimeException('mysqldump falló: '.trim($resultado->errorOutput() ?: $resultado->output()));
        }
    }

    /** ZIP con la base de datos y, si se indica, una carpeta de archivos. Se cifra con AES-256 si hay clave. */
    private function comprimir(string $rutaZip, string $sql, ?string $carpetaArchivos): void
    {
        $zip = new ZipArchive();
        if ($zip->open($rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("No se pudo crear {$rutaZip}");
        }

        $clave = config('respaldo.clave');
        $agregar = function (string $origen, string $nombre) use ($zip, $clave) {
            $zip->addFile($origen, $nombre);
            if ($clave) {
                $zip->setEncryptionName($nombre, ZipArchive::EM_AES_256, $clave);
            }
        };

        $agregar($sql, 'base-de-datos.sql');

        if ($carpetaArchivos && is_dir($carpetaArchivos)) {
            foreach (File::allFiles($carpetaArchivos) as $archivo) {
                $agregar($archivo->getPathname(), 'archivos/'.str_replace('\\', '/', $archivo->getRelativePathname()));
            }
        }

        if (! $zip->close()) {
            throw new RuntimeException("No se pudo cerrar {$rutaZip}");
        }
    }

    private function enviarPorCorreo(string $zip, string $marca): void
    {
        $destino = config('respaldo.correo');
        $maximo = config('respaldo.correo_max_mb') * 1024 * 1024;

        if (File::size($zip) > $maximo) {
            $this->warn('La base de datos pesa '.$this->tamano($zip).': es demasiado grande para enviarla por correo.');

            return;
        }

        try {
            Mail::raw(
                "Respaldo automático de la base de datos ({$marca}).\n\n"
                .'El archivo está '.(config('respaldo.clave') ? 'protegido con la clave de respaldo. Ábrelo con 7-Zip.' : 'SIN cifrar.')."\n"
                .'Tamaño: '.$this->tamano($zip).".\n\nNo respondas este correo.",
                fn ($m) => $m->to($destino)
                    ->subject(config('app.name')." · Respaldo {$marca}")
                    ->attach($zip, ['as' => basename($zip), 'mime' => 'application/zip'])
            );
            $this->info("Copia enviada a {$destino}.");
        } catch (Throwable $e) {
            // El respaldo local ya está hecho: un fallo de correo no lo anula
            $this->warn('No se pudo enviar el correo: '.$e->getMessage());
            Log::warning('Respaldo: no se pudo enviar el correo: '.$e->getMessage());
        }
    }

    private function limpiarAntiguos(string $carpeta): void
    {
        $limite = now()->subDays(config('respaldo.dias'))->getTimestamp();
        $borrados = 0;

        foreach (File::glob("{$carpeta}/respaldo-*.zip") as $archivo) {
            if (File::lastModified($archivo) < $limite) {
                File::delete($archivo);
                $borrados++;
            }
        }

        if ($borrados) {
            $this->info("Se borraron {$borrados} respaldo(s) antiguo(s).");
        }
    }

    /** Si el respaldo falla, se avisa por correo (si está configurado). */
    private function avisarFallo(string $motivo): void
    {
        if (! config('respaldo.correo')) {
            return;
        }

        try {
            Mail::raw(
                "El respaldo automático de esta noche FALLÓ.\n\nMotivo: {$motivo}\n\nRevisa el servidor lo antes posible.",
                fn ($m) => $m->to(config('respaldo.correo'))->subject(config('app.name').' · ⚠ Respaldo fallido')
            );
        } catch (Throwable) {
            // Sin correo no hay forma de avisar: queda en el log
        }
    }

    private function tamano(string $archivo): string
    {
        $bytes = File::size($archivo);

        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }
}
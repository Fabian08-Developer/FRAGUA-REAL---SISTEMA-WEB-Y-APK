<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4, tarea 1 (12/10/2026) — base de RF-M05-016/024 y de todos los avisos de la Fase 4: la
 * cola de trabajos y el programador de tareas.
 *
 * El .env de ejemplo usa QUEUE_CONNECTION=database, CACHE_STORE=database y
 * SESSION_DRIVER=database, pero el proyecto nunca creó esas tablas (las pruebas usan
 * "sync"/"array" y no lo notaban). Sin ellas, encolar un aviso, el límite de intentos de inicio
 * de sesión (que vive en la caché) o el bloqueo del programador fallan en el servidor.
 *
 * Son las tablas estándar de Laravel (jobs, job_batches, failed_jobs, cache, cache_locks y
 * sessions). Cada una se crea solo si no existe, por si alguna ya se creó a mano con
 * `php artisan make:queue-table` o similar. Aditiva: no toca datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration')->index();
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration')->index();
            });
        }

        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['sessions', 'cache_locks', 'cache', 'failed_jobs', 'job_batches', 'jobs'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};

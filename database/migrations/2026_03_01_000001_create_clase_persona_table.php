<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clase_persona', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->onDelete('cascade');
            $table->foreignId('clase_asistencia_id')->constrained('clases_asistencia')->onDelete('cascade');
            $table->boolean('es_maestro')->default(false);
            $table->timestamps();

            $table->unique(['persona_id', 'clase_asistencia_id']);
        });

        // Migrate existing data from personas columns to pivot table
        $personas = DB::table('personas')
            ->whereNotNull('clase_asistencia_id')
            ->get(['id', 'clase_asistencia_id', 'es_maestro']);

        foreach ($personas as $persona) {
            DB::table('clase_persona')->insert([
                'persona_id' => $persona->id,
                'clase_asistencia_id' => $persona->clase_asistencia_id,
                'es_maestro' => $persona->es_maestro ?? false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Drop old columns from personas
        Schema::table('personas', function (Blueprint $table) {
            $table->dropForeign(['clase_asistencia_id']);
            $table->dropColumn(['clase_asistencia_id', 'es_maestro']);
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->foreignId('clase_asistencia_id')->nullable()->constrained('clases_asistencia')->nullOnDelete();
            $table->boolean('es_maestro')->default(false);
        });

        // Migrate data back (only first class per persona)
        $pivots = DB::table('clase_persona')->get();
        $seen = [];
        foreach ($pivots as $pivot) {
            if (!isset($seen[$pivot->persona_id])) {
                DB::table('personas')
                    ->where('id', $pivot->persona_id)
                    ->update([
                        'clase_asistencia_id' => $pivot->clase_asistencia_id,
                        'es_maestro' => $pivot->es_maestro,
                    ]);
                $seen[$pivot->persona_id] = true;
            }
        }

        Schema::dropIfExists('clase_persona');
    }
};

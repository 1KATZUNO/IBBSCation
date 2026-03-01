<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencia_clase_detalle', function (Blueprint $table) {
            $table->json('estudiantes_presentes_ids')->nullable()->after('maestros_ids');
        });
    }

    public function down(): void
    {
        Schema::table('asistencia_clase_detalle', function (Blueprint $table) {
            $table->dropColumn('estudiantes_presentes_ids');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_especiales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asistencia_id')->constrained('asistencia')->onDelete('cascade');
            $table->enum('tipo', ['visita', 'salvo', 'bautismo']);
            $table->string('nombre');
            $table->enum('genero', ['M', 'F']);
            $table->unsignedTinyInteger('edad')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_especiales');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * En el recuento del culto la firma del pastor se reemplaza por la de
     * quien recibe el efectivo y lo deposita en el banco. Sirve como
     * comprobante de recibido: quien firma confirma que recibio el dinero
     * detallado en el recuento y que realizara el deposito bancario.
     *
     * Se renombran las columnas (no se crean nuevas) para conservar las
     * firmas ya capturadas y no dejar datos huerfanos.
     */
    public function up(): void
    {
        Schema::table('cultos', function (Blueprint $table) {
            if (Schema::hasColumn('cultos', 'firma_pastor') && ! Schema::hasColumn('cultos', 'firma_depositante')) {
                $table->renameColumn('firma_pastor', 'firma_depositante');
            }
        });

        Schema::table('cultos', function (Blueprint $table) {
            if (Schema::hasColumn('cultos', 'firma_pastor_imagen') && ! Schema::hasColumn('cultos', 'firma_depositante_imagen')) {
                $table->renameColumn('firma_pastor_imagen', 'firma_depositante_imagen');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cultos', function (Blueprint $table) {
            if (Schema::hasColumn('cultos', 'firma_depositante') && ! Schema::hasColumn('cultos', 'firma_pastor')) {
                $table->renameColumn('firma_depositante', 'firma_pastor');
            }
        });

        Schema::table('cultos', function (Blueprint $table) {
            if (Schema::hasColumn('cultos', 'firma_depositante_imagen') && ! Schema::hasColumn('cultos', 'firma_pastor_imagen')) {
                $table->renameColumn('firma_depositante_imagen', 'firma_pastor_imagen');
            }
        });
    }
};

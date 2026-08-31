<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Hasta ahora no habia forma de saber desde cuando se le debe cobrar una
     * promesa a la persona. El recalculo de compromisos iba hacia atras hasta
     * el primer aporte de la persona, asi que a quien registro su promesa en
     * marzo se le exigia tambien diciembre, enero y febrero: 249 filas con
     * 856.700 colones de deuda inexistente.
     *
     * `created_at` no servia como sustituto porque al editar una persona las
     * promesas se borraban y se recreaban, reseteando la fecha.
     *
     * Se siembra con el mes de created_at, que es la mejor aproximacion
     * disponible para los datos ya existentes.
     */
    public function up(): void
    {
        Schema::table('promesas', function (Blueprint $table) {
            $table->date('vigente_desde')->nullable()->after('frecuencia');
        });

        // Primer dia del mes en que se registro la promesa.
        DB::statement("
            UPDATE promesas
            SET vigente_desde = DATE_FORMAT(COALESCE(created_at, NOW()), '%Y-%m-01')
            WHERE vigente_desde IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promesas', function (Blueprint $table) {
            $table->dropColumn('vigente_desde');
        });
    }
};

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class MiPerfilController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $persona = $user->persona;

        if (!$persona) {
            return redirect()->route('dashboard')
                ->with('error', 'No se encontró información de persona asociada.');
        }

        $persona->load(['sobres.detalles', 'promesas', 'compromisos']);

        $servicioPromesas = app(\App\Services\CalculoPromesasService::class);
        $año = (int) Carbon::now()->year;
        $mes = (int) Carbon::now()->month;

        // Los compromisos del miembro se derivan de sus promesas y sobres. Se
        // resincronizan al entrar para que no vea meses viejos con datos
        // desactualizados (antes solo se recalculaba desde la vista de admin).
        $servicioPromesas->sincronizarHistorial($persona);

        // Calcular cumplimiento de promesas del mes actual.
        // Lo esperado va ajustado por frecuencia: antes se comparaba contra
        // promesa->monto crudo, asi que una promesa semanal aparecia cumplida
        // con el aporte de una sola semana.
        $promesasConEstatus = $persona->promesas->map(function ($promesa) use ($persona, $servicioPromesas, $año, $mes) {
            $montoPagado = $servicioPromesas->montoDado($persona->id, $promesa->categoria, $año, $mes);
            $montoEsperado = $promesa->vigenteEn($año, $mes)
                ? $servicioPromesas->montoPrometidoMes($promesa, $año, $mes)
                : 0.0;

            return [
                'promesa' => $promesa,
                'esperado' => $montoEsperado,
                'pagado' => $montoPagado,
                'faltante' => max(0, $montoEsperado - $montoPagado),
                'cumplido' => $montoPagado >= $montoEsperado,
                'porcentaje' => $montoEsperado > 0 ? min(100, ($montoPagado / $montoEsperado) * 100) : 100,
            ];
        });

        // Aportes en categorias donde no hay promesa registrada: sin esto el
        // miembro daba plata y no la veia en ningun lado de su perfil.
        $aportesSinPromesa = $servicioPromesas->aportesSinPromesa($persona, $año);

        // Obtener compromisos con saldo negativo (deudas)
        $compromisos = $persona->compromisos()
            ->where('saldo_actual', '<', 0)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($compromiso) {
                $compromiso->deuda = abs($compromiso->saldo_actual); // Convertir a positivo para mostrar
                $compromiso->descripcion = ucfirst($compromiso->categoria) . ' ' . 
                    now()->locale('es')->parse($compromiso->año . '-' . $compromiso->mes . '-01')->isoFormat('MMMM YYYY');
                return $compromiso;
            });

        return view('mi-perfil.index', compact('persona', 'promesasConEstatus', 'compromisos', 'aportesSinPromesa'));
    }
}

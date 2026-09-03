@extends('layouts.admin')

@section('title', 'IBBSC - Compromisos - ' . $persona->nombre)
@section('page-title', 'Estado de Compromisos')

@section('content')
@php
    $meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio',
              'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
@endphp
<div class="space-y-6">
    <!-- Header con información de la persona -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex justify-between items-start">
            <div>
                <h2 class="text-2xl font-bold text-gray-900">{{ $persona->nombre }}</h2>
                <p class="text-gray-600 mt-1">
                    @if($persona->telefono) {{ $persona->telefono }} @endif
                    @if($persona->correo) • {{ $persona->correo }} @endif
                </p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('compromisos.pdf', ['persona' => $persona, 'año' => $año, 'mes' => $mes]) }}"
                   class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Descargar reporte a {{ $meses[$mes - 1] }}
                </a>
                <a href="{{ route('personas.index') }}" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    ← Volver
                </a>
            </div>
        </div>
    </div>

    <!-- Filtro de mes/año -->
    <div class="bg-white rounded-lg shadow p-6">
        <form method="GET" class="flex items-end gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Año</label>
                <select name="año" class="rounded-md border-gray-300" onchange="this.form.submit()">
                    @for($y = date('Y'); $y >= date('Y') - 2; $y--)
                        <option value="{{ $y }}" {{ $año == $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Mes</label>
                <select name="mes" class="rounded-md border-gray-300" onchange="this.form.submit()">
                    @foreach($meses as $m => $nombreMes)
                        <option value="{{ $m + 1 }}" {{ $mes == ($m + 1) ? 'selected' : '' }}>{{ $nombreMes }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    {{-- Estado general: se decide con el acumulado de todos los meses, no con
         el mes que se este viendo abajo. Quien no dio en un mes pero repuso en
         otro esta al dia, y mirando mes por mes parecia lo contrario. --}}
    <div class="rounded-lg shadow p-6 {{ $acumulado['al_dia'] ? 'bg-green-50 border border-green-300' : 'bg-red-50 border border-red-300' }}">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-sm text-gray-600">Estado de su promesa</p>
                <p class="text-3xl font-bold {{ $acumulado['al_dia'] ? 'text-green-700' : 'text-red-700' }}">
                    {{ $acumulado['al_dia'] ? 'Al día' : 'Atrasado' }}
                </p>
                <p class="text-sm text-gray-700 mt-1">
                    @if($acumulado['prometido'] <= 0)
                        Todavía no hay meses que exigirle.
                    @elseif($acumulado['al_dia'])
                        Ha dado ₡{{ number_format($acumulado['dado'], 2) }} de los
                        ₡{{ number_format($acumulado['prometido'], 2) }} que le corresponden
                        @if($acumulado['diferencia'] > 1)
                            <span class="font-semibold text-green-700">
                                (₡{{ number_format($acumulado['diferencia'], 2) }} de más)
                            </span>
                        @endif
                    @else
                        Le faltan
                        <span class="font-semibold text-red-700">₡{{ number_format(abs($acumulado['diferencia']), 2) }}</span>
                        @if($acumulado['meses_atraso'] > 0)
                            · equivale a {{ $acumulado['meses_atraso'] }}
                            {{ $acumulado['meses_atraso'] == 1 ? 'mes' : 'meses' }} de su promesa
                        @endif
                    @endif
                </p>
                <p class="text-xs text-gray-500 mt-1">
                    Acumulado de enero a {{ $meses[$mes - 1] }} de {{ $año }}
                    ({{ $acumulado['meses_exigibles'] }}
                    {{ $acumulado['meses_exigibles'] == 1 ? 'mes exigible' : 'meses exigibles' }})
                    @if($acumulado['mes_en_curso'])
                        · {{ $meses[$mes - 1] }} sigue abierto, así que todavía no se cobra
                    @endif
                </p>
            </div>
            @if($acumulado['porcentaje'] !== null)
            <div class="text-center sm:text-right">
                <p class="text-xs text-gray-600 uppercase tracking-wide">Cumplimiento</p>
                <p class="text-4xl font-bold {{ $acumulado['al_dia'] ? 'text-green-700' : 'text-red-700' }}">
                    {{ $acumulado['porcentaje'] }}%
                </p>
            </div>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-5">
            <div class="bg-white rounded-md px-4 py-3">
                <p class="text-xs text-gray-600">Le correspondía dar</p>
                <p class="text-xl font-bold text-blue-600">₡{{ number_format($acumulado['prometido'], 2) }}</p>
            </div>
            <div class="bg-white rounded-md px-4 py-3">
                <p class="text-xs text-gray-600">Ha dado</p>
                <p class="text-xl font-bold text-green-600">₡{{ number_format($acumulado['dado'], 2) }}</p>
            </div>
            <div class="bg-white rounded-md px-4 py-3">
                <p class="text-xs text-gray-600">Diferencia</p>
                <p class="text-xl font-bold {{ $acumulado['diferencia'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $acumulado['diferencia'] >= 0 ? '+' : '−' }}₡{{ number_format(abs($acumulado['diferencia']), 2) }}
                </p>
            </div>
        </div>
    </div>

    <!-- Resumen del Mes -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-600">Esperado en {{ $meses[$mes - 1] }}</p>
            <p class="text-2xl font-bold text-blue-600">₡{{ number_format($resumenTotal['total_prometido'], 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-600">Dado en {{ $meses[$mes - 1] }}</p>
            <p class="text-2xl font-bold text-green-600">₡{{ number_format($resumenTotal['total_dado'], 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-600">Diferencia del Mes</p>
            <p class="text-2xl font-bold {{ $resumenTotal['saldo_total'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                {{ $resumenTotal['saldo_total'] >= 0 ? '+' : '-' }}₡{{ number_format(abs($resumenTotal['saldo_total']), 2) }}
            </p>
            <p class="text-xs text-gray-500 mt-1">
                Solo este mes. Lo que decide si va al día es el acumulado de arriba.
            </p>
        </div>
    </div>

    <!-- Tabla de Compromisos del Mes -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">Detalle - {{ $meses[$mes - 1] }} {{ $año }}</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Categoría</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Esperado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Dado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Diferencia</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Estado</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($compromisos as $compromiso)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 capitalize">
                            {{ ucfirst($compromiso->categoria) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                            ₡{{ number_format($compromiso->monto_prometido, 2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-blue-600 font-semibold">
                            ₡{{ number_format($compromiso->monto_dado, 2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold {{ $compromiso->saldo_actual >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ $compromiso->saldo_actual >= 0 ? '+' : '-' }}₡{{ number_format(abs($compromiso->saldo_actual), 2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            @if($compromiso->saldo_actual >= 0)
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                    @if($compromiso->saldo_actual > 0)
                                        Excedente
                                    @else
                                        Al día
                                    @endif
                                </span>
                            @else
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                    Déficit
                                </span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                            No hay promesas configuradas para esta persona
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Aportes en categorias sin promesa: no computan contra ningun
         compromiso, pero antes no se veian en ninguna parte y parecia que
         faltaba plata. --}}
    @if(!empty($aportesSinPromesa))
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-6">
        <h3 class="text-lg font-semibold text-gray-900">Aportes sin promesa asociada — {{ $año }}</h3>
        <p class="text-sm text-gray-600 mt-1">
            La persona aportó en estas categorías sin tener una promesa registrada.
            No cuentan contra ningún compromiso.
        </p>
        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach($aportesSinPromesa as $ap)
            <div class="flex items-center justify-between bg-white rounded-md px-4 py-3 border border-amber-100">
                <span class="text-sm text-gray-800">{{ $ap['nombre'] }}</span>
                <span class="text-sm font-semibold text-gray-900">₡{{ number_format($ap['total'], 2) }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Historial de Meses Anteriores -->
    @if($historial->count() > 1)
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">Historial por Mes</h3>
        </div>
        <div class="p-6">
            <div class="space-y-4">
                @foreach($historial->take(6) as $periodo => $compsMes)
                @php
                    list($añoHist, $mesHist) = explode('-', $periodo);
                    $totalMes = $compsMes->sum('saldo_actual');
                @endphp
                <div class="border-l-4 {{ $totalMes >= 0 ? 'border-green-500' : 'border-red-500' }} pl-4">
                    <div class="flex justify-between items-center">
                        <h4 class="font-semibold text-gray-900">{{ $meses[intval($mesHist) - 1] }} {{ $añoHist }}</h4>
                        <span class="font-bold {{ $totalMes >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ $totalMes >= 0 ? '+' : '-' }}₡{{ number_format(abs($totalMes), 2) }}
                        </span>
                    </div>
                    <div class="mt-2 grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
                        @foreach($compsMes as $comp)
                        <div>
                            <span class="text-gray-600 capitalize">{{ $comp->categoria }}:</span>
                            <span class="font-semibold {{ $comp->saldo_actual >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                {{ $comp->saldo_actual >= 0 ? '+' : '-' }}₡{{ number_format(abs($comp->saldo_actual), 2) }}
                            </span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    <!-- Notas explicativas -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h4 class="text-sm font-semibold text-blue-900 mb-2">ℹ️ Información</h4>
        <ul class="text-sm text-blue-800 space-y-1">
            <li>• <strong>Esperado:</strong> Lo que debió dar este mes según su promesa</li>
            <li>• <strong>Dado:</strong> Lo que realmente dio este mes</li>
            <li>• <strong>Diferencia:</strong> Dado - Esperado (cada mes es independiente)</li>
            <li>• <strong>Excedente (verde):</strong> Dio más de lo esperado</li>
            <li>• <strong>Déficit (rojo):</strong> Dio menos de lo esperado</li>
        </ul>
    </div>
</div>
@endsection

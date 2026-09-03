@extends('layouts.admin')

@section('title', 'IBBSC - Mi Perfil')
@section('page-title', 'Mi Perfil')

@section('content')
<div class="space-y-6">
    <!-- Información Personal -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center gap-4 mb-6">
            <div class="w-16 h-16 rounded-full bg-blue-600 flex items-center justify-center text-white text-2xl font-bold">
                {{ substr($persona->nombre, 0, 2) }}
            </div>
            <div>
                <h2 class="text-2xl font-bold text-gray-900">{{ $persona->nombre }}</h2>
                <p class="text-gray-600">{{ $persona->correo }}</p>
                @if($persona->telefono)
                <p class="text-gray-600">{{ $persona->telefono }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Estado general: sale del acumulado de todos los meses, no del mes en
         curso. Si un mes no se dio pero se repuso en otro, la persona esta al
         dia; mirando solo el mes actual parecia lo contrario. --}}
    <div class="rounded-lg shadow p-6 {{ $acumulado['al_dia'] ? 'bg-green-50 border border-green-300' : 'bg-red-50 border border-red-300' }}">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-sm text-gray-600">Estado de mis promesas</p>
                <p class="text-3xl font-bold {{ $acumulado['al_dia'] ? 'text-green-700' : 'text-red-700' }}">
                    {{ $acumulado['al_dia'] ? 'Al día' : 'Atrasado' }}
                </p>
                <p class="text-sm text-gray-700 mt-1">
                    @if($acumulado['prometido'] <= 0)
                        Aún no hay meses que corresponda exigir.
                    @elseif($acumulado['al_dia'])
                        Ha dado ₡{{ number_format($acumulado['dado'], 2) }} de
                        ₡{{ number_format($acumulado['prometido'], 2) }}
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
                @if($acumulado['desde'] && $acumulado['hasta'])
                <p class="text-xs text-gray-500 mt-1">
                    Suma de {{ $acumulado['desde']->locale('es')->isoFormat('MMMM YYYY') }}
                    a {{ $acumulado['hasta']->locale('es')->isoFormat('MMMM YYYY') }}
                </p>
                @endif
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
    </div>

    <!-- Resumen General -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm">Promesas Activas</p>
                    <p class="text-3xl font-bold mt-2">{{ $promesasConEstatus->count() }}</p>
                </div>
                <svg class="w-12 h-12 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>

        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm">Cumplidas este mes</p>
                    <p class="text-3xl font-bold mt-2">{{ $promesasConEstatus->where('cumplido', true)->count() }}</p>
                </div>
                <svg class="w-12 h-12 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
        </div>

        <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-lg shadow p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-red-100 text-sm">Meses con faltante</p>
                    <p class="text-3xl font-bold mt-2">{{ $compromisos->count() }}</p>
                    <p class="text-red-100 text-xs mt-1">Puede haberlos repuesto en otro mes</p>
                </div>
                <svg class="w-12 h-12 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
    </div>

    <!-- Promesas del Mes -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Mis Promesas - {{ now()->locale('es')->isoFormat('MMMM YYYY') }}</h3>
        </div>
        <div class="p-6">
            @if($promesasConEstatus->count() > 0)
                <div class="space-y-4">
                    @foreach($promesasConEstatus as $item)
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <h4 class="font-semibold text-gray-900 capitalize">{{ $item['promesa']->categoria }}</h4>
                                <p class="text-sm text-gray-600">
                                    {{ ucfirst($item['promesa']->frecuencia) }}
                                    &middot; ₡{{ number_format($item['promesa']->monto, 2) }} por vez
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-2xl font-bold text-gray-900">₡{{ number_format($item['pagado'], 2) }}</p>
                                {{-- Antes mostraba el monto crudo de la promesa. Lo correcto es
                                     lo esperado del mes: una promesa semanal de 1.000 espera
                                     1.000 x domingos del mes, no 1.000. --}}
                                <p class="text-sm text-gray-600">de ₡{{ number_format($item['esperado'], 2) }} este mes</p>
                            </div>
                        </div>
                        
                        <!-- Barra de progreso -->
                        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-500 {{ $item['cumplido'] ? 'bg-green-500' : 'bg-blue-500' }}" 
                                 style="width: {{ $item['porcentaje'] }}%"></div>
                        </div>
                        
                        <div class="mt-2 flex items-center justify-between text-sm">
                            @if($item['cumplido'])
                                <span class="text-green-600 font-semibold flex items-center">
                                    <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    ¡Cumplido!
                                </span>
                            @else
                                <span class="text-gray-600">Falta: ₡{{ number_format($item['faltante'], 2) }}</span>
                            @endif
                            <span class="text-gray-500">{{ number_format($item['porcentaje'], 1) }}%</span>
                        </div>
                    </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-12">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                    </svg>
                    <p class="mt-4 text-gray-600">No tienes promesas registradas</p>
                </div>
            @endif

            {{-- Aportes en categorias sin promesa: antes esta plata no aparecia
                 en ninguna parte del perfil y parecia que el sistema la perdia. --}}
            @if(!empty($aportesSinPromesa))
                <div class="mt-6 border border-amber-200 bg-amber-50 rounded-lg p-4">
                    <h4 class="font-semibold text-gray-900">Otros aportes de este año</h4>
                    <p class="text-sm text-gray-600 mt-1">
                        Diste en estas categorías sin tener una promesa registrada, así que no
                        cuentan contra ninguna promesa, pero quedaron registrados.
                    </p>
                    <div class="mt-3 space-y-2">
                        @foreach($aportesSinPromesa as $ap)
                        <div class="flex items-center justify-between bg-white rounded-md px-3 py-2 border border-amber-100">
                            <span class="text-sm text-gray-800">{{ $ap['nombre'] }}</span>
                            <span class="text-sm font-semibold text-gray-900">₡{{ number_format($ap['total'], 2) }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    <!-- Compromisos Pendientes -->
    @if($compromisos->count() > 0)
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Compromisos Pendientes</h3>
        </div>
        <div class="p-6">
            <div class="space-y-3">
                @foreach($compromisos as $compromiso)
                <div class="flex items-center justify-between p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div>
                        <p class="font-semibold text-gray-900 capitalize">{{ $compromiso->categoria }}</p>
                        <p class="text-sm text-gray-600">{{ $compromiso->descripcion }}</p>
                        <p class="text-xs text-gray-500 mt-1">Creado: {{ $compromiso->created_at->format('d/m/Y') }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xl font-bold text-red-600">₡{{ number_format($compromiso->deuda, 2) }}</p>
                        <p class="text-sm text-gray-600">Deuda pendiente</p>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    <!-- Información Adicional -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
        <div class="flex items-start">
            <svg class="w-6 h-6 text-blue-600 mt-1 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <h4 class="font-semibold text-blue-900 mb-2">Información Importante</h4>
                <ul class="text-sm text-blue-800 space-y-1">
                    <li>• Esta información se actualiza automáticamente cuando se registran tus ofrendas.</li>
                    <li>• Las promesas se calculan mensualmente según la frecuencia que estableciste.</li>
                    <li>• Si tienes dudas sobre tus compromisos, contacta al administrador de la iglesia.</li>
                    <li>• Solo puedes visualizar tu información, no puedes editarla desde aquí.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection

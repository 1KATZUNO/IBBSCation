<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Estado de cuenta - {{ $persona->nombre }}</title>
    <style>
        @page { size: A4 portrait; margin: 13mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; margin: 0; padding: 0; color: #1f2937; }

        /* DomPDF no soporta flexbox: el encabezado se arma con tabla */
        .header { width: 100%; border-bottom: 3px solid {{ $tenantColor }}; padding-bottom: 8px; }
        .header td { vertical-align: middle; border: none; padding: 0; }
        .logo-wrap { background-color: {{ $tenantColor }}; border-radius: 50%; width: 54px; height: 54px; text-align: center; }
        .logo-wrap img { width: 38px; height: 38px; margin-top: 8px; }
        .h-titulo { font-size: 15px; font-weight: bold; color: #111827; }
        .h-sub { font-size: 10.5px; color: {{ $tenantColor }}; margin-top: 2px; }
        .h-meta { font-size: 8px; color: #6b7280; }

        .persona { background: #f3f4f6; border-radius: 5px; padding: 8px 12px; margin: 11px 0 12px; }
        .persona .nombre { font-size: 14px; font-weight: bold; color: #111827; }
        .persona .dato { font-size: 8.5px; color: #6b7280; margin-top: 2px; }

        /* Estado general */
        .estado { border-radius: 7px; padding: 12px; margin-bottom: 12px; }
        .estado.ok { background: #ecfdf5; border: 1.5px solid #6ee7b7; }
        .estado.no { background: #fef2f2; border: 1.5px solid #fca5a5; }
        .estado td { border: none; padding: 0; vertical-align: middle; }
        .estado .rotulo { font-size: 8px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.6px; }
        .estado .valor { font-size: 22px; font-weight: bold; }
        .estado .detalle { font-size: 9px; color: #374151; margin-top: 3px; }
        .estado .pct { font-size: 30px; font-weight: bold; text-align: right; }
        .ok .valor, .ok .pct { color: #047857; }
        .no .valor, .no .pct { color: #b91c1c; }

        .cards { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin-top: 9px; }
        .card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 5px; padding: 7px; text-align: center; }
        .card .lbl { font-size: 7px; color: #6b7280; text-transform: uppercase; }
        .card .val { font-size: 13px; font-weight: bold; margin-top: 2px; }

        h3 { font-size: 10.5px; color: #374151; margin: 14px 0 5px; }

        table.t { width: 100%; border-collapse: collapse; }
        table.t th { background: {{ $tenantColor }}; color: #fff; font-size: 7.5px; text-transform: uppercase;
                     padding: 5px 4px; text-align: right; }
        table.t th.izq { text-align: left; }
        table.t td { border: 1px solid #e5e7eb; padding: 4px; font-size: 8.5px; text-align: right; }
        table.t td.izq { text-align: left; }
        table.t tbody tr:nth-child(even) { background: #fafafa; }
        .fila-total td { background: #eef2ff; font-weight: bold; font-size: 9px; border-top: 2px solid {{ $tenantColor }}; }
        .neg { color: #b91c1c; }
        .pos { color: #047857; }
        .cero { color: #9ca3af; }
        .pill { background: #dbeafe; color: #1e40af; border-radius: 8px; padding: 1px 5px; font-size: 7px; }

        .leyenda { margin-top: 12px; font-size: 7.5px; color: #6b7280; line-height: 1.5; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 7px; color: #9ca3af;
                  border-top: 1px solid #e5e7eb; padding-top: 5px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 66px;">
                <div class="logo-wrap">
                    <img src="data:image/png;base64,{{ $tenantLogoBase64 }}" alt="Logo">
                </div>
            </td>
            <td>
                <div class="h-titulo">{{ $tenantSiglas }} &middot; {{ $tenantNombre }}</div>
                <div class="h-sub">Estado de cuenta personal</div>
            </td>
            <td style="text-align: right;">
                <div class="h-meta">Generado</div>
                <div class="h-meta" style="font-size: 9px; color: #374151;">{{ now()->format('d/m/Y H:i') }}</div>
            </td>
        </tr>
    </table>

    <div class="persona">
        <div class="nombre">{{ $persona->nombre }}</div>
        <div class="dato">
            @if($persona->telefono){{ $persona->telefono }}@endif
            @if($persona->telefono && $persona->correo) &middot; @endif
            @if($persona->correo){{ $persona->correo }}@endif
        </div>
        <div class="dato" style="margin-top: 4px; color: #374151; font-weight: bold;">
            Período: {{ $periodo }}
        </div>
    </div>

    {{-- El estado sale del acumulado de todos los meses cerrados, no del mes
         en curso: quien no dio un mes pero repuso en otro esta al dia. --}}
    <div class="estado {{ $acumulado['al_dia'] ? 'ok' : 'no' }}">
        <table style="width: 100%;">
            <tr>
                <td>
                    <div class="rotulo">Estado de su promesa</div>
                    <div class="valor">{{ $acumulado['al_dia'] ? 'Al día' : 'Atrasado' }}</div>
                    <div class="detalle">
                        @if($acumulado['prometido'] <= 0)
                            Todavía no hay meses que corresponda exigir.
                        @elseif($acumulado['al_dia'])
                            Ha dado ₡{{ number_format($acumulado['dado'], 2) }} de los
                            ₡{{ number_format($acumulado['prometido'], 2) }} que le corresponden.
                        @else
                            {{-- Ojo: una directiva pegada a una letra (…promesa@endif) no la
                                 compila Blade y deja el @if sin cerrar. Va separada a proposito. --}}
                            Le faltan ₡{{ number_format(abs($acumulado['diferencia']), 2) }}
                            @if($acumulado['meses_atraso'] > 0)
                                , equivalentes a {{ $acumulado['meses_atraso'] }}
                                {{ $acumulado['meses_atraso'] == 1 ? 'mes' : 'meses' }} de su promesa
                            @endif
                        @endif
                    </div>
                    @if($acumulado['mes_en_curso'])
                    <div class="detalle" style="color: #6b7280; font-size: 8px;">
                        El mes en curso todavía no se cobra, pero lo que ya dio en él sí está contado.
                    </div>
                    @endif
                </td>
                @if($acumulado['porcentaje'] !== null)
                <td style="width: 90px;">
                    <div class="rotulo" style="text-align: right;">Cumplimiento</div>
                    <div class="pct">{{ $acumulado['porcentaje'] }}%</div>
                </td>
                @endif
            </tr>
        </table>

        <table class="cards">
            <tr>
                <td class="card" style="width: 33%;">
                    <div class="lbl">Le correspondía dar</div>
                    <div class="val" style="color: #1e40af;">₡{{ number_format($acumulado['prometido'], 2) }}</div>
                </td>
                <td class="card" style="width: 33%;">
                    <div class="lbl">Ha dado</div>
                    <div class="val" style="color: #047857;">₡{{ number_format($acumulado['dado'], 2) }}</div>
                </td>
                <td class="card" style="width: 34%;">
                    <div class="lbl">Diferencia</div>
                    <div class="val {{ $acumulado['diferencia'] >= 0 ? 'pos' : 'neg' }}">
                        {{ $acumulado['diferencia'] >= 0 ? '+' : '−' }}₡{{ number_format(abs($acumulado['diferencia']), 2) }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    @if($porRubro->count() > 0)
    <h3>Por rubro</h3>
    <table class="t">
        <thead>
            <tr>
                <th class="izq">Rubro</th>
                <th>Le correspondía</th>
                <th>Ha dado</th>
                <th>Diferencia</th>
                <th>Cumple</th>
            </tr>
        </thead>
        <tbody>
            @foreach($porRubro as $r)
            @php $dif = $r['dado'] - $r['prometido']; @endphp
            <tr>
                <td class="izq">{{ ucfirst($r['categoria']) }}</td>
                <td>₡{{ number_format($r['prometido'], 2) }}</td>
                <td>₡{{ number_format($r['dado'], 2) }}</td>
                <td class="{{ $dif >= 0 ? 'pos' : 'neg' }}">
                    {{ $dif >= 0 ? '+' : '−' }}₡{{ number_format(abs($dif), 2) }}
                </td>
                <td>{{ $r['prometido'] > 0 ? round($r['dado'] / $r['prometido'] * 100).'%' : '—' }}</td>
            </tr>
            @endforeach
            <tr class="fila-total">
                <td class="izq">TOTAL</td>
                <td>₡{{ number_format($acumulado['prometido'], 2) }}</td>
                <td>₡{{ number_format($acumulado['dado'], 2) }}</td>
                <td class="{{ $acumulado['diferencia'] >= 0 ? 'pos' : 'neg' }}">
                    {{ $acumulado['diferencia'] >= 0 ? '+' : '−' }}₡{{ number_format(abs($acumulado['diferencia']), 2) }}
                </td>
                <td>{{ $acumulado['porcentaje'] !== null ? $acumulado['porcentaje'].'%' : '—' }}</td>
            </tr>
        </tbody>
    </table>
    @endif

    @if($porMes->count() > 0)
    <h3>Mes a mes</h3>
    <table class="t">
        <thead>
            <tr>
                <th class="izq">Mes</th>
                <th>Le correspondía</th>
                <th>Dio</th>
                <th>Diferencia del mes</th>
            </tr>
        </thead>
        <tbody>
            @foreach($porMes as $m)
            @php $dif = $m['dado'] - $m['prometido']; @endphp
            <tr>
                <td class="izq">{{ ucfirst($m['etiqueta']) }}</td>
                <td class="{{ $m['prometido'] == 0 ? 'cero' : '' }}">₡{{ number_format($m['prometido'], 2) }}</td>
                <td class="{{ $m['dado'] == 0 ? 'cero' : '' }}">₡{{ number_format($m['dado'], 2) }}</td>
                <td class="{{ $dif >= 0 ? 'pos' : 'neg' }}">
                    {{ $dif >= 0 ? '+' : '−' }}₡{{ number_format(abs($dif), 2) }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="leyenda">
        Un mes en rojo no significa estar atrasado: lo que decide es la suma de todos los meses,
        que es la que aparece arriba. Quien no dio en un mes y repuso en otro está al día.
    </div>
    @endif

    @if($aportes->count() > 0)
    <h3>Sus sobres, uno por uno</h3>
    <table class="t">
        <thead>
            <tr>
                <th class="izq">Fecha</th>
                <th class="izq">Culto</th>
                <th class="izq">Forma de pago</th>
                <th class="izq">Detalle</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @php $totalAportes = 0; @endphp
            @foreach($aportes as $s)
            @php $totalAportes += $s->total_declarado_crc; @endphp
            <tr>
                <td class="izq">{{ $s->culto ? $s->culto->fecha->format('d/m/Y') : '—' }}</td>
                <td class="izq"><span class="pill">{{ $s->culto ? $s->culto->tipo_nombre : '—' }}</span></td>
                <td class="izq">
                    {{ ucfirst($s->metodo_pago) }}
                    @if($s->comprobante_numero)
                        <span style="color: #6b7280;">· {{ $s->comprobante_numero }}</span>
                    @endif
                </td>
                <td class="izq" style="font-size: 7.5px;">
                    @foreach($s->detalles as $d){{ ucfirst($d->categoria) }} ₡{{ number_format($d->monto, 2) }}@if(!$loop->last) · @endif @endforeach
                </td>
                <td><strong>₡{{ number_format($s->total_declarado_crc, 2) }}</strong></td>
            </tr>
            @endforeach
            <tr class="fila-total">
                <td class="izq" colspan="4">TOTAL ENTREGADO ({{ $aportes->count() }} sobres)</td>
                <td>₡{{ number_format($totalAportes, 2) }}</td>
            </tr>
        </tbody>
    </table>
    <div class="leyenda">
        Este total incluye todo lo que entregó, diezmo y ofrendas especiales incluidos. El cuadro de
        arriba solo cuenta los rubros que forman parte de su promesa, por eso las cifras difieren.
    </div>
    @endif

    @if(!empty($aportesSinPromesa))
    <h3>Aportes en rubros sin promesa registrada</h3>
    <table class="t">
        <thead>
            <tr><th class="izq">Rubro</th><th>Dado</th></tr>
        </thead>
        <tbody>
            @foreach($aportesSinPromesa as $a)
            <tr>
                <td class="izq">{{ $a['nombre'] }}</td>
                <td>₡{{ number_format($a['total'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="leyenda">
        Son rubros donde aportó sin tener una promesa registrada, así que no cuentan para el
        cumplimiento de arriba.
    </div>
    @endif

    <div class="footer">
        {{ $tenantSiglas }} &middot; {{ $tenantNombre }} &middot; Estado de cuenta de {{ $persona->nombre }}
    </div>
</body>
</html>

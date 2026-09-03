<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Resumen de Ingresos</title>
    <style>
        @page { size: A4 landscape; margin: 13mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; margin: 0; padding: 0; color: #1f2937; }

        /* DomPDF no soporta flexbox: el encabezado se arma con tabla */
        .header { width: 100%; border-bottom: 3px solid {{ $tenantColor }}; padding-bottom: 8px; }
        .header td { vertical-align: middle; border: none; padding: 0; }
        .logo-wrap { background-color: {{ $tenantColor }}; border-radius: 50%; width: 54px; height: 54px; text-align: center; }
        .logo-wrap img { width: 38px; height: 38px; margin-top: 8px; }
        .h-titulo { font-size: 15px; font-weight: bold; color: #111827; }
        .h-sub { font-size: 10.5px; color: {{ $tenantColor }}; margin-top: 2px; }
        .h-meta { font-size: 8px; color: #6b7280; }

        .periodo { background: #f3f4f6; border-radius: 5px; padding: 6px 11px; margin: 11px 0 12px; font-size: 9.5px; }

        /* Tarjetas de totales */
        .cards { width: 100%; border-collapse: separate; border-spacing: 7px 0; margin-bottom: 6px; }
        .card { border-radius: 7px; padding: 11px 8px; text-align: center; }
        .card .lbl { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.7px; }
        .card .val { font-size: 17px; font-weight: bold; margin-top: 5px; }
        .card .hint { font-size: 7px; margin-top: 3px; opacity: 0.75; }
        .c-general { background: #1e1b4b; color: #ffffff; }
        .c-efectivo { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .c-transfer { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

        h3 { font-size: 10.5px; color: #374151; margin: 14px 0 6px; }

        table.detalle { width: 100%; border-collapse: collapse; }
        table.detalle th { background: {{ $tenantColor }}; color: #fff; font-size: 7.5px; text-transform: uppercase;
                           padding: 5px 4px; text-align: right; }
        table.detalle th.izq { text-align: left; }
        table.detalle td { border: 1px solid #e5e7eb; padding: 5px 4px; font-size: 8.5px; text-align: right; }
        table.detalle td.izq { text-align: left; }
        table.detalle tbody tr:nth-child(even) { background: #fafafa; }
        .pill { background: #dbeafe; color: #1e40af; border-radius: 8px; padding: 1px 6px; font-size: 7.5px; }
        .fila-total td { background: #eef2ff; font-weight: bold; font-size: 9.5px; border-top: 2px solid {{ $tenantColor }}; }
        .neg { color: #dc2626; }
        .cero { color: #9ca3af; }

        /* Firmas */
        .firmas { width: 100%; border-collapse: collapse; margin-top: 26px; }
        .firmas td { text-align: center; vertical-align: bottom; padding: 0 14px; border: none; }
        .firma-img { height: 40px; }
        .firma-img img { max-height: 38px; max-width: 120px; }
        .firma-linea { border-top: 1px solid #374151; padding-top: 5px; margin-top: 4px; }
        .firma-nombre { font-size: 9px; font-weight: bold; }
        .firma-rol { font-size: 7px; color: #6b7280; }
        .firma-culto { font-size: 6.5px; color: #9ca3af; margin-top: 1px; }

        .leyenda { margin-top: 11px; font-size: 7px; color: #6b7280; text-align: center; line-height: 1.5; }
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
                <div class="h-sub">Resumen de Ingresos</div>
            </td>
            <td style="text-align: right;">
                <div class="h-meta">Generado</div>
                <div class="h-meta" style="font-size: 9px; color: #374151;">{{ now()->format('d/m/Y H:i') }}</div>
            </td>
        </tr>
    </table>

    <div class="periodo">
        <strong>Período:</strong> {{ $periodoTexto }}
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Cultos:</strong> {{ $cantidadCultos }}
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Sobres:</strong> {{ $cantidadSobres }}
    </div>

    <table class="cards">
        <tr>
            <td class="card c-general" style="width: 34%;">
                <div class="lbl">Total General</div>
                <div class="val">₡{{ number_format($totalGeneral, 2) }}</div>
                <div class="hint">Efectivo + Transferencias</div>
            </td>
            <td class="card c-efectivo" style="width: 33%;">
                <div class="lbl">Total Efectivo</div>
                <div class="val">₡{{ number_format($totalEfectivo, 2) }}</div>
                <div class="hint">Sobres + Suelto − Egresos</div>
            </td>
            <td class="card c-transfer" style="width: 33%;">
                <div class="lbl">Total Transferencias</div>
                <div class="val">₡{{ number_format($totalTransferencias, 2) }}</div>
                <div class="hint">Sobres por transferencia</div>
            </td>
        </tr>
    </table>

    <h3>Detalle de Ingresos</h3>
    <table class="detalle">
        <thead>
            <tr>
                <th class="izq">Fecha</th>
                <th class="izq">Tipo</th>
                @foreach($categories as $cat)
                <th>{{ $cat->nombre }}</th>
                @endforeach
                <th>Suelto</th>
                @if($hayEgresos)
                <th>Egresos</th>
                @endif
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @php
                $tot = [];
                foreach ($categories as $cat) { $tot[$cat->slug] = 0; }
                $totSuelto = 0; $totEgr = 0; $totFila = 0;
            @endphp

            @forelse($registros as $r)
                @php
                    foreach ($categories as $cat) { $tot[$cat->slug] += $r[$cat->slug] ?? 0; }
                    $totSuelto += $r['suelto'];
                    $totEgr += $r['egresos'];
                    $totFila += $r['total'];
                @endphp
                <tr>
                    <td class="izq">{{ $r['fecha'] }}</td>
                    <td class="izq"><span class="pill">{{ $r['tipo'] }}</span></td>
                    @foreach($categories as $cat)
                    @php $v = $r[$cat->slug] ?? 0; @endphp
                    <td class="{{ $v == 0 ? 'cero' : '' }}">₡{{ number_format($v, 2) }}</td>
                    @endforeach
                    <td class="{{ $r['suelto'] == 0 ? 'cero' : '' }}">₡{{ number_format($r['suelto'], 2) }}</td>
                    @if($hayEgresos)
                    <td class="{{ $r['egresos'] > 0 ? 'neg' : 'cero' }}">
                        {{ $r['egresos'] > 0 ? '−' : '' }}₡{{ number_format($r['egresos'], 2) }}
                    </td>
                    @endif
                    <td><strong>₡{{ number_format($r['total'], 2) }}</strong></td>
                </tr>
            @empty
                <tr>
                    <td class="izq" colspan="{{ count($categories) + ($hayEgresos ? 5 : 4) }}">
                        No hay cultos registrados en el período seleccionado.
                    </td>
                </tr>
            @endforelse

            @if(count($registros) > 0)
            <tr class="fila-total">
                <td class="izq" colspan="2">TOTALES</td>
                @foreach($categories as $cat)
                <td>₡{{ number_format($tot[$cat->slug], 2) }}</td>
                @endforeach
                <td>₡{{ number_format($totSuelto, 2) }}</td>
                @if($hayEgresos)
                <td class="neg">−₡{{ number_format($totEgr, 2) }}</td>
                @endif
                <td>₡{{ number_format($totFila, 2) }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    <h3>Firmas de autorización</h3>
    <table class="firmas">
        <tr>
            @foreach($firmas as $f)
            <td style="width: {{ intdiv(100, max(count($firmas), 1)) }}%;">
                <div class="firma-img">
                    @if(!empty($f['imagen']))
                        <img src="{{ $f['imagen'] }}" alt="">
                    @endif
                </div>
                <div class="firma-linea">
                    {{-- El espacio en blanco va fuera de {{ }}: Blade escapa el
                         contenido y la entidad se imprimia literal como texto. --}}
                    <div class="firma-nombre">@if($f['nombre']){{ $f['nombre'] }}@else&nbsp;@endif</div>
                    <div class="firma-rol">{{ $f['rol'] }}</div>
                    @if(!empty($f['detalle']))
                    <div class="firma-culto">{{ $f['detalle'] }}</div>
                    @endif
                </div>
            </td>
            @endforeach
        </tr>
    </table>

    @if(!empty($firmasPorCulto))
    {{-- Con muchos cultos no caben los bloques de firma, asi que se deja
         constancia de quien firmo cada recuento y las lineas de arriba quedan
         para firmar este resumen. --}}
    <h3>Firmas de los recuentos incluidos</h3>
    <table class="detalle">
        <thead>
            <tr>
                <th class="izq">Culto</th>
                <th class="izq">Firmaron el recuento</th>
            </tr>
        </thead>
        <tbody>
            @foreach($firmasPorCulto as $culto => $nombres)
            <tr>
                <td class="izq" style="width: 22%;">{{ $culto }}</td>
                <td class="izq">{{ implode(' · ', $nombres) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="leyenda">
        Quien firma como &laquo;Recibido por&raquo; confirma que recibió el efectivo detallado en este
        resumen y que realizará el depósito bancario correspondiente.
    </div>

    <div class="footer">
        {{ $tenantSiglas }} &middot; {{ $tenantNombre }} &middot; Documento generado por el sistema
    </div>
</body>
</html>

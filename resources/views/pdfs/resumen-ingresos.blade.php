<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Resumen de Ingresos</title>
    <style>
        @page { size: A4 portrait; margin: 16mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; margin: 0; padding: 0; color: #1f2937; }

        /* DomPDF no soporta flexbox: el encabezado se arma con tabla */
        .header { width: 100%; border-bottom: 3px solid {{ $tenantColor }}; padding-bottom: 10px; margin-bottom: 4px; }
        .header td { vertical-align: middle; border: none; padding: 0; }
        .logo-wrap { background-color: {{ $tenantColor }}; border-radius: 50%; width: 62px; height: 62px; text-align: center; }
        .logo-wrap img { width: 44px; height: 44px; margin-top: 9px; }
        .h-titulo { font-size: 17px; font-weight: bold; color: #111827; }
        .h-sub { font-size: 11px; color: {{ $tenantColor }}; margin-top: 2px; }
        .h-meta { font-size: 8.5px; color: #6b7280; }

        .periodo { background: #f3f4f6; border-radius: 5px; padding: 8px 12px; margin: 14px 0 16px; font-size: 10px; }

        /* Tarjetas de totales */
        .cards { width: 100%; border-collapse: separate; border-spacing: 7px 0; margin-bottom: 4px; }
        .card { border-radius: 7px; padding: 14px 10px; text-align: center; }
        .card .lbl { font-size: 8px; text-transform: uppercase; letter-spacing: 0.8px; }
        .card .val { font-size: 19px; font-weight: bold; margin-top: 6px; }
        .card .hint { font-size: 7.5px; margin-top: 4px; opacity: 0.75; }
        .c-general { background: #1e1b4b; color: #ffffff; }
        .c-efectivo { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .c-transfer { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

        h3 { font-size: 11px; color: #374151; margin: 20px 0 7px; }

        table.desglose { width: 100%; border-collapse: collapse; }
        table.desglose th { background: {{ $tenantColor }}; color: #fff; font-size: 8.5px; text-transform: uppercase;
                            padding: 6px 8px; text-align: left; }
        table.desglose td { border: 1px solid #e5e7eb; padding: 6px 8px; font-size: 9.5px; }
        table.desglose td.num { text-align: right; }
        .fila-sub { background: #f9fafb; font-weight: bold; }
        .fila-total { background: #eef2ff; font-weight: bold; font-size: 10.5px; }
        .neg { color: #dc2626; }

        /* Firmas */
        .firmas { width: 100%; border-collapse: collapse; margin-top: 34px; }
        .firmas td { width: 33%; text-align: center; vertical-align: bottom; padding: 0 10px; border: none; }
        .firma-img { height: 46px; }
        .firma-img img { max-height: 44px; max-width: 130px; }
        .firma-linea { border-top: 1px solid #374151; padding-top: 6px; margin-top: 4px; }
        .firma-nombre { font-size: 9.5px; font-weight: bold; }
        .firma-rol { font-size: 7.5px; color: #6b7280; }

        .leyenda { margin-top: 14px; font-size: 7.5px; color: #6b7280; text-align: center; line-height: 1.5; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 7.5px; color: #9ca3af;
                  border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 74px;">
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
                <div class="h-meta" style="font-size: 9.5px; color: #374151;">{{ now()->format('d/m/Y H:i') }}</div>
            </td>
        </tr>
    </table>

    <div class="periodo">
        <strong>Período:</strong> {{ $periodoTexto }}
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Cultos incluidos:</strong> {{ $cantidadCultos }}
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

    <h3>Desglose</h3>
    <table class="desglose">
        <thead>
            <tr>
                <th>Concepto</th>
                <th style="text-align: right; width: 34%;">Monto</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Sobres en efectivo</td>
                <td class="num">₡{{ number_format($sobresEfectivo, 2) }}</td>
            </tr>
            <tr>
                <td>Dinero suelto</td>
                <td class="num">₡{{ number_format($totalSuelto, 2) }}</td>
            </tr>
            <tr>
                <td>Egresos</td>
                <td class="num {{ $totalEgresos > 0 ? 'neg' : '' }}">
                    {{ $totalEgresos > 0 ? '−' : '' }}₡{{ number_format($totalEgresos, 2) }}
                </td>
            </tr>
            <tr class="fila-sub">
                <td>Subtotal efectivo</td>
                <td class="num">₡{{ number_format($totalEfectivo, 2) }}</td>
            </tr>
            <tr>
                <td>Sobres por transferencia</td>
                <td class="num">₡{{ number_format($sobresTransferencias, 2) }}</td>
            </tr>
            <tr class="fila-sub">
                <td>Subtotal transferencias</td>
                <td class="num">₡{{ number_format($totalTransferencias, 2) }}</td>
            </tr>
            <tr class="fila-total">
                <td>TOTAL GENERAL</td>
                <td class="num">₡{{ number_format($totalGeneral, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <h3>Firmas de autorización</h3>
    <table class="firmas">
        <tr>
            @foreach($firmas as $f)
            <td>
                <div class="firma-img">
                    @if(!empty($f['imagen']))
                        <img src="{{ $f['imagen'] }}" alt="">
                    @endif
                </div>
                <div class="firma-linea">
                    <div class="firma-nombre">{{ $f['nombre'] ?: '&nbsp;' }}</div>
                    <div class="firma-rol">{{ $f['rol'] }}</div>
                </div>
            </td>
            @endforeach
        </tr>
    </table>

    <div class="leyenda">
        Quien firma como &laquo;Recibido por&raquo; confirma que recibió el efectivo detallado en este
        resumen y que realizará el depósito bancario correspondiente.
    </div>

    <div class="footer">
        {{ $tenantSiglas }} &middot; {{ $tenantNombre }} &middot; Documento generado por el sistema
    </div>
</body>
</html>

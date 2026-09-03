<?php

namespace App\Http\Controllers;

use App\Models\Culto;
use App\Models\Persona;
use App\Models\Sobre;
use App\Models\SobreDetalle;
use App\Models\OfrendaSuelta;
use App\Models\AuditLog;
use App\Services\CalculoTotalesCultoService;
use Illuminate\Http\Request;

class RecuentoController extends Controller
{
    protected $calculoService;

    protected $promesasService;

    public function __construct(
        CalculoTotalesCultoService $calculoService,
        \App\Services\CalculoPromesasService $promesasService
    ) {
        $this->calculoService = $calculoService;
        $this->promesasService = $promesasService;
    }

    public function index(Request $request)
    {
        $cultoId = $request->get('culto_id');
        $verCerrado = $request->has('ver_cerrado');
        
        // El selector trae los cultos abiertos; al administrador se le suman los
        // cerrados, que son los que puede corregir si aparece un sobre despues.
        $cultos = Culto::query()
            ->when(! $this->puedeCorregirCerrado(), fn ($q) => $q->where('cerrado', false))
            ->orderBy('fecha', 'desc')
            ->get();
        
        // Traer cultos cerrados para la lista de solo lectura
        $cultosCerrados = Culto::where('cerrado', true)
            ->with('totales')
            ->orderBy('cerrado_at', 'desc')
            ->get();
        
        // Si hay un culto seleccionado
        $cultoSeleccionado = null;
        if ($cultoId) {
            $cultoSeleccionado = Culto::find($cultoId);
        }
        
        $sobres = $cultoId 
            ? Sobre::where('culto_id', $cultoId)->with(['persona', 'detalles'])->get()
            : collect();

        $ofrendasSueltas = $cultoId
            ? OfrendaSuelta::where('culto_id', $cultoId)->orderBy('created_at', 'desc')->get()
            : collect();

        $egresos = $cultoId
            ? \App\Models\Egreso::where('culto_id', $cultoId)->orderBy('created_at', 'desc')->get()
            : collect();

        $categorias = tenant_categories(['es_ofrenda_suelta' => false]);
        $puedeCorregirCerrado = $this->puedeCorregirCerrado();

        return view('recuento.index', compact('sobres', 'cultos', 'cultoSeleccionado', 'ofrendasSueltas', 'egresos', 'cultosCerrados', 'categorias', 'puedeCorregirCerrado'));
    }

    /**
     * Un culto cerrado ya tiene el recuento firmado, pero a veces aparecen
     * sobres despues (boletas traspapeladas, un sobre que no se digito ese
     * dia). El administrador puede corregirlo sin tener que reabrir el culto;
     * cualquier otro rol no.
     */
    protected function puedeCorregirCerrado(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return method_exists($user, 'isAdmin') ? $user->isAdmin() : $user->rol === 'admin';
    }

    public function create(Request $request)
    {
        $cultoId = $request->get('culto_id');
        $culto = $cultoId ? Culto::findOrFail($cultoId) : null;

        if ($culto && $culto->cerrado && ! $this->puedeCorregirCerrado()) {
            return redirect()->route('recuento.index', ['culto_id' => $culto->id])
                ->with('error', 'No se pueden agregar sobres a un culto cerrado.');
        }

        $personas = Persona::where('activo', true)->get();
        $categorias = tenant_categories(['es_ofrenda_suelta' => false]);

        return view('recuento.create', compact('culto', 'personas', 'categorias'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'culto_id' => 'required|exists:cultos,id',
            'persona_id' => 'nullable|exists:personas,id',
            'metodo_pago' => 'required|in:efectivo,transferencia',
            'comprobante_numero' => 'nullable|string|max:100',
            'moneda' => 'nullable|in:CRC,USD',
            'notas' => 'nullable|string',
            'detalles' => 'required|array',
            'detalles.*.categoria' => 'required|string',
            'detalles.*.monto' => 'required|numeric|min:0',
        ]);

        if ($validated['metodo_pago'] === 'transferencia') {
            $request->validate([
                'comprobante_numero' => 'required|string|max:100',
            ]);
        }

        // Un culto cerrado solo lo puede tocar el administrador
        $culto = Culto::findOrFail($validated['culto_id']);
        if ($culto->cerrado && ! $this->puedeCorregirCerrado()) {
            return redirect()->route('recuento.index', ['culto_id' => $culto->id])
                ->with('error', 'No se pueden agregar sobres a un culto cerrado.');
        }

        // Calcular total declarado
        $totalDeclarado = collect($validated['detalles'])->sum('monto');

        // Evitar el doble envio del formulario. Antes bastaba con que existiera
        // cualquier sobre de esa persona en ese culto en los ultimos 30
        // segundos, lo que bloqueaba dos sobres distintos de la misma persona
        // y molestaba al digitar sobres viejos de corrido. Ahora tienen que
        // coincidir tambien el monto y el metodo de pago, que es lo que
        // realmente identifica un envio repetido.
        $duplicado = Sobre::where('culto_id', $validated['culto_id'])
            ->where('persona_id', $validated['persona_id'] ?? null)
            ->where('total_declarado', $totalDeclarado)
            ->where('metodo_pago', $validated['metodo_pago'])
            ->where('created_at', '>=', now()->subSeconds(10))
            ->exists();

        if ($duplicado) {
            return redirect()->route('recuento.index', ['culto_id' => $culto->id])
                ->with('warning', 'No se guardo: hace unos segundos se registro un sobre igual '
                    . '(misma persona, mismo monto y mismo metodo de pago) en este culto. '
                    . 'Si de verdad son dos sobres distintos, vuelva a intentarlo en unos segundos.');
        }

        // Moneda y tipo de cambio
        $moneda = $validated['moneda'] ?? 'CRC';
        $tipoCambioVenta = null;
        $tipoCambioId = null;

        if ($moneda === 'USD') {
            $tipoCambio = \App\Models\TipoCambio::hoy();
            if ($tipoCambio) {
                $tipoCambioVenta = $tipoCambio->venta;
                $tipoCambioId = $tipoCambio->id;
            }
        }

        $sobre = Sobre::create([
            'culto_id' => $validated['culto_id'],
            'persona_id' => $validated['persona_id'] ?? null,
            'metodo_pago' => $validated['metodo_pago'],
            'comprobante_numero' => $validated['comprobante_numero'] ?? null,
            'total_declarado' => $totalDeclarado,
            'moneda' => $moneda,
            'tipo_cambio_venta' => $tipoCambioVenta,
            'tipo_cambio_id' => $tipoCambioId,
            'notas' => $validated['notas'] ?? null,
        ]);

        // Auditoría
        $user = $request->user();
        AuditLog::create([
            'user_id' => $user->id ?? null,
            'user_name' => $user->name ?? ($user->nombre ?? null),
            'user_email' => $user->email ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 255),
            'method' => 'POST',
            'route' => 'recuento.store',
            // Se distingue en la bitacora: agregar a un culto ya firmado no es
            // lo mismo que digitarlo el mismo dia.
            'action' => $culto->cerrado ? 'Agregar Sobre a culto cerrado' : 'Agregar Sobre',
            'details' => json_encode([
                'culto_id' => $sobre->culto_id,
                'culto_cerrado' => (bool) $culto->cerrado,
                'sobre_id' => $sobre->id,
                'metodo_pago' => $sobre->metodo_pago,
                'comprobante_numero' => $sobre->comprobante_numero,
                'total_declarado' => $sobre->total_declarado,
            ]),
        ]);

        // Crear detalles
        foreach ($validated['detalles'] as $detalle) {
            if ($detalle['monto'] > 0) {
                SobreDetalle::create([
                    'sobre_id' => $sobre->id,
                    'categoria' => $detalle['categoria'],
                    'monto' => $detalle['monto'],
                ]);
            }
        }

        // Recalcular totales del culto
        $this->calculoService->recalcular($sobre->culto);
        // Mantener al dia los compromisos de la persona del sobre.
        $this->promesasService->sincronizarCulto($sobre->culto, $sobre->persona_id);

        return redirect()->route('recuento.index', ['culto_id' => $validated['culto_id']])
            ->with('success', $culto->cerrado
                ? 'Sobre agregado al culto cerrado. Los totales se recalcularon y quedo registrado en la bitacora.'
                : 'Sobre registrado correctamente.');
    }

    public function edit(Sobre $sobre)
    {
        if ($sobre->culto->cerrado && ! $this->puedeCorregirCerrado()) {
            return redirect()->route('recuento.index', ['culto_id' => $sobre->culto_id])
                ->with('error', 'No se puede editar un sobre de un culto cerrado.');
        }

        $sobre->load(['detalles', 'culto']);
        $personas = Persona::where('activo', true)->get();
        $categorias = tenant_categories(['es_ofrenda_suelta' => false]);

        return view('recuento.edit', compact('sobre', 'personas', 'categorias'));
    }

    public function update(Request $request, Sobre $sobre)
    {
        if ($sobre->culto->cerrado && ! $this->puedeCorregirCerrado()) {
            return redirect()->route('recuento.index', ['culto_id' => $sobre->culto_id])
                ->with('error', 'No se puede editar un sobre de un culto cerrado.');
        }

        $validated = $request->validate([
            'persona_id' => 'nullable|exists:personas,id',
            'metodo_pago' => 'required|in:efectivo,transferencia',
            'comprobante_numero' => 'nullable|string|max:100',
            'notas' => 'nullable|string',
            'detalles' => 'required|array',
            'detalles.*.categoria' => 'required|string',
            'detalles.*.monto' => 'required|numeric|min:0',
        ]);

        if ($validated['metodo_pago'] === 'transferencia') {
            $request->validate([
                'comprobante_numero' => 'required|string|max:100',
            ]);
        }

        $totalDeclarado = collect($validated['detalles'])->sum('monto');

        $sobre->update([
            'persona_id' => $validated['persona_id'] ?? null,
            'metodo_pago' => $validated['metodo_pago'],
            'comprobante_numero' => $validated['comprobante_numero'] ?? null,
            'total_declarado' => $totalDeclarado,
            'notas' => $validated['notas'] ?? null,
        ]);

        // Auditoría
        $user = $request->user();
        AuditLog::create([
            'user_id' => $user->id ?? null,
            'user_name' => $user->name ?? ($user->nombre ?? null),
            'user_email' => $user->email ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 255),
            'method' => 'PUT',
            'route' => 'recuento.update',
            'action' => 'Editar Sobre',
            'details' => json_encode([
                'culto_id' => $sobre->culto_id,
                'sobre_id' => $sobre->id,
                'metodo_pago' => $sobre->metodo_pago,
                'comprobante_numero' => $sobre->comprobante_numero,
                'total_declarado' => $sobre->total_declarado,
            ]),
        ]);

        // Eliminar detalles existentes y crear nuevos
        $sobre->detalles()->delete();
        foreach ($validated['detalles'] as $detalle) {
            if ($detalle['monto'] > 0) {
                SobreDetalle::create([
                    'sobre_id' => $sobre->id,
                    'categoria' => $detalle['categoria'],
                    'monto' => $detalle['monto'],
                ]);
            }
        }

        // Recalcular totales
        $this->calculoService->recalcular($sobre->culto);
        // El sobre pudo cambiar de persona: sincronizar todo el culto.
        $this->promesasService->sincronizarCulto($sobre->culto);

        return redirect()->route('recuento.index', ['culto_id' => $sobre->culto_id])
            ->with('success', 'Sobre actualizado correctamente.');
    }

    public function destroy(Sobre $sobre)
    {
        // Solo admin y tesorero pueden eliminar sobres (con null-guard)
        $currentUser = auth()->user();
        if (!$currentUser || !in_array($currentUser->rol, ['admin', 'tesorero'])) {
            return redirect()->route('recuento.index', ['culto_id' => $sobre->culto_id])
                ->with('error', 'No tienes permiso para eliminar sobres.');
        }

        if ($sobre->culto->cerrado) {
            return redirect()->route('recuento.index', ['culto_id' => $sobre->culto_id])
                ->with('error', 'No se puede eliminar un sobre de un culto cerrado.');
        }

        $cultoId = $sobre->culto_id;
        $sobreId = $sobre->id;
        $metodo = $sobre->metodo_pago;
        $comprobante = $sobre->comprobante_numero;
        $totalDeclarado = $sobre->total_declarado;
        // Guardar antes de borrar: se necesita para resincronizar sus compromisos.
        $personaIdEliminada = $sobre->persona_id;
        $sobre->delete();

        // Recalcular totales
        $culto = Culto::find($cultoId);
        if ($culto) {
            $this->calculoService->recalcular($culto);
            $this->promesasService->sincronizarCulto($culto, $personaIdEliminada ?? null);
        }

        // Auditoría
        $user = $currentUser;
        AuditLog::create([
            'user_id' => $user->id ?? null,
            'user_name' => $user->name ?? ($user->nombre ?? null),
            'user_email' => $user->email ?? null,
            'ip_address' => request()->ip(),
            'user_agent' => substr(request()->userAgent() ?? '', 0, 255),
            'method' => 'DELETE',
            'route' => 'recuento.destroy',
            'action' => 'Eliminar Sobre',
            'details' => json_encode([
                'culto_id' => $cultoId,
                'sobre_id' => $sobreId,
                'metodo_pago' => $metodo,
                'comprobante_numero' => $comprobante,
                'total_declarado' => $totalDeclarado,
            ]),
        ]);

        return redirect()->route('recuento.index', ['culto_id' => $cultoId])
            ->with('success', 'Sobre eliminado correctamente.');
    }

    public function storeSuelto(Request $request)
    {
        $validated = $request->validate([
            'culto_id' => 'required|exists:cultos,id',
            'monto' => 'required|numeric|min:0.01',
            'descripcion' => 'nullable|string|max:500',
        ]);

        // Verificar que el culto no esté cerrado
        $culto = Culto::findOrFail($validated['culto_id']);
        if ($culto->cerrado) {
            return redirect()->route('recuento.index', ['culto_id' => $culto->id])
                ->with('error', 'No se puede agregar dinero suelto a un culto cerrado.');
        }

        OfrendaSuelta::create([
            'culto_id' => $validated['culto_id'],
            'monto' => $validated['monto'],
            'metodo_pago' => 'efectivo', // Dinero suelto siempre es efectivo
            'descripcion' => $validated['descripcion'] ?? null,
        ]);

        // Recalcular totales
        $culto = Culto::find($validated['culto_id']);
        if ($culto) {
            $this->calculoService->recalcular($culto);
        }

        return redirect()->route('recuento.index', ['culto_id' => $validated['culto_id']])
            ->with('success', 'Dinero suelto registrado correctamente.');
    }

    // Egresos
    public function storeEgreso(Request $request)
    {
        $validated = $request->validate([
            'culto_id' => 'required|exists:cultos,id',
            'monto' => 'required|numeric|min:0.01',
            'descripcion' => 'nullable|string|max:500',
        ]);

        // Verificar que el culto no esté cerrado
        $culto = Culto::findOrFail($validated['culto_id']);
        if ($culto->cerrado) {
            return redirect()->route('recuento.index', ['culto_id' => $culto->id])
                ->with('error', 'No se puede agregar egresos a un culto cerrado.');
        }

        \App\Models\Egreso::create([
            'culto_id' => $validated['culto_id'],
            'monto' => $validated['monto'],
            'descripcion' => $validated['descripcion'] ?? null,
        ]);

        // Recalcular totales
        $culto = Culto::find($validated['culto_id']);
        if ($culto) {
            $this->calculoService->recalcular($culto);
        }

        return redirect()->route('recuento.index', ['culto_id' => $validated['culto_id']])
            ->with('success', 'Egreso registrado correctamente.');
    }

    public function editEgreso(\App\Models\Egreso $egreso)
    {
        if ($egreso->culto->cerrado) {
            return redirect()->route('recuento.index', ['culto_id' => $egreso->culto_id])
                ->with('error', 'No se puede editar egresos de un culto cerrado.');
        }

        return response()->json($egreso);
    }

    public function updateEgreso(Request $request, \App\Models\Egreso $egreso)
    {
        if ($egreso->culto->cerrado) {
            return redirect()->route('recuento.index', ['culto_id' => $egreso->culto_id])
                ->with('error', 'No se puede editar egresos de un culto cerrado.');
        }

        $validated = $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'descripcion' => 'nullable|string|max:500',
        ]);

        $egreso->update([
            'monto' => $validated['monto'],
            'descripcion' => $validated['descripcion'] ?? null,
        ]);

        // Recalcular totales
        $culto = Culto::find($egreso->culto_id);
        if ($culto) {
            $this->calculoService->recalcular($culto);
        }

        return redirect()->route('recuento.index', ['culto_id' => $egreso->culto_id])
            ->with('success', 'Egreso actualizado correctamente.');
    }

    public function destroyEgreso(\App\Models\Egreso $egreso)
    {
        // Solo admin y tesorero pueden eliminar egresos
        if (!in_array(auth()->user()->rol, ['admin', 'tesorero'])) {
            return redirect()->route('recuento.index', ['culto_id' => $egreso->culto_id])
                ->with('error', 'No tienes permiso para eliminar egresos.');
        }

        if ($egreso->culto->cerrado) {
            return redirect()->route('recuento.index', ['culto_id' => $egreso->culto_id])
                ->with('error', 'No se puede eliminar egresos de un culto cerrado.');
        }

        $cultoId = $egreso->culto_id;
        $egreso->delete();

        // Recalcular totales
        $culto = Culto::find($cultoId);
        if ($culto) {
            $this->calculoService->recalcular($culto);
        }

        return redirect()->route('recuento.index', ['culto_id' => $cultoId])
            ->with('success', 'Egreso eliminado correctamente.');
    }

    public function editSuelto(OfrendaSuelta $suelto)
    {
        if ($suelto->culto->cerrado) {
            return redirect()->route('recuento.index', ['culto_id' => $suelto->culto_id])
                ->with('error', 'No se puede editar dinero suelto de un culto cerrado.');
        }

        return response()->json($suelto);
    }

    public function updateSuelto(Request $request, OfrendaSuelta $suelto)
    {
        if ($suelto->culto->cerrado) {
            return redirect()->route('recuento.index', ['culto_id' => $suelto->culto_id])
                ->with('error', 'No se puede editar dinero suelto de un culto cerrado.');
        }

        $validated = $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'descripcion' => 'nullable|string|max:500',
        ]);

        $suelto->update([
            'monto' => $validated['monto'],
            'descripcion' => $validated['descripcion'] ?? null,
        ]);

        // Recalcular totales
        $culto = Culto::find($suelto->culto_id);
        if ($culto) {
            $this->calculoService->recalcular($culto);
        }

        return redirect()->route('recuento.index', ['culto_id' => $suelto->culto_id])
            ->with('success', 'Dinero suelto actualizado correctamente.');
    }

    public function destroySuelto(OfrendaSuelta $suelto)
    {
        // Solo admin y tesorero pueden eliminar dinero suelto
        if (!in_array(auth()->user()->rol, ['admin', 'tesorero'])) {
            return redirect()->route('recuento.index', ['culto_id' => $suelto->culto_id])
                ->with('error', 'No tienes permiso para eliminar dinero suelto.');
        }

        if ($suelto->culto->cerrado) {
            return redirect()->route('recuento.index', ['culto_id' => $suelto->culto_id])
                ->with('error', 'No se puede eliminar dinero suelto de un culto cerrado.');
        }

        $cultoId = $suelto->culto_id;
        $suelto->delete();

        // Recalcular totales
        $culto = Culto::find($cultoId);
        if ($culto) {
            $this->calculoService->recalcular($culto);
        }

        return redirect()->route('recuento.index', ['culto_id' => $cultoId])
            ->with('success', 'Dinero suelto eliminado correctamente.');
    }

    public function cerrarCulto(Culto $culto)
    {
        if ($culto->cerrado) {
            return redirect()->route('recuento.index')
                ->with('error', 'Este culto ya está cerrado.');
        }

        $culto->update([
            'cerrado' => true,
            'cerrado_at' => now(),
            'cerrado_por' => auth()->id(),
        ]);

        return redirect()->route('recuento.index')
            ->with('success', 'Culto cerrado correctamente. Ahora aparece en la lista de cultos cerrados.');
    }

    public function verCultoCerrado(Culto $culto)
    {
        if (!$culto->cerrado) {
            return response()->json(['error' => 'Este culto no está cerrado'], 400);
        }

        $sobres = $culto->sobres()->with(['persona', 'detalles'])->get();
        $ofrendasSueltas = $culto->ofrendasSueltas;

        return view('recuento.partials.resumen-cerrado', compact('culto', 'sobres', 'ofrendasSueltas'));
    }
}

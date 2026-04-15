<?php

namespace App\Http\Controllers;

use App\Imports\GestionImport;
use App\Models\GestionLog;
use App\Services\GarantisaScraper;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class GestionController extends Controller
{
    public function index()
    {
        return view('gestion.index');
    }

    /**
     * Subir Excel y crear registros pendientes
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $rows = Excel::toArray(new GestionImport, $request->file('file'));
        $data = $rows[0] ?? [];

        if (empty($data)) {
            return response()->json(['error' => 'El archivo está vacío'], 422);
        }

        $batchId = Str::uuid()->toString();
        $created = 0;

        foreach ($data as $row) {
            // Normalizar keys (el heading row puede venir con tildes/mayúsculas)
            $normalized = array_change_key_case(array_map('trim', $row), CASE_LOWER);

            $cedula = $normalized['cedula'] ?? $normalized['cédula'] ?? null;
            $accion = $normalized['accion'] ?? $normalized['acción'] ?? null;
            $resultado = $normalized['resultado'] ?? null;
            $comentario = $normalized['comentario'] ?? null;

            if ($cedula && $accion && $resultado && $comentario) {
                GestionLog::create([
                    'batch_id'   => $batchId,
                    'cedula'     => (string) $cedula,
                    'accion'     => $accion,
                    'resultado'  => $resultado,
                    'comentario' => $comentario,
                    'status'     => 'pending',
                ]);
                $created++;
            }
        }

        if ($created === 0) {
            return response()->json(['error' => 'No se encontraron filas válidas. Verifica las columnas: CÉDULA, ACCIÓN, RESULTADO, COMENTARIO'], 422);
        }

        return response()->json([
            'batch_id' => $batchId,
            'total'    => $created,
            'message'  => "{$created} registros cargados correctamente",
        ]);
    }

    /**
     * Procesar el batch - endpoint SSE para tiempo real
     */
    public function process(string $batchId)
    {
        return response()->stream(function () use ($batchId) {
            // Desactivar time limit para procesos largos
            set_time_limit(0);
            ini_set('output_buffering', 'off');
            ini_set('zlib.output_compression', false);

            // Headers SSE
            echo "retry: 1000\n\n";
            if (ob_get_level()) ob_flush();
            flush();

            $logs = GestionLog::where('batch_id', $batchId)
                ->where('status', 'pending')
                ->get();

            $total = GestionLog::where('batch_id', $batchId)->count();
            $processed = 0;

            if ($logs->isEmpty()) {
                $this->sendSSE('complete', ['message' => 'No hay registros pendientes', 'total' => $total, 'processed' => 0]);
                return;
            }

            // Login
            $scraper = new GarantisaScraper();
            $this->sendSSE('status', ['message' => 'Iniciando sesión en Garantisa...', 'log_session' => $scraper->getLogSessionId()]);

            if (!$scraper->login()) {
                $scraper->writeSummary($total, 0, 0);
                $this->sendSSE('error_msg', ['message' => 'No se pudo iniciar sesión en Garantisa. Revisa el log: ' . basename($scraper->getLogFile())]);
                return;
            }

            $this->sendSSE('status', ['message' => 'Sesión iniciada correctamente']);
            sleep(1);

            foreach ($logs as $log) {
                $processed++;
                $log->update(['status' => 'processing']);

                $this->sendSSE('processing', [
                    'id'        => $log->id,
                    'cedula'    => $log->cedula,
                    'accion'    => $log->accion,
                    'resultado' => $log->resultado,
                    'current'   => $processed,
                    'total'     => $total,
                    'message'   => "Procesando cédula {$log->cedula} ({$processed}/{$total})...",
                ]);

                $result = $scraper->procesarCedula(
                    $log->cedula,
                    $log->accion,
                    $log->resultado,
                    $log->comentario
                );

                if ($result['success']) {
                    $log->update(['status' => 'success']);
                    $this->sendSSE('success', [
                        'id'      => $log->id,
                        'cedula'  => $log->cedula,
                        'current' => $processed,
                        'total'   => $total,
                        'message' => "✓ Cédula {$log->cedula} procesada correctamente",
                    ]);
                } else {
                    $log->update([
                        'status'        => 'failed',
                        'error_message' => $result['error'] ?? 'Error desconocido',
                    ]);
                    $this->sendSSE('failed', [
                        'id'      => $log->id,
                        'cedula'  => $log->cedula,
                        'current' => $processed,
                        'total'   => $total,
                        'error'   => $result['error'] ?? 'Error desconocido',
                        'message' => "✗ Cédula {$log->cedula} falló: " . ($result['error'] ?? 'Error desconocido'),
                    ]);
                }

                // Pausa entre registros para no saturar el servidor
                usleep(800000); // 0.8s
            }

            $successCount = GestionLog::where('batch_id', $batchId)->where('status', 'success')->count();
            $failedCount = GestionLog::where('batch_id', $batchId)->where('status', 'failed')->count();

            $scraper->writeSummary($total, $successCount, $failedCount);

            $this->sendSSE('complete', [
                'total'     => $total,
                'success'   => $successCount,
                'failed'    => $failedCount,
                'log_file'  => basename($scraper->getLogFile()),
                'message'   => "Proceso completado: {$successCount} exitosos, {$failedCount} fallidos de {$total} total. Log: " . basename($scraper->getLogFile()),
            ]);
        }, 200, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection'    => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Obtener estado actual del batch
     */
    public function status(string $batchId)
    {
        $logs = GestionLog::where('batch_id', $batchId)->get();

        return response()->json([
            'total'      => $logs->count(),
            'pending'    => $logs->where('status', 'pending')->count(),
            'processing' => $logs->where('status', 'processing')->count(),
            'success'    => $logs->where('status', 'success')->count(),
            'failed'     => $logs->where('status', 'failed')->count(),
            'logs'       => $logs,
        ]);
    }

    /**
     * Enviar evento SSE
     */
    private function sendSSE(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    }

    /**
     * Listar archivos de log del scraper
     */
    public function listLogs()
    {
        $logPath = storage_path('logs');
        $files = glob($logPath . '/scraper_*.log');
        $logs = [];

        foreach ($files as $file) {
            $logs[] = [
                'name' => basename($file),
                'size' => round(filesize($file) / 1024, 1) . ' KB',
                'date' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }

        // Ordenar por fecha descendente
        usort($logs, fn($a, $b) => strcmp($b['date'], $a['date']));

        return response()->json($logs);
    }

    /**
     * Ver contenido de un log específico
     */
    public function viewLog(string $filename)
    {
        // Sanitizar filename
        $filename = basename($filename);
        if (!str_starts_with($filename, 'scraper_') || !str_ends_with($filename, '.log')) {
            abort(404);
        }

        $path = storage_path("logs/{$filename}");
        if (!file_exists($path)) {
            abort(404);
        }

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /**
     * Listar historial de batches con resumen
     */
    public function batches()
    {
        $batches = GestionLog::selectRaw("
                batch_id,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                MIN(created_at) as started_at,
                MAX(updated_at) as finished_at
            ")
            ->groupBy('batch_id')
            ->orderByDesc('started_at')
            ->get();

        return response()->json($batches);
    }

    /**
     * Detalle de un batch específico (registros fallidos)
     */
    public function batchDetail(string $batchId)
    {
        $logs = GestionLog::where('batch_id', $batchId)
            ->orderByRaw("CASE status WHEN 'failed' THEN 1 WHEN 'pending' THEN 2 WHEN 'processing' THEN 3 WHEN 'success' THEN 4 END")
            ->get(['id', 'cedula', 'accion', 'resultado', 'comentario', 'status', 'error_message', 'updated_at']);

        return response()->json($logs);
    }

    /**
     * Reintentar registros fallidos de un batch
     */
    public function retryFailed(string $batchId)
    {
        $failedCount = GestionLog::where('batch_id', $batchId)
            ->where('status', 'failed')
            ->count();

        if ($failedCount === 0) {
            return response()->json(['error' => 'No hay registros fallidos para reintentar'], 422);
        }

        // Resetear fallidos a pending
        GestionLog::where('batch_id', $batchId)
            ->where('status', 'failed')
            ->update(['status' => 'pending', 'error_message' => null]);

        return response()->json([
            'batch_id' => $batchId,
            'total'    => $failedCount,
            'message'  => "{$failedCount} registros fallidos puestos en cola para reintento",
        ]);
    }

    /**
     * Continuar procesando registros pendientes (y stuck en processing) de un batch
     */
    public function continueBatch(string $batchId)
    {
        // Resetear los que quedaron en 'processing' (stuck) a 'pending'
        GestionLog::where('batch_id', $batchId)
            ->where('status', 'processing')
            ->update(['status' => 'pending']);

        $pendingCount = GestionLog::where('batch_id', $batchId)
            ->where('status', 'pending')
            ->count();

        if ($pendingCount === 0) {
            return response()->json(['error' => 'No hay registros pendientes para continuar'], 422);
        }

        return response()->json([
            'batch_id' => $batchId,
            'total'    => $pendingCount,
            'message'  => "{$pendingCount} registros pendientes listos para procesar",
        ]);
    }
}

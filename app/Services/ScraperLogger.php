<?php

namespace App\Services;

class ScraperLogger
{
    private string $logFile;
    private string $sessionId;
    private float $startTime;

    public function __construct()
    {
        $this->sessionId = date('Y-m-d_H-i-s') . '_' . substr(uniqid(), -5);
        $this->logFile = storage_path("logs/scraper_{$this->sessionId}.log");
        $this->startTime = microtime(true);

        $this->write("========================================");
        $this->write("SESIÓN DE SCRAPING: {$this->sessionId}");
        $this->write("FECHA: " . date('Y-m-d H:i:s'));
        $this->write("PHP: " . PHP_VERSION);
        $this->write("========================================\n");
    }

    public function step(string $step, string $message): void
    {
        $elapsed = round(microtime(true) - $this->startTime, 2);
        $this->write("[{$elapsed}s] [{$step}] {$message}");
    }

    public function request(string $method, string $url, array $options = []): void
    {
        $this->write("\n--- REQUEST ---");
        $this->write("  {$method} {$url}");

        if (!empty($options['headers'])) {
            $this->write("  Headers: " . json_encode($options['headers'], JSON_PRETTY_PRINT));
        }

        if (!empty($options['form_params'])) {
            $params = $options['form_params'];
            // Truncar ViewState para que no llene el log
            foreach ($params as $key => &$val) {
                if (is_string($val) && strlen($val) > 200 && in_array($key, ['__VIEWSTATE', '__EVENTVALIDATION'])) {
                    $val = substr($val, 0, 80) . '...[TRUNCADO ' . strlen($val) . ' chars]';
                }
            }
            $this->write("  Form Params: " . json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        if (!empty($options['json'])) {
            $this->write("  JSON Body: " . json_encode($options['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    public function response(int $statusCode, string $body, array $headers = []): void
    {
        $this->write("--- RESPONSE ---");
        $this->write("  Status: {$statusCode}");
        $this->write("  Body Length: " . strlen($body) . " bytes");

        if (!empty($headers)) {
            $relevantHeaders = [];
            foreach (['Content-Type', 'Location', 'Set-Cookie', 'X-AspNet-Version'] as $h) {
                $lower = strtolower($h);
                foreach ($headers as $key => $val) {
                    if (strtolower($key) === $lower) {
                        $relevantHeaders[$key] = is_array($val) ? implode('; ', $val) : $val;
                    }
                }
            }
            if ($relevantHeaders) {
                $this->write("  Headers relevantes: " . json_encode($relevantHeaders, JSON_PRETTY_PRINT));
            }
        }

        // Guardar snippet del body (primeros 2000 chars)
        $snippet = substr($body, 0, 2000);
        if (strlen($body) > 2000) $snippet .= "\n...[TRUNCADO]";
        $this->write("  Body Preview:\n{$snippet}");
        $this->write("--- END RESPONSE ---\n");
    }

    public function tokens(array $tokens): void
    {
        $this->write("  ASP.NET Tokens encontrados:");
        foreach ($tokens as $name => $value) {
            $display = strlen($value) > 80 ? substr($value, 0, 80) . '...[' . strlen($value) . ' chars]' : $value;
            $status = empty($value) ? '❌ VACÍO' : '✓';
            $this->write("    {$status} {$name}: {$display}");
        }
    }

    public function cookies(array $cookies): void
    {
        $this->write("  Cookies activas:");
        foreach ($cookies as $name => $value) {
            $this->write("    {$name}: {$value}");
        }
    }

    public function error(string $context, \Throwable $e): void
    {
        $this->write("\n!!! ERROR en {$context} !!!");
        $this->write("  Tipo: " . get_class($e));
        $this->write("  Mensaje: " . $e->getMessage());
        $this->write("  Archivo: " . $e->getFile() . ':' . $e->getLine());
        $this->write("  Trace:\n" . $e->getTraceAsString());
        $this->write("!!! FIN ERROR !!!\n");
    }

    public function separator(string $title): void
    {
        $this->write("\n" . str_repeat('=', 50));
        $this->write("  {$title}");
        $this->write(str_repeat('=', 50));
    }

    public function summary(int $total, int $success, int $failed): void
    {
        $elapsed = round(microtime(true) - $this->startTime, 2);
        $this->write("\n========================================");
        $this->write("RESUMEN DE SESIÓN");
        $this->write("  Tiempo total: {$elapsed}s");
        $this->write("  Total procesados: {$total}");
        $this->write("  Exitosos: {$success}");
        $this->write("  Fallidos: {$failed}");
        $this->write("  Archivo de log: {$this->logFile}");
        $this->write("========================================");
    }

    public function getLogFile(): string
    {
        return $this->logFile;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    private function write(string $line): void
    {
        file_put_contents($this->logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

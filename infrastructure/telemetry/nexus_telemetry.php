<?php
/**
 * Nexus Telemetry - Sistema de Rastreabilidade e Observabilidade
 * 
 * Coleta métricas de performance, erros e fluxos de negócio para análise de IA
 * Padrão: OpenTelemetry-compatible com exportação para JSONL
 * 
 * @package BodyHarmony\Nexus
 * @version 3.2.1
 */

namespace BodyHarmony\Nexus;

class Telemetry {
    private static ?Telemetry $instance = null;
    private string $logPath;
    private string $sessionId;
    private array $context = [];
    
    private function __construct() {
        $this->logPath = dirname(__DIR__) . '/telemetry/nexus_events.jsonl';
        $this->sessionId = session_id() ?: bin2hex(random_bytes(16));
        $this->context = [
            'service' => 'nexus-core',
            'version' => '3.2.1',
            'environment' => getenv('APP_ENV') ?: 'production',
            'session_id' => $this->sessionId,
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8)),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'ip_hash' => $this->hashIP($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            'timestamp_start' => microtime(true)
        ];
        
        // Garante diretório de telemetria
        if (!is_dir(dirname($this->logPath))) {
            mkdir(dirname($this->logPath), 0755, true);
        }
    }
    
    public static function getInstance(): Telemetry {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Registra evento de telemetria
     * 
     * @param string $event Nome do evento (ex: api.request, db.query, auth.login)
     * @param array $data Dados adicionais do evento
     * @param string $level Nível de severidade (debug, info, warn, error, critical)
     * @return bool Sucesso da operação
     */
    public function track(string $event, array $data = [], string $level = 'info'): bool {
        $timestamp = microtime(true);
        $duration = $timestamp - ($this->context['timestamp_start'] ?? $timestamp);
        
        $entry = array_merge($this->context, [
            'event' => $event,
            'level' => $level,
            'timestamp' => date('c'),
            'timestamp_unix' => $timestamp,
            'duration_ms' => round($duration * 1000, 2),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'data' => $this->sanitizeData($data)
        ]);
        
        // Remove dados sensíveis
        unset($entry['data']['password'], $entry['data']['token'], $entry['data']['api_key']);
        
        $jsonLine = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        return file_put_contents($this->logPath, $jsonLine . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }
    
    /**
     * Inicia span de performance para rastreamento distribuído
     */
    public function startSpan(string $spanName): string {
        $spanId = bin2hex(random_bytes(8));
        $this->context['active_span'] = $spanId;
        $this->context['span_name'] = $spanName;
        $this->context['span_start'] = microtime(true);
        
        $this->track('span.start', ['span_name' => $spanName, 'span_id' => $spanId], 'debug');
        
        return $spanId;
    }
    
    /**
     * Finaliza span de performance
     */
    public function endSpan(string $spanId, array $metadata = []): void {
        if (($this->context['active_span'] ?? '') !== $spanId) {
            return;
        }
        
        $duration = microtime(true) - ($this->context['span_start'] ?? microtime(true));
        
        $this->track('span.end', [
            'span_id' => $spanId,
            'span_name' => $this->context['span_name'] ?? 'unknown',
            'duration_ms' => round($duration * 1000, 2),
            'metadata' => $metadata
        ], 'debug');
        
        unset($this->context['active_span'], $this->context['span_name'], $this->context['span_start']);
    }
    
    /**
     * Registra erro com stack trace e contexto
     */
    public function trackError(\Throwable $exception, array $context = []): void {
        $this->track('error.exception', [
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $this->sanitizeTrace($exception->getTrace()),
            'context' => $context
        ], 'error');
    }
    
    /**
     * Registra métrica de negócio (KPI)
     */
    public function trackMetric(string $metricName, float $value, array $dimensions = []): void {
        $this->track('metric.kpi', [
            'metric_name' => $metricName,
            'value' => $value,
            'unit' => $dimensions['unit'] ?? 'count',
            'dimensions' => $dimensions
        ], 'info');
    }
    
    /**
     * Hash de IP para privacidade (LGPD/GDPR)
     */
    private function hashIP(string $ip): string {
        return hash('sha256', $ip . getenv('TELEMETRY_SALT') ?: 'default_salt_change_in_prod');
    }
    
    /**
     * Sanitiza dados sensíveis
     */
    private function sanitizeData(array $data): array {
        $sensitive = ['password', 'passwd', 'pwd', 'token', 'api_key', 'apikey', 'secret', 'credit_card', 'cpf', 'ssn'];
        
        foreach ($data as $key => &$value) {
            if (in_array(strtolower($key), $sensitive)) {
                $value = '[REDACTED]';
            } elseif (is_array($value)) {
                $value = $this->sanitizeData($value);
            }
        }
        
        return $data;
    }
    
    /**
     * Sanitiza stack trace removendo caminhos absolutos
     */
    private function sanitizeTrace(array $trace): array {
        return array_map(function($frame) {
            if (isset($frame['file'])) {
                $frame['file'] = preg_replace('#/var/www/html/|/app/|/workspace/#', '', $frame['file']);
            }
            return $frame;
        }, array_slice($trace, 0, 10)); // Limita a 10 frames
    }
    
    /**
     * Exporta telemetria para formato compatível com ELK/Datadog
     */
    public function exportToECS(): array {
        $events = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_ARRAYS) ?: [];
        
        return array_map(function($line) {
            $event = json_decode($line, true);
            
            // Converte para ECS (Elastic Common Schema)
            return [
                '@timestamp' => $event['timestamp'],
                'log.level' => $event['level'],
                'message' => $event['event'],
                'event' => [
                    'action' => $event['event'],
                    'category' => explode('.', $event['event'])[0] ?? 'unknown',
                    'dataset' => 'nexus.telemetry'
                ],
                'observer' => [
                    'hostname' => gethostname(),
                    'type' => 'application',
                    'version' => $event['version']
                ],
                'host' => [
                    'ip' => [$event['ip_hash']],
                ],
                'agent' => [
                    'ephemeral_id' => $event['session_id'],
                    'id' => $event['request_id'],
                    'type' => 'nexus',
                    'version' => $event['version']
                ],
                'ecs' => ['version' => '1.12.0'],
                '@timestamp' => $event['timestamp'],
                'event' => array_merge($event, ['original' => $line])
            ];
        }, $events);
    }
}

// Helper functions globais
if (!function_exists('nexus_track')) {
    function nexus_track(string $event, array $data = [], string $level = 'info'): bool {
        return Telemetry::getInstance()->track($event, $data, $level);
    }
}

if (!function_exists('nexus_span')) {
    function nexus_span(string $name): \BodyHarmony\Nexus\SpanGuard {
        return new \BodyHarmony\Nexus\SpanGuard($name);
    }
}

/**
 * Guard para spans automáticos com RAII
 */
class SpanGuard {
    private string $spanId;
    
    public function __construct(string $spanName) {
        $this->spanId = Telemetry::getInstance()->startSpan($spanName);
    }
    
    public function __destruct() {
        Telemetry::getInstance()->endSpan($this->spanId);
    }
    
    public function setMetadata(array $metadata): void {
        // Metadata será incluída no endSpan
    }
}

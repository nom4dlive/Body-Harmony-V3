<?php
/**
 * Nexus Telemetry API - Endpoint para Dashboard
 * 
 * Fornece dados de telemetria em tempo real para o dashboard web
 * Suporta CORS para acesso do frontend
 * 
 * @package BodyHarmony\\Nexus
 * @version 3.2.1
 */

namespace BodyHarmony\Nexus;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Request-ID');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/nexus_telemetry.php';

use BodyHarmony\Nexus\Telemetry;

try {
    $telemetry = Telemetry::getInstance();
    $action = $_GET['action'] ?? 'overview';
    
    switch ($action) {
        case 'overview':
            echo json_encode(getOverview($telemetry), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
            
        case 'metrics':
            echo json_encode(getMetrics($telemetry), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
            
        case 'logs':
            $limit = min((int)($_GET['limit'] ?? 50), 500);
            $level = $_GET['level'] ?? null;
            echo json_encode(getLogs($telemetry, $limit, $level), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
            
        case 'performance':
            $hours = min((int)($_GET['hours'] ?? 24), 168);
            echo json_encode(getPerformanceData($telemetry, $hours), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
            
        case 'errors':
            $limit = min((int)($_GET['limit'] ?? 20), 100);
            echo json_encode(getErrors($telemetry, $limit), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
            
        case 'health':
            echo json_encode(getHealthStatus($telemetry), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Ação inválida. Use: overview, metrics, logs, performance, errors, health',
                'timestamp' => date('c')
            ]);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno no servidor de telemetria',
        'message' => $e->getMessage(),
        'timestamp' => date('c')
    ]);
}

/**
 * Obtém visão geral das métricas
 */
function getOverview(Telemetry $telemetry): array {
    $logPath = dirname(__DIR__) . '/telemetry/nexus_events.jsonl';
    
    if (!file_exists($logPath)) {
        return generateMockOverview();
    }
    
    $events = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_ARRAYS) ?: [];
    $totalEvents = count($events);
    
    if ($totalEvents === 0) {
        return generateMockOverview();
    }
    
    // Analisa últimos eventos
    $lastHour = time() - 3600;
    $last24h = time() - 86400;
    $last7d = time() - 604800;
    
    $counts = ['hour' => 0, 'day' => 0, 'week' => 0];
    $errors = ['hour' => 0, 'day' => 0, 'week' => 0];
    $durations = [];
    $memory = [];
    
    foreach ($events as $line) {
        $event = json_decode($line, true);
        if (!$event) continue;
        
        $ts = (int)($event['timestamp_unix'] ?? 0);
        
        if ($ts > $lastHour) {
            $counts['hour']++;
            if ($event['level'] === 'error' || $event['level'] === 'critical') {
                $errors['hour']++;
            }
        }
        
        if ($ts > $last24h) {
            $counts['day']++;
            if ($event['level'] === 'error' || $event['level'] === 'critical') {
                $errors['day']++;
            }
        }
        
        if ($ts > $last7d) {
            $counts['week']++;
            if ($event['level'] === 'error' || $event['level'] === 'critical') {
                $errors['week']++;
            }
        }
        
        if (isset($event['duration_ms']) && is_numeric($event['duration_ms'])) {
            $durations[] = $event['duration_ms'];
        }
        
        if (isset($event['memory_mb']) && is_numeric($event['memory_mb'])) {
            $memory[] = $event['memory_mb'];
        }
    }
    
    // Calcula médias e percentis
    $avgDuration = count($durations) > 0 ? array_sum($durations) / count($durations) : 0;
    $p95Duration = count($durations) > 0 ? percentile($durations, 95) : 0;
    $avgMemory = count($memory) > 0 ? array_sum($memory) / count($memory) : 0;
    
    // Taxa de erro
    $errorRate = $counts['day'] > 0 ? ($errors['day'] / $counts['day']) * 100 : 0;
    
    return [
        'success' => true,
        'timestamp' => date('c'),
        'data' => [
            'total_events' => $totalEvents,
            'events_last_hour' => $counts['hour'],
            'events_last_24h' => $counts['day'],
            'events_last_7d' => $counts['week'],
            'errors_last_24h' => $errors['day'],
            'error_rate_percent' => round($errorRate, 2),
            'avg_duration_ms' => round($avgDuration, 2),
            'p95_duration_ms' => round($p95Duration, 2),
            'avg_memory_mb' => round($avgMemory, 2),
            'health_score' => calculateHealthScore($errorRate, $p95Duration)
        ]
    ];
}

/**
 * Obtém métricas detalhadas por categoria
 */
function getMetrics(Telemetry $telemetry): array {
    $logPath = dirname(__DIR__) . '/telemetry/nexus_events.jsonl';
    
    if (!file_exists($logPath)) {
        return generateMockMetrics();
    }
    
    $events = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_ARRAYS) ?: [];
    
    if (count($events) === 0) {
        return generateMockMetrics();
    }
    
    $categories = [];
    $last24h = time() - 86400;
    
    foreach ($events as $line) {
        $event = json_decode($line, true);
        if (!$event || !isset($event['timestamp_unix'])) continue;
        
        if ((int)$event['timestamp_unix'] < $last24h) continue;
        
        $category = explode('.', $event['event'])[0] ?? 'unknown';
        
        if (!isset($categories[$category])) {
            $categories[$category] = [
                'count' => 0,
                'errors' => 0,
                'total_duration' => 0,
                'durations' => []
            ];
        }
        
        $categories[$category]['count']++;
        
        if ($event['level'] === 'error' || $event['level'] === 'critical') {
            $categories[$category]['errors']++;
        }
        
        if (isset($event['duration_ms']) && is_numeric($event['duration_ms'])) {
            $categories[$category]['total_duration'] += $event['duration_ms'];
            $categories[$category]['durations'][] = $event['duration_ms'];
        }
    }
    
    $result = [];
    foreach ($categories as $name => $data) {
        $result[$name] = [
            'total_events' => $data['count'],
            'errors' => $data['errors'],
            'error_rate' => $data['count'] > 0 ? round(($data['errors'] / $data['count']) * 100, 2) : 0,
            'avg_duration_ms' => $data['count'] > 0 ? round($data['total_duration'] / $data['count'], 2) : 0,
            'p95_duration_ms' => count($data['durations']) > 0 ? round(percentile($data['durations'], 95), 2) : 0
        ];
    }
    
    return [
        'success' => true,
        'timestamp' => date('c'),
        'data' => $result
    ];
}

/**
 * Obtém logs recentes
 */
function getLogs(Telemetry $telemetry, int $limit = 50, ?string $level = null): array {
    $logPath = dirname(__DIR__) . '/telemetry/nexus_events.jsonl';
    
    if (!file_exists($logPath)) {
        return ['success' => true, 'timestamp' => date('c'), 'data' => [], 'total' => 0];
    }
    
    $events = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_ARRAYS) ?: [];
    $events = array_reverse($events); // Mais recentes primeiro
    
    $filtered = [];
    foreach ($events as $line) {
        $event = json_decode($line, true);
        if (!$event) continue;
        
        if ($level && $event['level'] !== $level) continue;
        
        $filtered[] = [
            'timestamp' => $event['timestamp'] ?? date('c'),
            'level' => $event['level'] ?? 'info',
            'event' => $event['event'] ?? 'unknown',
            'message' => $event['data']['message'] ?? '',
            'duration_ms' => $event['duration_ms'] ?? 0,
            'span_id' => $event['data']['span_id'] ?? null
        ];
        
        if (count($filtered) >= $limit) break;
    }
    
    return [
        'success' => true,
        'timestamp' => date('c'),
        'data' => $filtered,
        'total' => count($filtered)
    ];
}

/**
 * Obtém dados de performance para gráfico
 */
function getPerformanceData(Telemetry $telemetry, int $hours = 24): array {
    $logPath = dirname(__DIR__) . '/telemetry/nexus_events.jsonl';
    
    if (!file_exists($logPath)) {
        return generateMockPerformance($hours);
    }
    
    $events = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_ARRAYS) ?: [];
    $startTime = time() - ($hours * 3600);
    
    // Agrupa por hora
    $buckets = [];
    for ($i = 0; $i < $hours; $i++) {
        $bucketTime = $startTime + ($i * 3600);
        $bucketKey = date('Y-m-d H:00', $bucketTime);
        $buckets[$bucketKey] = [
            'timestamp' => $bucketTime,
            'count' => 0,
            'total_duration' => 0,
            'durations' => [],
            'errors' => 0
        ];
    }
    
    foreach ($events as $line) {
        $event = json_decode($line, true);
        if (!$event || !isset($event['timestamp_unix'])) continue;
        
        $ts = (int)$event['timestamp_unix'];
        if ($ts < $startTime) continue;
        
        $bucketKey = date('Y-m-d H:00', $ts);
        if (!isset($buckets[$bucketKey])) continue;
        
        $buckets[$bucketKey]['count']++;
        
        if (isset($event['duration_ms']) && is_numeric($event['duration_ms'])) {
            $buckets[$bucketKey]['total_duration'] += $event['duration_ms'];
            $buckets[$bucketKey]['durations'][] = $event['duration_ms'];
        }
        
        if ($event['level'] === 'error' || $event['level'] === 'critical') {
            $buckets[$bucketKey]['errors']++;
        }
    }
    
    $series = [];
    foreach ($buckets as $bucket) {
        $avgDuration = $bucket['count'] > 0 ? $bucket['total_duration'] / $bucket['count'] : 0;
        $p95Duration = count($bucket['durations']) > 0 ? percentile($bucket['durations'], 95) : 0;
        
        $series[] = [
            'timestamp' => date('c', $bucket['timestamp']),
            'label' => date('H:i', $bucket['timestamp']),
            'requests' => $bucket['count'],
            'avg_duration_ms' => round($avgDuration, 2),
            'p95_duration_ms' => round($p95Duration, 2),
            'errors' => $bucket['errors']
        ];
    }
    
    return [
        'success' => true,
        'timestamp' => date('c'),
        'data' => $series,
        'period_hours' => $hours
    ];
}

/**
 * Obtém lista de erros recentes
 */
function getErrors(Telemetry $telemetry, int $limit = 20): array {
    $logPath = dirname(__DIR__) . '/telemetry/nexus_events.jsonl';
    
    if (!file_exists($logPath)) {
        return ['success' => true, 'timestamp' => date('c'), 'data' => [], 'total' => 0];
    }
    
    $events = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_ARRAYS) ?: [];
    $events = array_reverse($events);
    
    $errors = [];
    foreach ($events as $line) {
        $event = json_decode($line, true);
        if (!$event) continue;
        
        if ($event['level'] !== 'error' && $event['level'] !== 'critical') continue;
        
        $errors[] = [
            'timestamp' => $event['timestamp'] ?? date('c'),
            'level' => $event['level'],
            'event' => $event['event'],
            'message' => $event['data']['message'] ?? 'Sem mensagem',
            'exception' => $event['data']['exception_class'] ?? null,
            'file' => $event['data']['file'] ?? null,
            'line' => $event['data']['line'] ?? null,
            'trace_preview' => isset($event['data']['trace'][0]) ? 
                ($event['data']['trace'][0]['function'] ?? '') : null
        ];
        
        if (count($errors) >= $limit) break;
    }
    
    return [
        'success' => true,
        'timestamp' => date('c'),
        'data' => $errors,
        'total' => count($errors)
    ];
}

/**
 * Verifica status de saúde do sistema
 */
function getHealthStatus(Telemetry $telemetry): array {
    $logPath = dirname(__DIR__) . '/telemetry/nexus_events.jsonl';
    $last24h = time() - 86400;
    
    $status = [
        'healthy' => true,
        'issues' => [],
        'warnings' => []
    ];
    
    if (!file_exists($logPath)) {
        $status['warnings'][] = 'Arquivo de logs não encontrado - usando dados mock';
        return [
            'success' => true,
            'timestamp' => date('c'),
            'data' => $status
        ];
    }
    
    $events = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_ARRAYS) ?: [];
    $recentEvents = array_filter($events, function($line) use ($last24h) {
        $event = json_decode($line, true);
        return $event && isset($event['timestamp_unix']) && (int)$event['timestamp_unix'] > $last24h;
    });
    
    $totalRecent = count($recentEvents);
    $errorCount = 0;
    $slowRequests = 0;
    
    foreach ($recentEvents as $line) {
        $event = json_decode($line, true);
        if (!$event) continue;
        
        if ($event['level'] === 'error' || $event['level'] === 'critical') {
            $errorCount++;
        }
        
        if (isset($event['duration_ms']) && $event['duration_ms'] > 1000) {
            $slowRequests++;
        }
    }
    
    // Verificações de saúde
    $errorRate = $totalRecent > 0 ? ($errorCount / $totalRecent) * 100 : 0;
    
    if ($errorRate > 5) {
        $status['healthy'] = false;
        $status['issues'][] = "Taxa de erro elevada: " . round($errorRate, 2) . "%";
    } elseif ($errorRate > 1) {
        $status['warnings'][] = "Taxa de erro acima do ideal: " . round($errorRate, 2) . "%";
    }
    
    if ($slowRequests > 10) {
        $status['warnings'][] = "$slowRequests requisições lentas (>1s) nas últimas 24h";
    }
    
    return [
        'success' => true,
        'timestamp' => date('c'),
        'data' => $status
    ];
}

/**
 * Gera dados mock quando não há logs reais
 */
function generateMockOverview(): array {
    return [
        'success' => true,
        'timestamp' => date('c'),
        'data' => [
            'total_events' => 0,
            'events_last_hour' => 0,
            'events_last_24h' => 0,
            'events_last_7d' => 0,
            'errors_last_24h' => 0,
            'error_rate_percent' => 0,
            'avg_duration_ms' => 0,
            'p95_duration_ms' => 0,
            'avg_memory_mb' => 0,
            'health_score' => 100,
            '_mock' => true,
            '_message' => 'Nenhum dado de telemetria disponível ainda. Execute alguma ação na aplicação.'
        ]
    ];
}

function generateMockMetrics(): array {
    return [
        'success' => true,
        'timestamp' => date('c'),
        'data' => [
            '_mock' => true,
            '_message' => 'Nenhuma métrica disponível ainda.'
        ]
    ];
}

function generateMockPerformance(int $hours): array {
    $series = [];
    $now = time();
    
    for ($i = $hours - 1; $i >= 0; $i--) {
        $ts = $now - ($i * 3600);
        $series[] = [
            'timestamp' => date('c', $ts),
            'label' => date('H:i', $ts),
            'requests' => 0,
            'avg_duration_ms' => 0,
            'p95_duration_ms' => 0,
            'errors' => 0
        ];
    }
    
    return [
        'success' => true,
        'timestamp' => date('c'),
        'data' => $series,
        'period_hours' => $hours,
        '_mock' => true
    ];
}

/**
 * Calcula percentil
 */
function percentile(array $data, float $percentile): float {
    if (empty($data)) return 0;
    
    sort($data);
    $index = ($percentile / 100) * (count($data) - 1);
    $lower = floor($index);
    $upper = ceil($index);
    
    if ($lower === $upper) {
        return $data[$lower];
    }
    
    $weight = $index - $lower;
    return $data[$lower] * (1 - $weight) + $data[$upper] * $weight;
}

/**
 * Calcula score de saúde (0-100)
 */
function calculateHealthScore(float $errorRate, float $p95Duration): int {
    $score = 100;
    
    // Penaliza erro rate
    if ($errorRate > 10) {
        $score -= 40;
    } elseif ($errorRate > 5) {
        $score -= 25;
    } elseif ($errorRate > 1) {
        $score -= 10;
    }
    
    // Penaliza latência alta
    if ($p95Duration > 2000) {
        $score -= 30;
    } elseif ($p95Duration > 1000) {
        $score -= 20;
    } elseif ($p95Duration > 500) {
        $score -= 10;
    }
    
    return max(0, min(100, $score));
}

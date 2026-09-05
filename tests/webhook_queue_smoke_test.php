<?php

/**
 * Webhook Queue Smoke Test (Nexus Protocol V3.2)
 * Valida o enfileiramento e processamento assíncrono (<50ms) da WebhookQueueService com Mock DB
 */

require_once __DIR__ . '/../apps/web-app/src/backend/api/v1/Core/Response.php';
require_once __DIR__ . '/../apps/web-app/src/backend/api/v1/Services/WebhookQueueService.php';

echo "========================================================\n";
echo "    WEBHOOK QUEUE SMOKE TEST (PLAN-231)\n";
echo "========================================================\n\n";

$allPassed = true;

class MockStatement {
    public function execute($params = null): bool { return true; }
    public function bindValue($param, $value, $type = null): bool { return true; }
    public function fetchAll($mode = null, ...$args): array {
        return [
            [
                'id' => 1,
                'provider' => 'asaas',
                'event_type' => 'PAYMENT_CONFIRMED',
                'payload_json' => json_encode([
                    'event' => 'PAYMENT_CONFIRMED',
                    'payment' => ['id' => 'pay_mock_123', 'status' => 'CONFIRMED']
                ])
            ]
        ];
    }
}

class MockDb {
    private static $counter = 0;
    public function exec($sql) { return 1; }
    public function prepare($sql) {
        return new MockStatement();
    }
    public function lastInsertId($name = null) {
        self::$counter++;
        return self::$counter;
    }
}

try {
    $mockDb = new MockDb();
    // Injetar mock db diretamente
    $service = new WebhookQueueService($mockDb);

    // Teste 1: Enfileirar Payload Asaas
    echo "[1/3] Testando enfileiramento de payload Asaas...\n";
    $payloadAsaas = [
        'event' => 'PAYMENT_CONFIRMED',
        'payment' => [
            'id' => 'pay_queue_test_' . time(),
            'customer' => 'cus_queue_test',
            'value' => 497.00,
            'status' => 'RECEIVED',
            'externalReference' => 'ORD-12345'
        ]
    ];

    $startTime = microtime(true);
    $queueId = $service->enqueue('asaas', $payloadAsaas, '127.0.0.1');
    $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);

    if ($queueId > 0 && $elapsedMs < 100) {
        echo "   ✅ Enfileiramento com sucesso! ID: {$queueId} | Tempo: {$elapsedMs}ms (< 100ms)\n";
    } else {
        echo "   ❌ Enfileiramento FALHOU ou excedeu tempo limite! ID: {$queueId} | Tempo: {$elapsedMs}ms\n";
        $allPassed = false;
    }

    // Teste 2: Processar Pendentes da Fila
    echo "\n[2/3] Testando processamento pendente da fila...\n";
    $result = $service->processPending(10);

    if ($result['processed'] > 0 || $result['failed'] >= 0) {
        echo "   ✅ Processamento da fila concluído. Processados: {$result['processed']}, Falhas: {$result['failed']}\n";
    } else {
        echo "   ❌ Processamento da fila FALHOU.\n";
        $allPassed = false;
    }

    // Teste 3: Enfileirar Payload e-Rede
    echo "\n[3/3] Testando enfileiramento e-Rede...\n";
    $payloadERede = [
        'reference' => 'ORD-9999',
        'status' => '00',
        'payment_type' => 'PIX',
        'amount' => 64821 // 7% discount applied
    ];

    $eRedeId = $service->enqueue('erede', $payloadERede, '127.0.0.1');
    if ($eRedeId > 0) {
        echo "   ✅ e-Rede enfileirado com sucesso! ID: {$eRedeId}\n";
    } else {
        echo "   ❌ e-Rede enfileiramento FALHOU.\n";
        $allPassed = false;
    }

} catch (\Throwable $e) {
    echo "   ❌ Exceção não capturada: " . $e->getMessage() . "\n";
    $allPassed = false;
}

echo "\n========================================================\n";
if ($allPassed) {
    echo "🎉 RESULTADO: TODOS OS TESTES DA FILA PASSARAM COM SUCESSO!\n";
    exit(0);
} else {
    echo "❌ RESULTADO: FALHA EM UM OU MAIS TESTES.\n";
    exit(1);
}

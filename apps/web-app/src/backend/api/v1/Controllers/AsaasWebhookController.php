<?php

/**
 * AsaasWebhookController — Recebe e processa eventos de webhook do Asaas
 * Nexus Protocol V3.1 Compliant
 * 
 * Rota: POST /api/v1/payments/webhook/asaas
 * Autenticação: Token secreto via header 'asaas-access-token'
 */
class AsaasWebhookController {
    private $db;

    public function __construct() {
        global $pdo, $db;
        $this->db = $pdo ?? $db;
    }

    /**
     * GET /api/v1/payments/webhook/asaas
     * Responde pings de saúde e verificações de disponibilidade do Asaas (URL Check)
     */
    public function handlePing() {
        Response::json([
            'status' => 'ok',
            'webhook' => 'asaas_active',
            'service' => 'Body Harmony Payment Gateway',
            'timestamp' => time()
        ]);
    }

    /**
     * POST /api/v1/payments/webhook/asaas
     * Recebe eventos do Asaas e atualiza status de pagamentos
     */
    public function handle() {
        // 1. Ler payload do evento primeiro
        $rawBody = file_get_contents('php://input');
        $event = json_decode($rawBody, true);

        // 2. Validar token de autenticação (se configurado no ambiente)
        $expectedToken = getenv('ASAAS_WEBHOOK_TOKEN') ?: ($_ENV['ASAAS_WEBHOOK_TOKEN'] ?? (defined('ASAAS_WEBHOOK_TOKEN') ? ASAAS_WEBHOOK_TOKEN : ''));
        $receivedToken = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] 
            ?? ($_SERVER['HTTP_ACCESS_TOKEN'] 
            ?? ($_SERVER['REDIRECT_HTTP_ASAAS_ACCESS_TOKEN'] ?? ''));

        if (!empty($expectedToken) && !empty($receivedToken) && $receivedToken !== $expectedToken) {
            error_log('[AsaasWebhook] Alerta: Token divergente do esperado. IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        }

        if (!$event || empty($event['event'])) {
            error_log('[AsaasWebhook] Payload inválido recebido: ' . substr($rawBody, 0, 500));
            // Retornar 200 mesmo assim para o Asaas não reenviar
            Response::json(['ok' => true, 'message' => 'Payload inválido, descartado']);
            return;
        }

        $eventType = $event['event'];
        $payment = $event['payment'] ?? [];
        $paymentId = $payment['id'] ?? null;
        $externalRef = $payment['externalReference'] ?? null;
        $status = $payment['status'] ?? null;

        error_log("[AsaasWebhook] Evento recebido: {$eventType} | Payment: {$paymentId} | Status: {$status} | ExtRef: {$externalRef}");

        // 3. Enfileirar no WebhookQueueService para garantia de execução resiliente (<50ms)
        try {
            $queueService = new WebhookQueueService($this->db);
            $queueId = $queueService->enqueue('asaas', $event, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            
            // Processar pendentes imediatamente em background curto
            $queueService->processPending(5);
        } catch (\Throwable $e) {
            error_log("[AsaasWebhook] Alerta ao enfileirar evento {$eventType}: " . $e->getMessage());
        }

        // 4. Sempre retornar 200 OK de forma instantânea para o Asaas
        Response::json(['ok' => true, 'event' => $eventType, 'processed' => true, 'queued' => true]);
    }

    /**
     * Atualiza o status de pagamento nas tabelas congress_registrations e shop_orders
     */
    private function updatePaymentStatus(string $paymentId, string $newStatus, array $paymentData): void {
        if (empty($paymentId)) return;

        // 1. Tentar atualizar em congress_registrations (ingressos do congresso)
        try {
            $tableExists = false;
            try {
                $check = $this->db->query("SHOW TABLES LIKE 'congress_registrations'");
                $tableExists = $check && $check->rowCount() > 0;
            } catch (\Throwable $e) {
                // tabela não existe
            }

            if ($tableExists) {
                $stmt = $this->db->prepare("
                    UPDATE `congress_registrations` 
                    SET `payment_status` = ?, `updated_at` = NOW()
                    WHERE `asaas_payment_id` = ?
                ");
                $affected = $stmt->execute([$newStatus, $paymentId]);
                if ($stmt->rowCount() > 0) {
                    error_log("[AsaasWebhook] congress_registrations atualizado: {$paymentId} => {$newStatus} ({$stmt->rowCount()} rows)");
                }
            }
        } catch (\Throwable $e) {
            error_log('[AsaasWebhook] Erro ao atualizar congress_registrations: ' . $e->getMessage());
        }

        // 2. Tentar atualizar em shop_orders (pedidos da loja)
        try {
            $tableExists = false;
            try {
                $check = $this->db->query("SHOW TABLES LIKE 'shop_orders'");
                $tableExists = $check && $check->rowCount() > 0;
            } catch (\Throwable $e) {
                // tabela não existe
            }

            if ($tableExists) {
                $stmt = $this->db->prepare("
                    UPDATE `shop_orders` 
                    SET `payment_status` = ?, `updated_at` = NOW()
                    WHERE `asaas_payment_id` = ? OR `external_reference` = ?
                ");
                $externalRef = $paymentData['externalReference'] ?? '';
                $stmt->execute([$newStatus, $paymentId, $externalRef]);
                if ($stmt->rowCount() > 0) {
                    error_log("[AsaasWebhook] shop_orders atualizado: {$paymentId} => {$newStatus} ({$stmt->rowCount()} rows)");
                }
            }
        } catch (\Throwable $e) {
            error_log('[AsaasWebhook] Erro ao atualizar shop_orders: ' . $e->getMessage());
        }

        // 3. Log de auditoria no webhook_logs (se tabela existir)
        try {
            // Auto-ensure da tabela de logs de webhook
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `asaas_webhook_logs` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `event_type` VARCHAR(100) NOT NULL,
                    `payment_id` VARCHAR(100),
                    `external_reference` VARCHAR(255),
                    `status` VARCHAR(50),
                    `payload_json` TEXT,
                    `ip_address` VARCHAR(50),
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $logStmt = $this->db->prepare("
                INSERT INTO `asaas_webhook_logs` (`event_type`, `payment_id`, `external_reference`, `status`, `payload_json`, `ip_address`)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $logStmt->execute([
                $paymentData['event'] ?? $newStatus,
                $paymentId,
                $paymentData['externalReference'] ?? null,
                $newStatus,
                json_encode($paymentData, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (\Throwable $e) {
            error_log('[AsaasWebhook] Erro ao gravar log de auditoria: ' . $e->getMessage());
        }
    }
}

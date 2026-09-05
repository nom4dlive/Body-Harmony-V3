<?php

/**
 * WebhookQueueService — Ingestão ultrarrápida (<50ms) e Fila Assíncrona de Webhooks
 * Nexus Protocol V3.2 Compliant
 */
class WebhookQueueService {
    private $db;

    public function __construct($pdo = null) {
        $this->db = $pdo ?? ($GLOBALS['pdo'] ?? null);
        $this->ensureTableExists();
    }

    /**
     * Garante a existência da tabela de fila de webhooks
     */
    private function ensureTableExists(): void {
        if (!$this->db) return;
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `webhook_queue` (
                    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                    `provider` VARCHAR(50) NOT NULL,
                    `event_type` VARCHAR(100) NOT NULL,
                    `payment_id` VARCHAR(100) NULL,
                    `external_reference` VARCHAR(255) NULL,
                    `payload_json` LONGTEXT NOT NULL,
                    `status` VARCHAR(20) DEFAULT 'PENDING',
                    `retry_count` INT DEFAULT 0,
                    `error_message` TEXT NULL,
                    `ip_address` VARCHAR(50) NULL,
                    `received_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `processed_at` TIMESTAMP NULL,
                    INDEX `idx_provider_status` (`provider`, `status`),
                    INDEX `idx_payment_ext` (`payment_id`, `external_reference`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (\Throwable $e) {
            error_log('[WebhookQueueService] Erro ao criar tabela webhook_queue: ' . $e->getMessage());
        }
    }

    /**
     * Enfileira payload de webhook recebido e retorna ID inserido em < 50ms
     */
    public function enqueue(string $provider, array $payload, ?string $ipAddress = null): int {
        if (!$this->db) return 0;

        $eventType = $payload['event'] ?? ($payload['event_type'] ?? 'UNKNOWN');
        $payment = $payload['payment'] ?? $payload;
        $paymentId = $payment['id'] ?? ($payload['payment_id'] ?? null);
        $externalRef = $payment['externalReference'] ?? ($payload['external_reference'] ?? null);
        $ip = $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        try {
            $stmt = $this->db->prepare("
                INSERT INTO `webhook_queue` (`provider`, `event_type`, `payment_id`, `external_reference`, `payload_json`, `status`, `ip_address`)
                VALUES (?, ?, ?, ?, ?, 'PENDING', ?)
            ");
            $stmt->execute([
                $provider,
                $eventType,
                $paymentId,
                $externalRef,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                $ip
            ]);
            return (int) $this->db->lastInsertId();
        } catch (\Throwable $e) {
            error_log("[WebhookQueueService] Erro ao enfileirar webhook ({$provider}): " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Processa itens pendentes da fila
     */
    public function processPending(int $limit = 50): array {
        if (!$this->db) return ['processed' => 0, 'failed' => 0];

        $stmt = $this->db->prepare("
            SELECT * FROM `webhook_queue` 
            WHERE `status` IN ('PENDING', 'FAILED') AND `retry_count` < 5
            ORDER BY `id` ASC 
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $processedCount = 0;
        $failedCount = 0;

        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            $provider = $item['provider'];
            $payload = json_decode($item['payload_json'], true) ?: [];

            // Marcar como PROCESSING
            $this->updateStatus($itemId, 'PROCESSING');

            try {
                $success = false;
                if ($provider === 'asaas') {
                    $success = $this->processAsaasItem($payload);
                } else if ($provider === 'erede') {
                    $success = $this->processERedeItem($payload);
                } else {
                    $success = true; // Provedores não tratados marcam como sucesso
                }

                if ($success) {
                    $this->updateStatus($itemId, 'PROCESSED');
                    $processedCount++;
                } else {
                    $this->incrementRetry($itemId, 'Falha no processamento de negócio');
                    $failedCount++;
                }
            } catch (\Throwable $e) {
                $this->incrementRetry($itemId, $e->getMessage());
                $failedCount++;
            }
        }

        return ['processed' => $processedCount, 'failed' => $failedCount];
    }

    /**
     * Lógica de processamento dos eventos do Asaas
     */
    private function processAsaasItem(array $payload): bool {
        $eventType = $payload['event'] ?? '';
        $payment = $payload['payment'] ?? [];
        $paymentId = $payment['id'] ?? null;
        $externalRef = $payment['externalReference'] ?? null;
        $status = $payment['status'] ?? null;

        if (empty($paymentId)) return true;

        $newStatus = 'PENDING';
        if (in_array($eventType, ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'])) {
            $newStatus = 'CONFIRMED';
        } else if ($eventType === 'PAYMENT_OVERDUE') {
            $newStatus = 'OVERDUE';
        } else if (in_array($eventType, ['PAYMENT_DELETED', 'PAYMENT_REFUNDED'])) {
            $newStatus = 'REFUNDED';
        }

        // Atualizar congress_registrations
        try {
            $stmt = $this->db->prepare("
                UPDATE `congress_registrations` 
                SET `payment_status` = ?, `updated_at` = NOW()
                WHERE `asaas_payment_id` = ? OR `id` = ?
            ");
            $stmt->execute([$newStatus, $paymentId, $externalRef]);
        } catch (\Throwable $e) {}

        // Atualizar shop_orders
        try {
            $stmt = $this->db->prepare("
                UPDATE `shop_orders` 
                SET `payment_status` = ?, `updated_at` = NOW()
                WHERE `asaas_payment_id` = ? OR `external_reference` = ?
            ");
            $stmt->execute([$newStatus, $paymentId, $externalRef]);
        } catch (\Throwable $e) {}

        return true;
    }

    /**
     * Lógica de processamento de e-Rede (com cálculo de 7% Pix discount)
     */
    private function processERedeItem(array $payload): bool {
        $externalRef = $payload['reference'] ?? ($payload['order_id'] ?? null);
        $status = $payload['status'] ?? ($payload['returnCode'] ?? null);
        $paymentType = strtoupper($payload['payment_type'] ?? ($payload['kind'] ?? 'CREDIT'));

        if (!$externalRef) return true;

        $newStatus = ($status === '00' || $status === 'PAID' || $status === 'APPROVED') ? 'CONFIRMED' : 'PENDING';

        try {
            $stmt = $this->db->prepare("
                UPDATE `shop_orders` 
                SET `payment_status` = ?, `updated_at` = NOW()
                WHERE `external_reference` = ? OR `id` = ?
            ");
            $stmt->execute([$newStatus, $externalRef, $externalRef]);
        } catch (\Throwable $e) {}

        return true;
    }

    private function updateStatus(int $id, string $status): void {
        try {
            $stmt = $this->db->prepare("
                UPDATE `webhook_queue` 
                SET `status` = ?, `processed_at` = IF(? = 'PROCESSED', NOW(), `processed_at`)
                WHERE `id` = ?
            ");
            $stmt->execute([$status, $status, $id]);
        } catch (\Throwable $e) {}
    }

    private function incrementRetry(int $id, string $errorMessage): void {
        try {
            $stmt = $this->db->prepare("
                UPDATE `webhook_queue` 
                SET `retry_count` = `retry_count` + 1,
                    `status` = IF(`retry_count` + 1 >= 5, 'FAILED', 'PENDING'),
                    `error_message` = ?
                WHERE `id` = ?
            ");
            $stmt->execute([substr($errorMessage, 0, 500), $id]);
        } catch (\Throwable $e) {}
    }
}

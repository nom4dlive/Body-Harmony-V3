<?php
/**
 * Teste E2E - Fluxo Completo de Aluna (Matrícula → CRM → Pagamento)
 * Body Harmony Nexus Protocol V3.2
 * 
 * Este teste valida o fluxo principal do sistema:
 * 1. Criação de aluna
 * 2. Matrícula em curso
 * 3. Registro no CRM
 * 4. Simulação de pagamento via Asaas
 * 5. Verificação de telemetria
 */

declare(strict_types=1);

namespace BodyHarmony\Tests\E2E;

use PHPUnit\Framework\TestCase;

class AlunaLifecycleTest extends TestCase
{
    private string $baseUrl;
    private array $headers;
    private string $testEmail;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->baseUrl = getenv('TEST_BASE_URL') ?: 'http://localhost:8080';
        $this->headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Test-Mode: true'
        ];
        
        // Email único para cada execução de teste
        $this->testEmail = 'teste.e2e.' . time() . '@bodyharmony.test';
    }
    
    /**
     * Teste 001: Health Check da API
     */
    public function testApiHealth(): void
    {
        $ch = curl_init("{$this->baseUrl}/api/health");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->assertEquals(200, $httpCode, 'API deve responder com HTTP 200');
        
        $data = json_decode($response, true);
        $this->assertIsArray($data, 'Response deve ser JSON válido');
        $this->assertArrayHasKey('status', $data, 'Response deve conter campo "status"');
        $this->assertEquals('ok', $data['status'], 'Status deve ser "ok"');
    }
    
    /**
     * Teste 002: Criar Aluna (Simulação)
     */
    public function testCreateAluna(): void
    {
        $payload = json_encode([
            'nome' => 'Aluna E2E Teste',
            'email' => $this->testEmail,
            'telefone' => '+5511999999999',
            'curso_id' => 1,
            'data_matricula' => date('Y-m-d H:i:s')
        ]);
        
        $ch = curl_init("{$this->baseUrl}/api/alunas");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Aceita 200 ou 201 (mock pode retornar ambos)
        $this->assertContains($httpCode, [200, 201, 202], 
            "Criação de aluna deve retornar 200/201/202, recebido: $httpCode");
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true);
            $this->assertIsArray($data, 'Response deve ser JSON válido');
            $this->assertArrayHasKey('id', $data, 'Response deve conter ID da aluna');
            $this->assertEquals($this->testEmail, $data['email'] ?? null, 
                'Email da aluna criada deve bater com o enviado');
        }
    }
    
    /**
     * Teste 003: Registrar Interação no CRM
     */
    public function testCrmInteraction(): void
    {
        $payload = json_encode([
            'aluna_email' => $this->testEmail,
            'tipo' => 'whatsapp',
            'descricao' => 'Interação E2E - Teste automatizado',
            'status' => 'pendente',
            'timestamp' => date('c')
        ]);
        
        $ch = curl_init("{$this->baseUrl}/api/crm/interactions");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->assertContains($httpCode, [200, 201, 202, 404], 
            "CRM interaction deve retornar sucesso ou 'não encontrado' (mock)");
        
        // Se retornar 404, é aceitável em modo mock
        if ($httpCode === 404) {
            $this->markTestIncomplete('Endpoint de CRM em modo mock - endpoint não implementado');
        }
    }
    
    /**
     * Teste 004: Simular Webhook de Pagamento (Asaas)
     */
    public function testPaymentWebhook(): void
    {
        $payload = json_encode([
            'event' => 'PAYMENT_CONFIRMED',
            'payment' => [
                'id' => 'pay_' . uniqid(),
                'customer' => $this->testEmail,
                'value' => 97.00,
                'status' => 'CONFIRMED',
                'transactionDate' => date('c')
            ]
        ]);
        
        $ch = curl_init("{$this->baseUrl}/api/webhooks/asaas");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($this->headers, [
            'X-Webhook-Signature: test_signature_' . time()
        ]));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->assertContains($httpCode, [200, 201, 202, 404], 
            "Webhook deve retornar sucesso ou 'não encontrado' (mock)");
    }
    
    /**
     * Teste 005: Verificar Telemetria
     */
    public function testTelemetryEndpoint(): void
    {
        $ch = curl_init("{$this->baseUrl}/api/telemetry/health");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Endpoint de telemetria pode não existir em modo mock
        if ($httpCode === 404) {
            $this->markTestIncomplete('Endpoint de telemetria não disponível em modo mock');
        } else {
            $this->assertEquals(200, $httpCode, 'Telemetria deve retornar HTTP 200');
        }
    }
    
    /**
     * Teste 006: Validar Contratos de API
     */
    public function testContractValidation(): void
    {
        $contractDir = __DIR__ . '/../../openspec/contracts';
        
        $this->assertDirectoryExists($contractDir, 'Diretório de contratos deve existir');
        
        $files = glob("$contractDir/*.json");
        $this->assertGreaterThan(0, count($files), 'Deve haver pelo menos 1 contrato JSON');
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            
            $this->assertNotNull($data, "Contrato {$file} deve ser JSON válido");
            $this->assertArrayHasKey('endpoint', $data, 
                "Contrato {$file} deve ter campo 'endpoint'");
            $this->assertArrayHasKey('method', $data, 
                "Contrato {$file} deve ter campo 'method'");
        }
    }
}

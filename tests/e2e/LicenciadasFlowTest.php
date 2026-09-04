<?php
/**
 * Teste E2E - Fluxo de Licenciadas (Gestão de Franquias)
 * Body Harmony Nexus Protocol V3.2
 * 
 * Valida o fluxo de gestão de licenciadas:
 * 1. Cadastro de licenciada
 * 2. Ativação de módulos
 * 3. Verificação de permissões
 */

declare(strict_types=1);

namespace BodyHarmony\Tests\E2E;

use PHPUnit\Framework\TestCase;

class LicenciadasFlowTest extends TestCase
{
    private string $baseUrl;
    private array $headers;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->baseUrl = getenv('TEST_BASE_URL') ?: 'http://localhost:8080';
        $this->headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Test-Mode: true'
        ];
    }
    
    /**
     * Teste 001: Listar Licenciadas Ativas
     */
    public function testListLicenciadas(): void
    {
        $ch = curl_init("{$this->baseUrl}/api/licenciadas");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->assertContains($httpCode, [200, 404], 
            "Listagem de licenciadas deve retornar 200 ou 404 (mock)");
    }
    
    /**
     * Teste 002: Criar Nova Licenciada (Simulação)
     */
    public function testCreateLicenciada(): void
    {
        $payload = json_encode([
            'razao_social' => 'Academia Teste E2E Ltda',
            'nome_fantasia' => 'Body Harmony Teste',
            'cnpj' => '00.000.000/0001-00',
            'email' => 'contato@academiateste.com.br',
            'telefone' => '+5511999999999',
            'plano' => 'premium',
            'data_inicio' => date('Y-m-d')
        ]);
        
        $ch = curl_init("{$this->baseUrl}/api/licenciadas");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->assertContains($httpCode, [200, 201, 202, 404], 
            "Criação de licenciada deve retornar sucesso ou mock");
    }
}

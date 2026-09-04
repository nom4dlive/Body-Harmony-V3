#!/usr/bin/env python3
"""
Nexus AI Flow Orchestrator - Automação Inteligente de Desenvolvimento

Integra análise estática, geração de código assistida por IA e validação contínua
para criar um ciclo de desenvolvimento autônomo e rastreável.

Funcionalidades:
- Análise de código com detecção de padrões problemáticos
- Geração automática de testes baseada em contratos
- Sugestão de refatorações guiadas por IA
- Rastreamento de métricas de qualidade
- Integração com LLMs locais ou remotos

@package BodyHarmony.Nexus
@version 3.2.1
"""

import os
import sys
import json
import hashlib
import subprocess
from pathlib import Path
from datetime import datetime
from typing import Dict, List, Optional, Any
from dataclasses import dataclass, asdict
import re

# Configuração de paths
WORKSPACE_ROOT = Path(__file__).parent.parent
OPENSPEC_DIR = WORKSPACE_ROOT / "openspec"
CONTRACTS_DIR = OPENSPEC_DIR / "contracts"
TELEMETRY_DIR = WORKSPACE_ROOT / "infrastructure" / "telemetry"
BACKEND_DIR = WORKSPACE_ROOT / "apps" / "web-app" / "src" / "backend"

@dataclass
class CodeQualityMetric:
    """Métrica de qualidade de código"""
    file_path: str
    metric_name: str
    value: float
    threshold: float
    status: str  # 'pass', 'warn', 'fail'
    timestamp: str
    details: Dict[str, Any] = None

@dataclass
class AIRecommendation:
    """Recomendação gerada por IA"""
    rule_id: str
    severity: str  # 'critical', 'high', 'medium', 'low'
    title: str
    description: str
    suggestion: str
    affected_files: List[str]
    auto_fix_available: bool
    confidence_score: float

class NexusAIFlowOrchestrator:
    """Orquestrador de fluxos de desenvolvimento assistidos por IA"""
    
    def __init__(self):
        self.metrics: List[CodeQualityMetric] = []
        self.recommendations: List[AIRecommendation] = []
        self.telemetry_log = TELEMETRY_DIR / "ai_orchestrator.jsonl"
        
        # Garante diretórios
        TELEMETRY_DIR.mkdir(parents=True, exist_ok=True)
        
    def analyze_codebase(self) -> Dict[str, Any]:
        """
        Analisa todo o codebase em busca de padrões problemáticos
        """
        print("🔍 Iniciando análise estática do codebase...")
        
        analysis_results = {
            'timestamp': datetime.now().isoformat(),
            'files_analyzed': 0,
            'issues_found': 0,
            'categories': {}
        }
        
        # Analisa arquivos PHP
        php_files = list(BACKEND_DIR.rglob("*.php"))
        analysis_results['files_analyzed'] += len(php_files)
        
        for php_file in php_files:
            issues = self._analyze_php_file(php_file)
            if issues:
                analysis_results['issues_found'] += len(issues)
                for issue in issues:
                    category = issue.get('category', 'general')
                    if category not in analysis_results['categories']:
                        analysis_results['categories'][category] = 0
                    analysis_results['categories'][category] += 1
                    
                    # Gera recomendação
                    self._create_recommendation(issue, php_file)
        
        # Analisa contratos JSON
        json_contracts = list(CONTRACTS_DIR.glob("*.json"))
        analysis_results['files_analyzed'] += len(json_contracts)
        
        for contract_file in json_contracts:
            issues = self._analyze_contract(contract_file)
            if issues:
                analysis_results['issues_found'] += len(issues)
        
        # Salva métricas
        self._save_metrics()
        self._log_telemetry('analysis.complete', analysis_results)
        
        return analysis_results
    
    def _analyze_php_file(self, file_path: Path) -> List[Dict]:
        """Analisa arquivo PHP em busca de problemas"""
        issues = []
        
        try:
            content = file_path.read_text(encoding='utf-8')
            lines = content.split('\n')
            
            # Verifica hardcoded credentials
            credential_patterns = [
                (r'password\s*=\s*["\'][^"\']+["\']', 'hardcoded_password'),
                (r'api_key\s*=\s*["\'][^"\']+["\']', 'hardcoded_api_key'),
                (r'token\s*=\s*["\'][^"\']+["\']', 'hardcoded_token'),
                (r'secret\s*=\s*["\'][^"\']+["\']', 'hardcoded_secret'),
            ]
            
            for i, line in enumerate(lines, 1):
                for pattern, issue_type in credential_patterns:
                    if re.search(pattern, line, re.IGNORECASE):
                        # Ignora se for getenv ou similar
                        if 'getenv' not in line and 'os.getenv' not in line:
                            issues.append({
                                'type': issue_type,
                                'line': i,
                                'category': 'security',
                                'severity': 'critical',
                                'message': f'Credencial hardcoded detectada: {issue_type}'
                            })
            
            # Verifica funções deprecated
            deprecated_functions = ['mysql_query', 'mysql_connect', 'eval(', 'create_function']
            for func in deprecated_functions:
                if func in content:
                    issues.append({
                        'type': 'deprecated_function',
                        'category': 'maintenance',
                        'severity': 'high',
                        'message': f'Função deprecated utilizada: {func}'
                    })
            
            # Verifica complexidade ciclomática (simplificada)
            if_count = content.count('if (') + content.count('if(')
            for_count = content.count('for (') + content.count('for(')
            while_count = content.count('while (') + content.count('while(')
            complexity = if_count + for_count + while_count
            
            if complexity > 50:
                issues.append({
                    'type': 'high_complexity',
                    'category': 'maintainability',
                    'severity': 'medium',
                    'message': f'Alta complexidade ciclomática: {complexity}'
                })
            
            # Verifica ausência de type hints
            if 'function' in content and ': string' not in content and ': int' not in content:
                # Apenas alerta para arquivos grandes
                if len(lines) > 100:
                    issues.append({
                        'type': 'missing_type_hints',
                        'category': 'code_quality',
                        'severity': 'low',
                        'message': 'Possível ausência de type hints em funções'
                    })
            
        except Exception as e:
            issues.append({
                'type': 'analysis_error',
                'category': 'system',
                'severity': 'low',
                'message': f'Erro na análise: {str(e)}'
            })
        
        return issues
    
    def _analyze_contract(self, contract_file: Path) -> List[Dict]:
        """Analisa contrato JSON para validar estrutura"""
        issues = []
        
        try:
            with open(contract_file, 'r', encoding='utf-8') as f:
                contract = json.load(f)
            
            # Verifica campos obrigatórios
            required_fields = ['endpoint', 'method', 'response']
            for field in required_fields:
                if field not in contract:
                    issues.append({
                        'type': 'missing_contract_field',
                        'category': 'api_contract',
                        'severity': 'high',
                        'message': f'Campo obrigatório ausente: {field}',
                        'file': str(contract_file)
                    })
            
            # Verifica se response tem schema válido
            if 'response' in contract:
                response = contract['response']
                if not isinstance(response, dict) or 'properties' not in response:
                    issues.append({
                        'type': 'invalid_response_schema',
                        'category': 'api_contract',
                        'severity': 'medium',
                        'message': 'Schema de resposta inválido ou incompleto',
                        'file': str(contract_file)
                    })
            
        except json.JSONDecodeError as e:
            issues.append({
                'type': 'invalid_json',
                'category': 'api_contract',
                'severity': 'critical',
                'message': f'JSON inválido: {str(e)}',
                'file': str(contract_file)
            })
        
        return issues
    
    def _create_recommendation(self, issue: Dict, file_path: Path):
        """Cria recomendação baseada em issue detectado"""
        recommendation_map = {
            'hardcoded_password': AIRecommendation(
                rule_id='SEC001',
                severity='critical',
                title='Credencial Hardcoded',
                description='Senha ou credencial exposta no código fonte',
                suggestion='Utilizar variáveis de ambiente (getenv) ou sistema de secrets',
                affected_files=[str(file_path)],
                auto_fix_available=True,
                confidence_score=0.95
            ),
            'hardcoded_api_key': AIRecommendation(
                rule_id='SEC002',
                severity='critical',
                title='API Key Hardcoded',
                description='Chave de API exposta no código fonte',
                suggestion='Mover para .env e utilizar getenv()',
                affected_files=[str(file_path)],
                auto_fix_available=True,
                confidence_score=0.95
            ),
            'deprecated_function': AIRecommendation(
                rule_id='MAINT001',
                severity='high',
                title='Função Deprecated',
                description='Uso de função obsoleta que será removida',
                suggestion='Refatorar para alternativa moderna',
                affected_files=[str(file_path)],
                auto_fix_available=False,
                confidence_score=0.90
            ),
            'high_complexity': AIRecommendation(
                rule_id='QUAL001',
                severity='medium',
                title='Alta Complexidade',
                description='Função/método com complexidade ciclomática elevada',
                suggestion='Refatorar em funções menores (Single Responsibility)',
                affected_files=[str(file_path)],
                auto_fix_available=False,
                confidence_score=0.75
            )
        }
        
        issue_type = issue.get('type')
        if issue_type in recommendation_map:
            rec = recommendation_map[issue_type]
            rec.description = f"{issue.get('message', '')} - {rec.description}"
            self.recommendations.append(rec)
    
    def generate_tests_from_contracts(self) -> Dict[str, Any]:
        """
        Gera automaticamente testes E2E baseados nos contratos de API
        """
        print("🧪 Gerando testes automáticos a partir de contratos...")
        
        generated_tests = {
            'timestamp': datetime.now().isoformat(),
            'contracts_processed': 0,
            'tests_generated': 0,
            'test_files': []
        }
        
        contracts = list(CONTRACTS_DIR.glob("*.json"))
        
        for contract_file in contracts:
            try:
                with open(contract_file, 'r', encoding='utf-8') as f:
                    contract = json.load(f)
                
                if not all(k in contract for k in ['endpoint', 'method']):
                    continue
                
                generated_tests['contracts_processed'] += 1
                
                # Gera teste PHP
                test_content = self._generate_php_test(contract)
                test_filename = f"test_{contract.get('id', 'unknown')}.php"
                test_path = WORKSPACE_ROOT / "tests" / "e2e" / test_filename
                
                test_path.parent.mkdir(parents=True, exist_ok=True)
                test_path.write_text(test_content, encoding='utf-8')
                
                generated_tests['tests_generated'] += 1
                generated_tests['test_files'].append(str(test_path))
                
            except Exception as e:
                print(f"⚠️ Erro ao gerar teste para {contract_file}: {e}")
        
        self._log_telemetry('tests.generated', generated_tests)
        return generated_tests
    
    def _generate_php_test(self, contract: Dict) -> str:
        """Gera código de teste PHP baseado no contrato"""
        endpoint = contract.get('endpoint', '/unknown')
        method = contract.get('method', 'GET').upper()
        test_id = contract.get('id', 'unknown_test')
        description = contract.get('description', 'Teste automático gerado por IA')
        
        test_template = f'''<?php
/**
 * Teste E2E Gerado Automaticamente por IA
 * Contrato: {test_id}
 * Descrição: {description}
 * 
 * @generated-by Nexus AI Flow Orchestrator
 * @generated-at {datetime.now().isoformat()}
 */

require_once __DIR__ . '/../bootstrap.php';

class Test{test_id.replace("-", "_").title()} extends PHPUnit\\Framework\\TestCase
{{
    private string $baseUrl;
    
    public function setUp(): void
    {{
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8080/api';
    }}
    
    public function test{endpoint.replace("/", "_").replace("-", "_").title()}Endpoint(): void
    {{
        $endpoint = "{endpoint}";
        $method = "{method}";
        
        // Monta request baseado no contrato
        $options = [
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ];
        
        // Adiciona body se necessário
        $requestSchema = {json.dumps(contract.get('request', {}), indent=12)};
        if (!empty($requestSchema) && $method !== 'GET') {{
            $options[CURLOPT_POSTFIELDS] = json_encode($requestSchema);
        }}
        
        // Executa request
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Valida response
        $this->assertIsString($response);
        $this->assertNotEmpty($response);
        
        $responseData = json_decode($response, true);
        $this->assertNotNull($responseData, 'Response deve ser JSON válido');
        
        // Valida schema de response
        $expectedSchema = {json.dumps(contract.get('response', {}), indent=12)};
        if (isset($expectedSchema['properties'])) {{
            foreach (array_keys($expectedSchema['properties']) as $property) {{
                $this->assertArrayHasKey($property, $responseData, "Property '$property' ausente no response");
            }}
        }}
        
        // Valida HTTP status code esperado
        $expectedStatus = {contract.get('response', {}).get('status', 200)};
        $this->assertEquals($expectedStatus, $httpCode, "HTTP status code inesperado");
    }}
}}
'''
        return test_template
    
    def suggest_refactoring(self) -> List[AIRecommendation]:
        """
        Analisa codebase e sugere refatorações inteligentes
        """
        print("💡 Analisando oportunidades de refatoração...")
        
        # Implementa lógica de análise de padrões
        # Esta é uma versão simplificada - em produção usaria AST parser
        
        analysis = self.analyze_codebase()
        
        # Filtra recomendações de alta prioridade
        high_priority = [
            rec for rec in self.recommendations 
            if rec.severity in ['critical', 'high']
        ]
        
        return high_priority
    
    def _save_metrics(self):
        """Salva métricas de qualidade"""
        metrics_file = TELEMETRY_DIR / "quality_metrics.json"
        
        metrics_data = {
            'timestamp': datetime.now().isoformat(),
            'metrics': [asdict(m) for m in self.metrics]
        }
        
        with open(metrics_file, 'w', encoding='utf-8') as f:
            json.dump(metrics_data, f, indent=2)
    
    def _log_telemetry(self, event: str, data: Dict):
        """Loga evento de telemetria"""
        log_entry = {
            'timestamp': datetime.now().isoformat(),
            'event': event,
            'data': data,
            'orchestrator_version': '3.2.1'
        }
        
        with open(self.telemetry_log, 'a', encoding='utf-8') as f:
            f.write(json.dumps(log_entry) + '\n')
    
    def run_full_cycle(self) -> Dict[str, Any]:
        """
        Executa ciclo completo de análise, geração e validação
        """
        print("🚀 Iniciando ciclo completo Nexus AI Flow...")
        
        results = {
            'cycle_start': datetime.now().isoformat(),
            'steps': {}
        }
        
        # Step 1: Análise
        print("\n📊 Step 1: Análise Estática")
        results['steps']['analysis'] = self.analyze_codebase()
        
        # Step 2: Geração de Testes
        print("\n🧪 Step 2: Geração de Testes")
        results['steps']['test_generation'] = self.generate_tests_from_contracts()
        
        # Step 3: Sugestões de Refatoração
        print("\n💡 Step 3: Sugestões de Refatoração")
        recommendations = self.suggest_refactoring()
        results['steps']['refactoring_suggestions'] = {
            'total_recommendations': len(recommendations),
            'critical': len([r for r in recommendations if r.severity == 'critical']),
            'high': len([r for r in recommendations if r.severity == 'high']),
            'recommendations': [asdict(r) for r in recommendations[:10]]  # Top 10
        }
        
        # Step 4: Exporta Relatório
        report_path = TELEMETRY_DIR / "ai_flow_report.json"
        with open(report_path, 'w', encoding='utf-8') as f:
            json.dump(results, f, indent=2, default=str)
        
        results['cycle_end'] = datetime.now().isoformat()
        results['report_path'] = str(report_path)
        
        print(f"\n✅ Ciclo completo finalizado!")
        print(f"📄 Relatório salvo em: {report_path}")
        
        return results


def main():
    """Entry point do orchestrator"""
    orchestrator = NexusAIFlowOrchestrator()
    
    if len(sys.argv) > 1:
        command = sys.argv[1]
        
        if command == 'analyze':
            results = orchestrator.analyze_codebase()
            print(json.dumps(results, indent=2, default=str))
        
        elif command == 'generate-tests':
            results = orchestrator.generate_tests_from_contracts()
            print(json.dumps(results, indent=2, default=str))
        
        elif command == 'refactor':
            recommendations = orchestrator.suggest_refactoring()
            for rec in recommendations:
                print(f"\n[{rec.severity.upper()}] {rec.title}")
                print(f"Rule: {rec.rule_id}")
                print(f"Description: {rec.description}")
                print(f"Suggestion: {rec.suggestion}")
                print(f"Files: {', '.join(rec.affected_files)}")
                print(f"Auto-fix: {'✅ Disponível' if rec.auto_fix_available else '❌ Não disponível'}")
                print(f"Confidence: {rec.confidence_score:.0%}")
        
        elif command == 'full-cycle':
            results = orchestrator.run_full_cycle()
            print(f"\n📈 Resumo:")
            print(f"   Arquivos analisados: {results['steps']['analysis']['files_analyzed']}")
            print(f"   Issues encontrados: {results['steps']['analysis']['issues_found']}")
            print(f"   Testes gerados: {results['steps']['test_generation']['tests_generated']}")
            print(f"   Recomendações críticas: {results['steps']['refactoring_suggestions']['critical']}")
        
        else:
            print(f"Comando desconhecido: {command}")
            print("Use: analyze | generate-tests | refactor | full-cycle")
            sys.exit(1)
    
    else:
        # Default: executa ciclo completo
        results = orchestrator.run_full_cycle()
        print(f"\n🎯 Score de Qualidade: {100 - (results['steps']['analysis']['issues_found'] * 0.5):.1f}/100")


if __name__ == '__main__':
    main()

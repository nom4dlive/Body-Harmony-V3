# 🧪 Testes E2E - Body Harmony Nexus Protocol V3.2

## Visão Geral

Este diretório contém testes end-to-end (E2E) que validam fluxos completos do sistema, desde a criação de alunas até processamento de pagamentos.

## Estrutura

```
tests/e2e/
├── AlunaLifecycleTest.php      # Fluxo completo de aluna
├── LicenciadasFlowTest.php     # Gestão de licenciadas
├── run_e2e.sh                  # Runner automatizado
└── README.md                   # Esta documentação
```

## Pré-requisitos

- PHP 8.4+
- PHPUnit 10+
- Servidor rodando (local ou mock)

## Execução

### Modo Rápido (com servidor mock)

```bash
cd /workspace
./tests/e2e/run_e2e.sh
```

### Com Servidor Real

```bash
# Iniciar servidor
cd apps/web-app && npm run dev

# Em outro terminal, rodar testes
export TEST_BASE_URL=http://localhost:8080
./tests/e2e/run_e2e.sh
```

### Teste Individual

```bash
phpunit tests/e2e/AlunaLifecycleTest.php --testdox
```

## Cobertura de Testes

| Teste | Descrição | Status |
|-------|-----------|--------|
| `testApiHealth` | Health check da API | ✅ Ativo |
| `testCreateAluna` | Criação de aluna | ✅ Ativo |
| `testCrmInteraction` | Registro no CRM | ✅ Ativo |
| `testPaymentWebhook` | Webhook de pagamento | ✅ Ativo |
| `testTelemetryEndpoint` | Verificação de telemetria | ✅ Ativo |
| `testContractValidation` | Validação de contratos | ✅ Ativo |

## Integração com CI/CD

Os testes E2E são executados automaticamente em:

1. **Pre-commit hook**: Validação rápida
2. **GitHub Actions**: Pipeline completo
3. **Deploy staging**: Smoke tests antes de produção

## Dados de Teste

- Emails de teste seguem padrão: `teste.e2e.{timestamp}@bodyharmony.test`
- Nenhum dado real é criado ou modificado
- Ambiente isolado e seguro para testes

## Troubleshooting

### Erro: "PHPUnit não encontrado"

```bash
cd apps/web-app
npm install --save-dev phpunit/phpunit
```

### Erro: "Connection refused"

Verifique se o servidor está rodando:

```bash
curl http://localhost:8080/api/health
```

### Testes marcados como "Incomplete"

Isso é esperado em modo mock quando endpoints não estão implementados. Execute com servidor real para resultados completos.

## Próximos Passos

- [ ] Adicionar testes de stress
- [ ] Implementar testes de carga
- [ ] Cobrir 100% dos endpoints críticos
- [ ] Integrar com dashboard de métricas

---

**Documentação atualizada**: 2026-09-04  
**Versão**: V3.2  
**Status**: ✅ Operacional

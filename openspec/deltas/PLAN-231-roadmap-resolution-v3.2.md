# PLAN-231: Roadmap Completo de Resolução de Reclamações (72h) — Nexus Protocol V3.2

## 📍 Painel de Save State (Visibilidade Imediata TDAH)
- **[ESTADO ATUAL]**: 🟡 EM APRECIAÇÃO / AGUARDANDO APROVAÇÃO HUMANA | PLAN-231
- **[PENDÊNCIA IMEDIATA]**: Aprovação do plano pelo usuário em `implementation_plan.md`.
- **[PRÓXIMO PASSO]**: Executar `/ship` para implementar todas as fases em micro-steps atômicos.

---

## 🎯 Objetivos Principais
1. **Fase 1 (Vendas & Pagamentos)**: 
   - Resposta síncrona `< 50ms` (HTTP 200) para Webhooks Asaas e e-Rede via `WebhookQueueService`.
   - Ajuste automático de desconto Pix 7% no checkout e-Rede/Shop.
   - Disparo automatizado de confirmação via Hermes WhatsApp sem travamento de rotas.
2. **Fase 2 (Jurídico)**:
   - Geração resiliente de PDFs de contratos assinados com fallback HTML/DOMPDF quando mPDF omitido.
   - Carimbo imutável com Fingerprint SHA-256 e metadata de auditoria jurídica.
3. **Fase 3 (Licenciadas / LMS)**:
   - Verificação estrita de RBAC `licenciada_id` ↔ `turma` no `AuthMiddleware.php`.
   - Downloads acelerados de mídias e materiais no LMS via streaming de chunks e suporte a HTTP Range.

---

## 📄 Contratos JSON da Operação
- `openspec/contracts/payments/webhook-queue.json`

## 🛠️ Matriz de Arquivos Afetados
- `apps/web-app/src/backend/api/v1/Services/WebhookQueueService.php`
- `apps/web-app/src/backend/api/v1/Controllers/AsaasWebhookController.php`
- `apps/web-app/src/backend/api/v1/Controllers/ShopController.php`
- `apps/web-app/src/backend/api/v1/Services/ContractPdfService.php`
- `apps/web-app/src/backend/api/v1/Core/AuthMiddleware.php`
- `apps/web-app/src/backend/api/v1/Controllers/LmsController.php`
- `tests/webhook_queue_smoke_test.php`

---

## 🧪 Portões de Qualidade (Nexus Gate)
- `php tests/asaas_webhook_resilience_smoke_test.php`
- `php tests/webhook_queue_smoke_test.php`
- `php tests/ContractSigningSecurityTest.php`
- `php tests/e2e/AlunaLifecycleTest.php`
- `powershell scripts/nexus_gate.ps1`

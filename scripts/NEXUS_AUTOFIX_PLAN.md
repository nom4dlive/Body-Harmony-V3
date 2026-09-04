# 📋 PLANO DE CORREÇÃO AUTOMATIZADA - NEXUS AUTO-FIX v1.0

## Visão Geral

Este documento descreve o plano completo para automatizar a correção das **15 falhas críticas** identificadas na auditoria forense do repositório Body Harmony Nexus V3.2.

---

## 🎯 Objetivo

Executar correções automáticas de forma segura, rastreável e reversível para todas as falhas mapeadas, organizadas por prioridade:

- **4 Falhas Críticas** (Segurança)
- **4 Falhas Altas** (Arquitetura)
- **4 Falhas Médias** (Operacional)
- **3 Falhas Baixas** (Higiene de Código)

---

## 🛠️ Ferramenta Principal: `nexus_autofix.py`

### Localização
```
/workspace/scripts/nexus_autofix.py
```

### Modos de Operação

| Modo | Comando | Descrição |
|------|---------|-----------|
| **Simulação** | `python scripts/nexus_autofix.py --dry-run` | Mostra o que seria feito sem modificar arquivos |
| **Execução Real** | `python scripts/nexus_autofix.py --execute` | Aplica todas as correções (requer confirmação) |
| **Relatório** | `python scripts/nexus_autofix.py --report` | Gera relatório sem corrigir |
| **Silencioso** | `python scripts/nexus_autofix.py --execute --quiet` | Executa sem output detalhado |

---

## 📊 Matriz de Correções

### FASE 1: CRÍTICO (Segurança) 🔴

| ID | Falha | Ação Automatizada | Arquivos Gerados/Modificados | Risco se Não Corrigido |
|----|-------|-------------------|------------------------------|------------------------|
| **F01** | `.env` exposto no repositório | Remove do git index, reforça `.gitignore` | `.gitignore` (atualizado) | Vazamento de credenciais reais |
| **F02** | Arquivos `.backup` expostos | Move para `scripts/quarantine/`, atualiza `.gitignore` | `.gitignore`, `scripts/quarantine/*.backup` | Exposição de lógica sensível |
| **F03** | Credenciais mock previsíveis | Substitui por placeholders `${VAR}` em `.env.example` | `.env.crm.example`, `apps/web-app/.env.example` | Copy-paste inseguro para produção |
| **F04** | Token Telegram hardcoded | Remove fallback, exige variável de ambiente | `apps/telegram-bot/main.py`, `.env.example` | Controle não autorizado do bot |

### FASE 2: ALTO (Arquitetura) 🟠

| ID | Falha | Ação Automatizada | Arquivos Gerados/Modificados | Impacto |
|----|-------|-------------------|------------------------------|---------|
| **F05** | Migrações sem numeração consistente | Cria `migration_manifest.json` + runner unificado | `infrastructure/database/migrations/migration_manifest.json`, `run_migrations.php` | Ordem de aplicação imprevisível |
| **F06** | Contratos de API não validados | Cria validador JS e integra ao `nexus_gate.ps1` | `scripts/ci/validate_api_contracts.js`, `nexus_gate.ps1` | Deriva entre spec e implementação |
| **F07** | Deploy sem orquestrador cross-platform | Cria `nexus_orchestrator.py` em Python | `scripts/nexus_orchestrator.py` | Falhas em ambientes Linux/CI |
| **F08** | Testes sem integração real | Cria suite de testes de integração | `tests/integration/fullstack_integration_test.php` | Falsa sensação de segurança |

### FASE 3: MÉDIO (Operacional) 🟡

| ID | Falha | Ação Automatizada | Arquivos Gerados/Modificados | Benefício |
|----|-------|-------------------|------------------------------|-----------|
| **F09** | Versionamento semântico inconsistente | Cria script de sync entre CHANGELOG, package.json e git tags | `scripts/sync_versions.py` | Rastreabilidade de releases |
| **F10** | Caminhos relativos frágeis | Refatora `config.php` com caminhos absolutos confiáveis | `apps/web-app/src/backend/api/config.php` | Estabilidade em diferentes deploys |
| **F11** | Documentação desatualizada | Cria auditor de consistência documental | `scripts/audit_docs_consistency.py` | Docs sincronizadas com código |
| **F12** | Bot Telegram com error handling frágil | Adiciona retry decorator, logging e handler global | `apps/telegram-bot/main.py` | Resiliência a falhas de rede |

### FASE 4: BAIXO (Higiene) 🟢

| ID | Falha | Ação Automatizada | Arquivos Gerados/Modificados | Melhoria |
|----|-------|-------------------|------------------------------|----------|
| **F13** | Scripts de debug expostos | Move para diretório protegido com .htaccess | `apps/web-app/src/backend/debug/`, `.htaccess` | Segurança operacional |
| **F14** | Comentários em idiomas misturados | Cria guia de estilo pt-BR + detector | `openspec/specs/CODING_STYLE.md`, `scripts/audit_comment_language.py` | Consistência e onboarding |
| **F15** | Ausência de padronização de formatação | Cria `.editorconfig`, `.prettierrc` e pre-commit hook | `.editorconfig`, `.prettierrc`, `.git/hooks/pre-commit` | Diffs limpos e consistência |

---

## 🚀 Guia de Execução

### Passo 1: Simulação (Obrigatório)

Antes de aplicar qualquer correção, execute em modo dry-run para revisar:

```bash
cd /workspace
python scripts/nexus_autofix.py --dry-run
```

**Output esperado:**
- Lista de 15 correções simuladas
- Arquivos que seriam criados/modificados
- Log em `scripts/nexus_autofix_YYYYMMDD_HHMMSS.log`

### Passo 2: Revisão Manual

Revise o relatório JSON gerado:
```bash
cat scripts/nexus_autofix_report_*.json | jq .
```

Pontos de atenção:
- Backups criados em `scripts/quarantine/`
- Alterações em `.gitignore`
- Modificações em arquivos de configuração

### Passo 3: Execução Real

Quando estiver pronto para aplicar as correções:

```bash
python scripts/nexus_autofix.py --execute
```

O script solicitará confirmação:
```
ATENÇÃO: Isso modificará arquivos no repositório. Continuar? (yes/no): yes
```

### Passo 4: Validação Pós-Correção

Após execução:

```bash
# 1. Verificar status do git
git status

# 2. Rodar quality gate
npm run gate

# 3. Testar setup local
npm run setup:mock

# 4. Validar testes smoke
php tests/crm_health_smoke_test.php
```

### Passo 5: Commit Estratégico

Recomenda-se fazer commits separados por fase:

```bash
# Fase 1: Segurança (CRÍTICO)
git add .gitignore apps/telegram-bot/main.py scripts/quarantine/
git commit -m "security: NEXUS AUTO-FIX F01-F04 - Blindagem de segredos e tokens"

# Fase 2: Arquitetura (ALTO)
git add infrastructure/database/migrations/ scripts/ci/ tests/integration/
git commit -m "feat: NEXUS AUTO-FIX F05-F08 - Fundações arquiteturais"

# Fase 3: Operacional (MÉDIO)
git add scripts/*.py apps/web-app/src/backend/api/config.php
git commit -m "refactor: NEXUS AUTO-FIX F09-F12 - Melhorias operacionais"

# Fase 4: Higiene (BAIXO)
git add .editorconfig .prettierrc openspec/specs/CODING_STYLE.md
git commit -m "style: NEXUS AUTO-FIX F13-F15 - Padronização e higiene"
```

---

## 📁 Estrutura de Arquivos Gerados

```
/workspace/
├── .gitignore                      # Atualizado (F01, F02)
├── .editorconfig                   # Novo (F15)
├── .prettierrc                     # Novo (F15)
│
├── apps/
│   ├── telegram-bot/
│   │   ├── main.py                 # Atualizado (F04, F12)
│   │   └── .env.example            # Novo (F04)
│   └── web-app/
│       ├── src/backend/
│       │   ├── api/config.php      # Atualizado (F10)
│       │   └── debug/              # Novo diretório (F13)
│       └── .env.example            # Atualizado (F03)
│
├── infrastructure/
│   └── database/migrations/
│       ├── migration_manifest.json # Novo (F05)
│       └── run_migrations.php      # Novo (F05)
│
├── openspec/
│   └── specs/
│       └── CODING_STYLE.md         # Novo (F14)
│
├── scripts/
│   ├── nexus_autofix.py            # Script principal (NOVO)
│   ├── nexus_autofix_*.log         # Log de execução (NOVO)
│   ├── nexus_autofix_report_*.json # Relatório (NOVO)
│   ├── quarantine/                 # Diretório seguro (NOVO)
│   │   └── *.backup                # Backups movidos (F02)
│   │
│   ├── ci/
│   │   └── validate_api_contracts.js  # Novo (F06)
│   │
│   ├── nexus_orchestrator.py       # Novo (F07)
│   ├── sync_versions.py            # Novo (F09)
│   ├── audit_docs_consistency.py   # Novo (F11)
│   └── audit_comment_language.py   # Novo (F14)
│
├── tests/
│   └── integration/
│       └── fullstack_integration_test.php  # Novo (F08)
│
└── .git/
    └── hooks/
        └── pre-commit              # Novo (F15)
```

---

## 🔒 Mecanismos de Segurança

### Backup Automático
Todos os arquivos modificados recebem backup com sufixo `.autofix_backup` antes da alteração.

### Quarentena para Sensíveis
Arquivos `.backup` e scripts de debug são movidos para `scripts/quarantine/` ao invés de deletados.

### Dry-Run Obrigatório
O script **sempre** executa em modo simulação se nenhum flag for fornecido, prevenindo modificações acidentais.

### Confirmação Explícita
Modo `--execute` requer confirmação verbal (`yes`) antes de prosseguir.

### Log Completo
Todas as ações são registradas em:
- Terminal (output colorido)
- Arquivo de log: `scripts/nexus_autofix_YYYYMMDD_HHMMSS.log`
- Relatório JSON: `scripts/nexus_autofix_report_YYYYMMDD_HHMMSS.json`

---

## 📈 Métricas de Sucesso

Após execução completa, verifique:

| Métrica | Antes | Depois Esperado |
|---------|-------|-----------------|
| Segredos no git | 2 arquivos .env | 0 (removidos do index) |
| Backups expostos | 1 arquivo .backup | 0 (em quarentena) |
| Tokens hardcoded | 1 token Telegram | 0 (exige env) |
| Migrações documentadas | 0 | 1 manifesto JSON |
| Validação de contratos | Manual | Automática no gate |
| Testes de integração | 0 | 1 suite completa |
| Scripts de debug expostos | 4 arquivos | 0 (em /debug protegido) |
| Padronização | Nenhuma | .editorconfig + Prettier |

---

## ⚠️ Riscos e Mitigações

| Risco | Probabilidade | Mitigação |
|-------|---------------|-----------|
| Perda de dados | Baixa | Backups automáticos antes de cada modificação |
| Quebra de funcionalidade | Média | Testes smoke pós-correção obrigatórios |
| Conflitos de merge | Média | Executar em branch dedicada antes de merge |
| Falso positivo em validações | Baixa | Revisão manual do relatório JSON |

---

## 🔄 Rollback (Se Necessário)

Cada correção gera backups. Para reverter:

```bash
# Restaurar backups
find scripts/ -name "*.autofix_backup" -exec bash -c 'mv "$1" "${1%.autofix_backup}"' _ {} \;

# Ou usar git para reverter tudo
git reset --hard HEAD
git clean -fd
```

---

## 📞 Suporte e Troubleshooting

### Erro: "TELEGRAM_BOT_TOKEN não configurado"
**Solução:** Definir variável de ambiente:
```bash
export TELEGRAM_BOT_TOKEN=seu_token_aqui
```

### Erro: "migration_manifest.json não encontrado"
**Solução:** Executar F05 primeiro ou rodar script completo novamente.

### Erro: "Nexus Gate falhou"
**Solução:** Verificar log específico em `scripts/nexus_autofix_*.log` e corrigir manualmente se necessário.

---

## ✅ Checklist de Conclusão

- [ ] Executado dry-run e revisado output
- [ ] Confirmado que backups foram criados
- [ ] Executado modo --execute com sucesso
- [ ] Validado com `npm run gate`
- [ ] Testado locally com `npm run setup:mock`
- [ ] Commits realizados por fase
- [ ] Branch mergeada e deploy testado

---

**Autor:** Nexus AI Governance  
**Versão:** 1.0  
**Data:** 2026-08-31  
**Status:** ✅ Pronto para Produção

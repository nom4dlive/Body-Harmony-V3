?? Regras Especializadas: Backend & Arquitetura de APIs PHP 8.4
Manual de Invariantes para Controllers Finos, Services Desacoplados, LazyDb/PDO, Autentica��o e Seguran�a de Neg�cio.


?? REGRA 6: Desacoplamento de Servi�os & Isolamento de Testes CLI (Service Decoupling)
Diretriz: Endpoints HTTP (api/v1/*.php) devem atuar estritamente como controladores finos de requisi��o/resposta. Nenhuma l�gica pura de transforma��o de dados, valida��o ou compila��o deve residir exclusivamente no escopo global do controller.
A��o:
Toda regra de neg�cio, convers�o de schemas ou compila��o de documentos deve residir em classes de servi�o dedicadas (BodyHarmony\Services\*).
Scripts de teste de fuma�a CLI (tests/*_smoke_test.php) devem invocar apenas classes de servi�o e helpers puros, nunca arquivos de controller que executam auth_check.php ou manipulam headers HTTP no escopo global.
Em testes de fuma�a CLI, utilizar classes de Mock PDO puro em mem�ria (MockPDO, MockStatement) com arrays associativos, evitando depend�ncia de drivers SQLite nativos que possam estar desativados no php.ini do ambiente de desenvolvimento.


?? REGRA 9: Invariant de Qualifica��o PJ da Licenciada (Contract PJ Invariant)
Diretriz: O par�grafo de qualifica��o da Licenciada nos contratos Body Harmony � estritamente Pessoa Jur�dica por padr�o. � proibido utilizar linguagem amb�gua como (ou pessoa f�sica habilitada) ou CNPJ/CPF no texto-base do par�grafo.
A��o:
O par�grafo oficial obrigat�rio �: "pessoa jur�dica de direito privado, inscrita no CNPJ sob o n� {{LICENCIADA_CNPJ_CPF}}, com sede na ... neste ato representada por sua s�cia".
O campo no formul�rio do Wizard deve ser rotulado como "CNPJ da Licenciada (Pessoa Jur�dica)" com placeholder 00.000.000/0001-00.
A �nica forma de inserir qualifica��o PF � atrav�s do token {{CLAUSULA_TRANSICAO_CNPJ}}, injetado ao final do par�grafo PJ quando o toggle de abertura de CNPJ for ativado pelo operador.


?? REGRA 10: Completude Bidirecional de Assinaturas (Dual-Signature Invariant)
Diretriz: O status SIGNED em um contrato de licenciamento � uma condi��o bidirecional, s� atingida quando ambas as partes (Licenciante e Licenciada) tiverem assinado digitalmente.
A��o:
Em sign.php e qualquer endpoint de processamento de assinatura, verificar a presen�a de ambos os tipos (LICENCIANTE e LICENCIADA) na tabela contract_signatures antes de atribuir status = 'SIGNED'.
Enquanto apenas uma parte assinou, o status permanece PENDING_SIGNATURE.


?? REGRA 11: Invariant de Dados Oficiais da Licenciante (Licenciante Official Data Invariant)
Diretriz: Os dados cadastrais da LICENCIANTE (propriet�ria e outorgante da marca Body Harmony) s�o estritamente institucionais, imut�veis e baseados no cart�o CNPJ oficial da Receita Federal.
A��o:
Em todos os templates, seeds, migrations e documentos compilados, a qualifica��o obrigat�ria da Licenciante �: "BODY HARMONY ELETROESTIMULA��O LTDA., pessoa jur�dica de direito privado, inscrita no CNPJ sob o n� 68.016.506/0001-22, com sede na Rua Sebasti�o da Silva Leite, n� 456, Vila Ros�ngela, CEP 19.814-370, na cidade de Assis/SP, neste ato representada por sua s�cia administradora JOSELENE APARECIDA DA SILVA, brasileira, empres�ria, portadora do CPF n� 362.082.328-64, residente e domiciliada na Rua Sebasti�o da Silva Leite, n� 456, Assis/SP".


?? REGRA 14: Invariante de Requisi��es Autenticadas e Chave Can�nica de Sess�o (Authenticated Client Invariant)
Diretriz: A sess�o administrativa � estritamente armazenada no LocalStorage sob a chave 'bh_auth' ({ token: string, user: object }).
A��o: Utilizar estritamente o cliente central api ou a fun��o request() de src/services/api.js, assegurando a inje��o autom�tica do cabe�alho Authorization: Bearer <token>.


??? REGRA 15: Invariante de Roteamento para Identificadores Polim�rficos (Polymorphic Identifier Invariant)
Diretriz: No roteador customizado da API PHP (Core/Router.php), o token {id} converte exclusivamente para regex num�rica ([0-9]+). Identificadores polim�rficos (tok_XX, UUIDs de contratos bh-lic-*) devem utilizar placeholders como {identifier}, {token} ou {uuid}.


?? REGRA 24: Invariante de Paridade nos Servi�os de API Frontend (Frontend API Client Parity Invariant)
Diretriz: Todos os servi�os exportados em src/services/api.js devem manter 100% de paridade com os m�todos consumidos nos componentes React, com suporte a sobrecarga defensiva.


??? REGRA 26: Invariante de Transcri��o Verbatim no Open Notebook (Verbatim Transcription Invariant)
Diretriz: Ingest�o de aulas do LMS no Open Notebook deve assegurar fidelidade palavra por palavra com blocos temporais [MM:SS - MM:SS], extra��o FFmpeg 16 kHz e registro de aresta reference no grafo SurrealDB.


?? REGRA 47: Invariante de Streaming de Anexos Privados (Private Storage Streaming Invariant)
Diretriz: Arquivos privados (private_uploads/*) devem ser servidos exclusivamente por endpoints autenticados da API (ex: GET /api/v1/admin/onboarding/{id}/document/{type}) com inje��o de token em query string para downloads via window.open.


?? REGRA 51: Invariante de Auto-Cura da Identidade da Licenciante em PDFs (Licenciante Digital Signature Invariant)
Diretriz: Constantes p�blicas can�nicas declaradas em ContractPdfService::LICENCIANTE_* devem for�ar a identidade institucional em chancelas e PDFs (MP 2.200-2/2001 e Lei 14.063/2020).


?? REGRA 52: Invariante de Retifica��o Inteligente de Contratos Assinados (Signed Contract Rectification Invariant)
Diretriz: Contratos SIGNED que sofram altera��es cosm�ticas preservam o status; altera��es de cl�usulas cr�ticas da Licenciada transicionam automaticamente para PENDING_SIGNATURE.


? REGRA 57: Invariante de Endpoints de Leitura Pura (Pure Read-Only Endpoints Invariant)
Diretriz: Endpoints GET devem responder em <= 50ms como consultas puras. Sincroniza��es em lote residem estritamente em endpoints POST.


?? REGRA 59: Invariante de Normaliza��o e Resolu��o do 9� D�gito (Brazilian Phone Normalization Invariant)
Diretriz: Consultas telef�nicas devem buscar defensivamente por $clean, $suffix8 e $suffix9 com matching flex�vel no MySQL.


??? REGRA 62: Invariante de Credenciais de Usu�rios (password_hash Invariant)
Diretriz: Na tabela admin_users, a coluna f�sica de senhas criptografadas � estritamente password_hash.


?? REGRA 66: Invariante de Gateway de Pagamentos Asaas (Asaas Gateway & Payment Invariant)
Diretriz: Integra��es Asaas devem suportar detec��o autom�tica de Sandbox ($aact_hmlg_), desativa��o de e-mails gen�ricos (notificationDisabled: true), objeto creditCardHolderInfo e valida��o com hash_equals() no webhook.


?? REGRA 70: Invariante de Acesso Defensivo a Agrega��es PDO em PHP 8.4 (PDO Summary Aggregations Invariant)
Diretriz: Toda leitura de agrega��o de banco de dados deve utilizar operador coalescente ?? 0 ou ?? null para prevenir TypeError fatal no PHP 8.4.


?? REGRA 73: Invariante de Preserva��o de Timestamps no CRM (CRM History Sync Invariant)
Diretriz: Ingest�o retroativa de mensagens no Chatwoot deve preservar o timestamp Unix original no campo created_at.

🛡️ REGRA 74: Invariante de Validação de Cupons Monouso por CPF (One-Per-CPF Coupon Invariant)
Diretriz: Cupons de desconto com a flag one_per_cpf = 1 devem validar a unicidade consultando a tabela de inscrições/pedidos com payment_status NOT IN ('CANCELLED', 'REFUNDED').
Ação: A checagem deve normalizar o CPF removendo caracteres não numéricos (preg_replace('/\D/', '', )) e validar tanto a versão limpa quanto a formatada (customer_cpf = ? OR customer_cpf = ?), impedindo a geração concorrente ou reuso de múltiplas ordens de pagamento com o mesmo cupom promocional.

🛡️ REGRA 75: Invariante de Dados Reais & Fim de Mocks Forenses (Truth in Forensics & Telemetry Invariant)
Diretriz: É expressamente proibido utilizar dados mockados, métricas fixas ou fallbacks com números simulados (ex: 96.8%, 48.5h, 183 ações, 145ms, 95% de bateria) em dashboards de auditoria e telemetria de canais.
Ação:
- Toda métrica exibida na Trilha Forense (assertividade, horas economizadas, ações autônomas, latência média) deve ser agregada dinamicamente via SQL a partir da tabela `crm_hermes_audit_trail`.
- Quando uma linha ou canal estiver desconectado (`status !== 'CONNECTED'`), o status de bateria/sinal deve ser estritamente `-- (Desconectado)` e nunca porcentagens presumidas.
- Quando o banco de dados não possuir registros, exibir empty-state limpo informando que os dados surgirão com o uso, sem inserir logs falsos.

🛡️ REGRA 76: Invariante de Instâncias e Timeouts na Evolution API v2 (Baileys Resilient Lifecycle Invariant)
Diretriz: A integração com instâncias WhatsApp na Evolution API v2 exige configuração explícita de engine Baileys e timeouts HTTP defensivos para acomodar a inicialização de sockets remotos.
Ação:
- O payload de criação (`/instance/create`) deve conter obrigatoriamente `'integration' => 'WHATSAPP-BAILEYS'` e `'qrcode' => true`.
- Requisições cURL para geração de QR Code ou pareamento devem ter timeout mínimo de 15 segundos (`CURLOPT_TIMEOUT => 15`, `CURLOPT_CONNECTTIMEOUT => 8`).
- Ao consultar o endpoint `/instance/connect/{instance}`, caso a API retorne HTTP 404 (instância inexistente), o serviço deve auto-criar a instância transparentemente antes de retornar a resposta ao usuário.

🛡️ REGRA 77: Invariante de Trilha Forense Neural Universal (Hermes AI Audit Invariant)
Diretriz: Toda inferência, chamada de ferramenta ou síntese executada pelo agente neural Hermes no CRM deve produzir um registro forense em tempo real na tabela `crm_hermes_audit_trail`.
Ação:
- Os métodos `testPrompt()`, `generateCopilotDraft()`, `internalAssistantChat()`, `generateDossierSummary()` e `dispatchProactiveMessage()` devem invocar o helper `$this->logAudit(...)`.
- O registro deve conter: `conversation_id`, `line_code`, `action_type`, `user_input`, `ai_output`, `tool_name`, `sentiment_status`, `execution_time_ms` e `created_at`.
- A tabela `crm_hermes_audit_trail` deve ser verificada com `CREATE TABLE IF NOT EXISTS` defensivo antes das operações.

🛡️ REGRA 78: Invariante de Cartão de Terceiros, PJ (CNPJ) e Fallback 3DS (Third-Party Card & 3DS Invariant)
Diretriz: Em qualquer fluxo de cobrança por cartão de crédito (Congressos, Loja, Licenciamento), o sistema deve neutralizar falsos-positivos de antifraude e oferecer contingência imediata caso o banco emissor exija validação no app.
Ação:
- **Alinhamento Cadastral de Risco no Gateway**: Quando o pagador for diferente do titular da inscrição/compra (`isDifferentHolder = true`), o cliente na adquirente (Asaas) DEVE ser criado/vinculado sob os dados cadastrais do titular do cartão (Nome, CPF/CNPJ, Telefone). O produto ou ingresso permanece registrado no nome da aluna/participante. Isso zera o score de divergência cadastral que causa a recusa arbitrária da compra.
- **Suporte Híbrido Obrigatório a CPF e CNPJ**: Formulários e payloads de faturamento devem aceitar tanto CPF (11 dígitos) quanto CNPJ (14 dígitos) para viabilizar compras com cartões corporativos de clínicas/empresas.
- **Enriquecimento de Endereço Real**: Proibido enviar números fictícios como `"100"`. Integrar preenchimento automático por CEP (ViaCEP) e exigir o número real da fatura com autofoco.
- **Geração Automática de Fallback 3DS**: Quando a transação direta for recusada pelo banco emissor, o backend não deve simplesmente falhar. Deve gerar uma fatura hosted com 3DS (`createHostedInvoice`), registrar a ordem como `PENDING` com método `card_fallback_3ds`, e retornar `can_retry_with_3ds: true` e `fallback_invoice_url`.

🛡️ REGRA 79: Invariante de Fidelidade de Mídia e Zero-Mock STT (Audio & Whisper STT Invariant)
Diretriz: É expressamente proibido utilizar dados simulados (mock strings estáticas aleatórias) em pipelines de processamento de áudio, transcrição ou auditoria de IA em produção.
Ação:
- Toda transcrição de áudio deve ser processada por APIs neurais reais (Groq `whisper-large-v3-turbo` ou OpenAI `whisper-1`), baixando o arquivo de áudio para armazenamento temporário e despachando via `multipart/form-data`.
- Em caso de indisponibilidade de chave de API ou falha de rede, retornar mensagem de status transparente e descritiva (ex: *"Áudio recebido via WhatsApp (Transcritor Whisper aguardando processamento)"*), jamais gerando texto inventado.
- Para garantir compatibilidade com PHP FastCGI em produção, a leitura de chaves de API sensíveis deve utilizar fallback triplo:
  `getenv('KEY') ?: ($_ENV['KEY'] ?? ($_SERVER['KEY'] ?? ''))`.

🛡️ REGRA 80: Invariante de Estrutura Canônica de Parcelamento e Antecipação de Cartão no Asaas (Asaas Installment & Anticipation Invariant)
Diretriz: Todo parcelamento de cartão de crédito no gateway Asaas (seja cobrança direta transparente ou fatura hospedada de contingência 3DS) deve ser criado para garantir 100% de elegibilidade imediata para antecipação de recebíveis no painel do Asaas.
Ação:
- **Proibição Estrita de 'UNDEFINED' em Links de Cartão**: Links de contingência e faturas hospedadas (`createHostedInvoice`) originadas do fluxo de cartão de crédito NUNCA devem ser enviadas com `'billingType' => 'UNDEFINED'`. O uso de `UNDEFINED` com múltiplas parcelas instrui o Asaas a criar um Carnê Multi-Método aberto (boletos mensais avulsos), que bloqueia a antecipação de recebíveis com a mensagem: *"Não é possível antecipar este parcelamento pois a forma de pagamento de uma ou mais parcelas não é Cartão de Crédito"*. O campo DEVE ser estritamente `'billingType' => 'CREDIT_CARD'`.
- **Estrutura Canônica de Payload de Parcelamento**:
  - Para cobranças à vista (1x): Enviar o campo `value` e NUNCA enviar `installmentCount` ou `installmentValue`.
  - Para cobranças parceladas (2x a 12x): OMITIR obrigatoriamente o campo `value` do payload e enviar estritamente `installmentCount` e `installmentValue` (ou `totalValue`). Enviar `value` juntamente com `installmentCount` gera inconsistência de precificação na API do Asaas.
- **Garantia de Anticipabilidade**: Seguindo este contrato estrito, o Asaas confirma o parcelamento como compra de cartão de crédito de captura integral, registrando em cada parcela futura o atributo oficial `"anticipable": true` e liberando o botão de antecipação imediata no painel.
- **Validação de Dígitos Verificadores em Testes Sandbox**: O Sandbox do Asaas valida matematicamente o algoritmo de módulo 11 de CPF e CNPJ. Todo teste de estresse contra a API Sandbox real do Asaas DEVE utilizar geradores de documentos com dígitos verificadores válidos.

🛡️ REGRA 81: Invariante de Lock Atômico de Cotas e Rate Limiting de IA (Atomic AI Quota & Rate Limit Invariant)
Diretriz: Todo consumo de créditos ou inferência de agentes de IA (Dra. Harmony AI, SmartBook, Hermes) atribuído a usuários deve ser executado sob lock de concorrência transacional no MySQL, garantindo proteção contra estouro de cotas (race conditions) e bloqueio 429 determinístico.
Ação:
- **Lock Transacional Obrigatório**: O cálculo de saldo e o débito de créditos DEVEM ser executados dentro de transação PDO com `SELECT ... FOR UPDATE` sobre a linha da licenciada/usuário.
- **Validação Prévia de Status (403)**: Se a aluna estiver inativa (`is_active = 0`) ou não possuir permissão beta ativa (`ai_notebook_beta_enabled = 0`), bloquear imediatamente com HTTP 403 Forbidden.
- **Bloqueio Estrito por Exaustão de Cota (429)**: Se a soma de créditos consumidos no dia (`SUM(credits_spent) WHERE created_at >= CURDATE()`) atingir o limite (`ai_notebook_credits_limit`), lançar exceção com código 429 contendo payload JSON com `quota_exceeded: true`, dados de consumo e URL do WhatsApp da coordenação (`wa.me`) para recarga imediata.
- **Testes In-Memory**: Toda suíte de testes de serviços de IA deve emular PDO em memória para validar cenários 403, 429 e débito atômico sem dependência de banco de dados externo.

🛡️ REGRA 82: Invariante de Termos Dinâmicos de Módulos, Hard Gate In-App e Zero-Manual-Testing Gate (Student Module Term & E2E Gate Invariant)
Diretriz: A concessão de acesso a módulos ou cursos avulsos deve auto-gerar termos de ciência com bloqueio estrito de consumo até a assinatura eletrônica, validada por esteira de testes sintéticos automatizados pré e pós-deploy sem dependência de testes manuais.
Ação:
- **Auto-Geração Sem Fricção**: Conceder acesso a um módulo no Portal Gestor (`grantModuleAccess`) DEVE invocar automaticamente `ensureStudentModuleContract()`, instanciando o template `termo-ciencia-modulo-individual` com status `PENDING_SIGNATURE` e `sign_token` exclusivo.
- **Hard Gate no Portal da Aluna**: O controller de aulas (`AlunaLmsController::lessons`) DEVE inspecionar se há termos pendentes para a aluna no módulo. Se houver, retornar `locked: true`, `has_pending_term: true` e payload do termo. O frontend deve renderizar o modal de assinatura in-app (`AlunaTermSignModal`), bloqueando a reprodução de vídeo até o envio da assinatura com base64 e metadados de auditoria.
- **Zero Testes Manuais & Esteira E2E Obrigatória**: Toda alteração no fluxo de contratos ou termos DEVE ser validada por testes sintéticos automatizados (`ContractSigningSecurityTest.php` e `test_contract_signing_e2e.ps1`). Esses testes devem ser acoplados obrigatoriamente ao `nexus_gate.ps1` (pré-deploy) e ao Deep Smoke Test de `deploy-hostinger.ps1` (pós-deploy), validando compilação mPDF, Folha de Chancela Jurídica (MP 2.200-2/2001 e Lei 14.063/2020), QR Code e Hash SHA-256.
- **Resolução Resiliente do Autoloader Composer**: `ContractPdfService` DEVE conter resolução defensiva com múltiplos fallbacks para o `vendor/autoload.php` (`__DIR__ . '/../../../vendor/autoload.php'`, `__DIR__ . '/../../../../build/public_html/vendor/autoload.php'`), garantindo que mPDF e classes auxiliares carreguem sem dependência do ponto de entrada da execução (CLI, Webhook, API ou Cron).

🛡️ REGRA 83: Invariante de Contingência Tripla de Checkout Asaas (Direct PIX Key & Hosted Invoice First)
Diretriz: Em checkouts de eventos, ingressos e alta conversão, o gateway Asaas deve operar com contingência tripla para blindar o fluxo de vendas contra rejeições de adquirente por titularidade de terceiros e instabilidade do SPI/Bacen.
Ação:
- **PIX Tri-Channel**: Toda criação de cobrança PIX deve retornar o QR Code dinâmico, o Copia e Cola, e a URL da fatura oficial hospedada (`invoiceUrl`), além de disponibilizar a chave aleatória direta da conta jurídica para contingência imediata de transferência bancária manual.
- **Cartão Hosted-First sem Carnê**: Para pagamentos parcelados onde haja risco de titularidade divergente (ex: uso de cartão da mãe ou parente), o backend deve gerar a cobrança avulsa com `billing_type: UNDEFINED` sem gerar carnês futuros, retornando `invoice_url` para conclusão no ambiente blindado 3DS do Asaas.

🛡️ REGRA 84: Invariante de Resolução Multi-Caminho de Configurações de Ambiente (.env Multi-Path Invariant)
Diretriz: O carregador de variáveis de ambiente (`EnvLoader::load()`) no backend monolítico deve suportar nativamente e de forma defensiva todos os contextos de execução (Produção Hostinger `/public_html/api/.env`, `/public_html/.env`, raiz de domínio `/domains/.../.env` e ambientes locais de desenvolvimento). É estritamente proibido restringir o carregador a uma profundidade única de diretório (`dirname(__DIR__, N)`).
Ação:
O array canônico de caminhos em `apps/web-app/src/backend/api/config.php` deve sempre manter a cadeia:
```php
$envPaths = [
    __DIR__ . '/.env',                 // Hostinger Produção: /public_html/api/.env
    dirname(__DIR__) . '/.env',        // Hostinger Web Root: /public_html/.env
    dirname(__DIR__, 2) . '/.env',     // Hostinger Domain Root
    dirname(__DIR__, 3) . '/.env',     // Web-App Local Root
    dirname(__DIR__, 4) . '/.env',     // Repositório Local
    dirname(__DIR__, 5) . '/.env'      // Workspace Raiz
];
```

🛡️ REGRA 85: Invariante de Validação de Sessão Desacoplada e Modo Admin Ghost (Decoupled Device Session & Ghost Admin Invariant)
Diretriz: Em ecossistemas com múltiplos portais (LMS Licenciadas / Portal Gestor) onde administradores podem logar ou simular sessões de alunas/licenciadas com identificadores virtuais negativos (`id < 0`), a validação de sessão (`X-Device-Token` e `validateLicenciadaSession`) deve obrigatoriamente utilizar consultas SQL desacopladas em 2 etapas em vez de `LEFT JOIN` rígido sobre colunas de tabelas de perfil.
Ação:
1. **Etapa 1 (Token Check)**: Consultar `licenciada_devices` diretamente pelo `device_token`.
2. **Etapa 2 (Identity Resolution)**:
   - Se `licenciada_id < 0`, resolver o usuário em `admin_users` pelo ID absoluto (`abs($licenciada_id)`), preservando seu papel de administrador e liberando acesso total.
   - Se `licenciada_id > 0`, resolver o usuário na tabela `licenciadas`.
3. **Compatibilidade Simétrica de Payload**: Endpoints de validação de sessão devem sempre retornar tanto a chave `'licenciada'` quanto `'student'`, blindando o handshake dos contextos React (`LicenciadaAuthContext`) contra regressões de chave de payload.



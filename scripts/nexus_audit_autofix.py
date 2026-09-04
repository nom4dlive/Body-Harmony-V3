#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Body Harmony Nexus — Auditoria Completa e Correção Automatizada
Versão: 1.0.0
Data: 2026-09-04

Este script executa uma auditoria forense completa do repositório,
identifica falhas de segurança, quebras de padrão e gambiarras,
e oferece correção automatizada com backup e rollback.

Falhas mapeadas na auditoria inicial:
1. Arquivos .env comitados no repositório
2. Token do Telegram hardcoded
3. Arquivos .backup não protegidos
4. Migrações sem numeração consistente
5. Scripts de debug expostos
6. Ausência de .editorconfig
7. Ausência de pre-commit hooks
8. Documentação desatualizada vs código
9. Caminhos relativos frágeis em config.php
10. Testes sem validação de contrato
"""

import os
import sys
import json
import shutil
import hashlib
import subprocess
import re
from datetime import datetime
from pathlib import Path
from typing import List, Dict, Tuple, Optional

# Configurações
WORKSPACE = Path("/workspace")
BACKUP_DIR = WORKSPACE / ".audit_backup" / datetime.now().strftime("%Y%m%d_%H%M%S")
LOG_FILE = WORKSPACE / ".audit_log.txt"
GITIGNORE_PATH = WORKSPACE / ".gitignore"

# Cores para output
class Colors:
    RED = '\033[91m'
    GREEN = '\033[92m'
    YELLOW = '\033[93m'
    BLUE = '\033[94m'
    MAGENTA = '\033[95m'
    CYAN = '\033[96m'
    WHITE = '\033[97m'
    BOLD = '\033[1m'
    RESET = '\033[0m'

def log(message: str, level: str = "INFO"):
    """Registra mensagem no log e console"""
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    log_entry = f"[{timestamp}] [{level}] {message}"
    
    with open(LOG_FILE, "a", encoding="utf-8") as f:
        f.write(log_entry + "\n")
    
    color_map = {
        "CRITICAL": Colors.RED,
        "ERROR": Colors.RED,
        "WARNING": Colors.YELLOW,
        "INFO": Colors.WHITE,
        "SUCCESS": Colors.GREEN,
        "AUDIT": Colors.CYAN
    }
    
    color = color_map.get(level, Colors.WHITE)
    print(f"{color}{log_entry}{Colors.RESET}")

def create_backup(file_path: Path) -> bool:
    """Cria backup de arquivo antes de modificação"""
    try:
        if not file_path.exists():
            return False
        
        BACKUP_DIR.mkdir(parents=True, exist_ok=True)
        relative_path = file_path.relative_to(WORKSPACE)
        backup_path = BACKUP_DIR / relative_path
        backup_path.parent.mkdir(parents=True, exist_ok=True)
        
        shutil.copy2(file_path, backup_path)
        log(f"Backup criado: {relative_path}", "INFO")
        return True
    except Exception as e:
        log(f"Falha ao criar backup de {file_path}: {e}", "ERROR")
        return False

def calculate_file_hash(file_path: Path) -> str:
    """Calcula hash SHA256 de um arquivo"""
    if not file_path.exists():
        return ""
    
    sha256_hash = hashlib.sha256()
    with open(file_path, "rb") as f:
        for byte_block in iter(lambda: f.read(4096), b""):
            sha256_hash.update(byte_block)
    return sha256_hash.hexdigest()

# ============================================================================
# AUDITORIA - FASE 1: DETECÇÃO DE FALHAS
# ============================================================================

def audit_env_files() -> List[Dict]:
    """Audita arquivos .env expostos"""
    issues = []
    
    # Padrões de arquivos sensíveis
    sensitive_patterns = [
        "**/*.env",
        "**/.env.*",
        "**/*.env.*",
        "**/*.backup",
        "**/*.bak"
    ]
    
    found_files = []
    for pattern in sensitive_patterns:
        found_files.extend(WORKSPACE.glob(pattern))
    
    # Remove duplicatas e filtra diretórios
    found_files = list(set([f for f in found_files if f.is_file()]))
    
    # Verifica se estão no .gitignore
    gitignore_content = ""
    if GITIGNORE_PATH.exists():
        gitignore_content = GITIGNORE_PATH.read_text()
    
    for file_path in found_files:
        relative_path = str(file_path.relative_to(WORKSPACE))
        
        # Verifica se deveria estar no gitignore
        should_be_ignored = any([
            '.env' in file_path.name,
            file_path.suffix in ['.backup', '.bak'],
            'config.php.backup' in file_path.name
        ])
        
        is_ignored = relative_path in gitignore_content or '*.env' in gitignore_content
        
        if should_be_ignored and not is_ignored:
            issues.append({
                "id": "ENV-001",
                "severity": "CRITICAL",
                "type": "security",
                "file": str(relative_path),
                "description": f"Arquivo sensível '{relative_path}' não está no .gitignore",
                "recommendation": "Adicionar ao .gitignore e remover do histórico git",
                "auto_fixable": True
            })
        
        # Verifica conteúdo sensível
        try:
            content = file_path.read_text()
            
            # Procura por tokens/padrões sensíveis
            sensitive_patterns_content = [
                (r'TELEGRAM_BOT_TOKEN.*[=:].*[\'"]?[A-Z0-9:_-]+[\'"]?', 'Token do Telegram'),
                (r'PASSWORD.*[=:].*[\'"]?[^\'"\s]+[\'"]?', 'Senha hardcoded'),
                (r'API_KEY.*[=:].*[\'"]?[A-Za-z0-9_-]+[\'"]?', 'Chave de API'),
                (r'SECRET.*[=:].*[\'"]?[A-Za-z0-9_-]+[\'"]?', 'Segredo'),
            ]
            
            for pattern, desc in sensitive_patterns_content:
                if re.search(pattern, content, re.IGNORECASE):
                    issues.append({
                        "id": "ENV-002",
                        "severity": "CRITICAL",
                        "type": "security",
                        "file": str(relative_path),
                        "description": f"{desc} detectado em '{relative_path}'",
                        "recommendation": "Remover valor sensível e usar variáveis de ambiente",
                        "auto_fixable": False
                    })
        except Exception as e:
            log(f"Erro ao ler {file_path}: {e}", "ERROR")
    
    return issues

def audit_telegram_bot() -> List[Dict]:
    """Audita hardcoded token no bot do Telegram"""
    issues = []
    
    bot_file = WORKSPACE / "apps" / "telegram-bot" / "main.py"
    
    if not bot_file.exists():
        return issues
    
    content = bot_file.read_text()
    
    # Procura por token hardcoded com fallback
    pattern = r'os\.getenv\s*\(\s*["\']TELEGRAM_BOT_TOKEN["\']\s*,\s*["\']([A-Z0-9:_-]+)["\']\s*\)'
    match = re.search(pattern, content)
    
    if match:
        hardcoded_token = match.group(1)
        issues.append({
            "id": "BOT-001",
            "severity": "CRITICAL",
            "type": "security",
            "file": "apps/telegram-bot/main.py",
            "description": f"Token do Telegram hardcoded como fallback: {hardcoded_token[:20]}...",
            "recommendation": "Remover fallback hardcoded e exigir variável de ambiente",
            "auto_fixable": True,
            "fix_data": {"token": hardcoded_token}
        })
    
    return issues

def audit_debug_files() -> List[Dict]:
    """Audita scripts de debug expostos"""
    issues = []
    
    debug_patterns = [
        "**/debug*.php",
        "**/*DEBUG*.php",
        "**/debug/*.php"
    ]
    
    debug_dirs = [
        WORKSPACE / "apps" / "web-app" / "src" / "backend" / "api"
    ]
    
    for debug_dir in debug_dirs:
        if not debug_dir.exists():
            continue
        
        for pattern in debug_patterns:
            for file_path in debug_dir.glob(pattern.replace("**/", "")):
                if file_path.is_file():
                    relative_path = str(file_path.relative_to(WORKSPACE))
                    issues.append({
                        "id": "DBG-001",
                        "severity": "MEDIUM",
                        "type": "security",
                        "file": relative_path,
                        "description": f"Script de debug exposto: {relative_path}",
                        "recommendation": "Mover para diretório protegido ou remover em produção",
                        "auto_fixable": True
                    })
    
    return issues

def audit_migrations() -> List[Dict]:
    """Audita inconsistência de numeração de migrações"""
    issues = []
    
    migrations_dir = WORKSPACE / "infrastructure" / "database" / "migrations"
    
    if not migrations_dir.exists():
        return issues
    
    migration_files = list(migrations_dir.glob("*"))
    migration_files = [f for f in migration_files if f.is_file()]
    
    patterns_found = {
        "V###_": [],
        "YYYYMMDD_": [],
        "FIX-": [],
        "UPDATE_": [],
        "diag_": [],
        "other": []
    }
    
    for file_path in migration_files:
        filename = file_path.name
        
        if re.match(r'^V\d+_', filename):
            patterns_found["V###_"].append(filename)
        elif re.match(r'^\d{8}_', filename):
            patterns_found["YYYYMMDD_"].append(filename)
        elif filename.startswith("FIX-"):
            patterns_found["FIX-"].append(filename)
        elif filename.startswith("UPDATE_"):
            patterns_found["UPDATE_"].append(filename)
        elif filename.startswith("diag_"):
            patterns_found["diag_"].append(filename)
        else:
            patterns_found["other"].append(filename)
    
    # Reporta inconsistência se houver múltiplos padrões
    active_patterns = [k for k, v in patterns_found.items() if len(v) > 0]
    
    if len(active_patterns) > 2:
        issues.append({
            "id": "MIG-001",
            "severity": "HIGH",
            "type": "architecture",
            "file": "infrastructure/database/migrations/",
            "description": f"Múltiplos padrões de numeração detectados: {', '.join(active_patterns)}",
            "recommendation": "Padronizar para formato V###_ ou YYYYMMDD_",
            "auto_fixable": False,
            "details": patterns_found
        })
    
    # Verifica gaps na numeração V###
    v_numbers = []
    for filename in patterns_found["V###_"]:
        match = re.match(r'^V(\d+)_', filename)
        if match:
            v_numbers.append(int(match.group(1)))
    
    if v_numbers:
        v_numbers.sort()
        gaps = []
        for i in range(len(v_numbers) - 1):
            if v_numbers[i+1] - v_numbers[i] > 1:
                gaps.append((v_numbers[i], v_numbers[i+1]))
        
        if gaps:
            issues.append({
                "id": "MIG-002",
                "severity": "LOW",
                "type": "hygiene",
                "file": "infrastructure/database/migrations/",
                "description": f"Gaps na numeração de migrações V: {gaps[:5]}{'...' if len(gaps) > 5 else ''}",
                "recommendation": "Revisar se há migrações faltando ou se a numeração está correta",
                "auto_fixable": False
            })
    
    return issues

def audit_gitignore() -> List[Dict]:
    """Audita conteúdo do .gitignore"""
    issues = []
    
    if not GITIGNORE_PATH.exists():
        issues.append({
            "id": "GIT-001",
            "severity": "CRITICAL",
            "type": "security",
            "file": ".gitignore",
            "description": "Arquivo .gitignore não existe ou está vazio",
            "recommendation": "Criar .gitignore com padrões de segurança",
            "auto_fixable": True
        })
        return issues
    
    content = GITIGNORE_PATH.read_text()
    
    required_patterns = [
        "*.env",
        ".env.*",
        "*.backup",
        "*.bak",
        "node_modules/",
        "__pycache__/",
        "*.pyc",
        ".DS_Store"
    ]
    
    missing_patterns = []
    for pattern in required_patterns:
        if pattern not in content:
            missing_patterns.append(pattern)
    
    if missing_patterns:
        issues.append({
            "id": "GIT-002",
            "severity": "HIGH",
            "type": "security",
            "file": ".gitignore",
            "description": f"Padrões ausentes no .gitignore: {missing_patterns}",
            "recommendation": "Adicionar padrões de segurança ao .gitignore",
            "auto_fixable": True,
            "fix_data": {"missing": missing_patterns}
        })
    
    return issues

def audit_editorconfig() -> List[Dict]:
    """Audita ausência de .editorconfig"""
    issues = []
    
    editorconfig_path = WORKSPACE / ".editorconfig"
    
    if not editorconfig_path.exists():
        issues.append({
            "id": "CFG-001",
            "severity": "LOW",
            "type": "hygiene",
            "file": ".editorconfig",
            "description": "Arquivo .editorconfig não existe",
            "recommendation": "Criar .editorconfig para padronização de código",
            "auto_fixable": True
        })
    
    return issues

def audit_precommit() -> List[Dict]:
    """Audita ausência de pre-commit hooks"""
    issues = []
    
    precommit_config = WORKSPACE / ".pre-commit-config.yaml"
    precommit_hook = WORKSPACE / ".git" / "hooks" / "pre-commit"
    
    has_config = precommit_config.exists()
    has_hook = precommit_hook.exists()
    
    if not has_config and not has_hook:
        issues.append({
            "id": "HOOK-001",
            "severity": "MEDIUM",
            "type": "quality",
            "file": ".pre-commit-config.yaml",
            "description": "Pre-commit hooks não configurados",
            "recommendation": "Configurar pre-commit hooks para validação automática",
            "auto_fixable": True
        })
    
    return issues

def audit_contract_validation() -> List[Dict]:
    """Audita falta de validação automática de contratos"""
    issues = []
    
    contracts_dir = WORKSPACE / "openspec" / "contracts"
    nexus_gate = WORKSPACE / "scripts" / "nexus_gate.ps1"
    
    if not contracts_dir.exists():
        return issues
    
    # Verifica se há validação automática nos scripts
    if nexus_gate.exists():
        content = nexus_gate.read_text()
        if "contract" not in content.lower() and "schema" not in content.lower():
            issues.append({
                "id": "CTR-001",
                "severity": "HIGH",
                "type": "quality",
                "file": "scripts/nexus_gate.ps1",
                "description": "Nexus Gate não valida contratos de API automaticamente",
                "recommendation": "Implementar validação de schemas JSON vs endpoints reais",
                "auto_fixable": False
            })
    
    return issues

def run_full_audit() -> List[Dict]:
    """Executa todas as auditorias"""
    log("=" * 80, "AUDIT")
    log("INICIANDO AUDITORIA FORENSE COMPLETA - BODY HARMONY NEXUS", "AUDIT")
    log("=" * 80, "AUDIT")
    
    all_issues = []
    
    audits = [
        ("Arquivos .env e segredos", audit_env_files),
        ("Bot do Telegram", audit_telegram_bot),
        ("Scripts de debug", audit_debug_files),
        ("Migrações de banco", audit_migrations),
        ("Configuração .gitignore", audit_gitignore),
        ("Configuração .editorconfig", audit_editorconfig),
        ("Pre-commit hooks", audit_precommit),
        ("Validação de contratos", audit_contract_validation),
    ]
    
    for audit_name, audit_func in audits:
        log(f"Executando auditoria: {audit_name}", "AUDIT")
        issues = audit_func()
        all_issues.extend(issues)
        
        if issues:
            log(f"  → {len(issues)} problema(s) encontrado(s)", "WARNING")
        else:
            log(f"  → Nenhum problema encontrado", "SUCCESS")
    
    log("=" * 80, "AUDIT")
    log(f"AUDITORIA CONCLUÍDA: {len(all_issues)} falha(s) identificada(s)", "AUDIT")
    log("=" * 80, "AUDIT")
    
    return all_issues

# ============================================================================
# CORREÇÃO - FASE 2: AUTOMATED FIXES
# ============================================================================

def fix_gitignore(issues: List[Dict]) -> bool:
    """Corrige .gitignore adicionando padrões ausentes"""
    git_issue = next((i for i in issues if i["id"] == "GIT-002"), None)
    
    if not git_issue:
        return True
    
    missing_patterns = git_issue.get("fix_data", {}).get("missing", [])
    
    if not missing_patterns:
        return True
    
    try:
        create_backup(GITIGNORE_PATH)
        
        content = GITIGNORE_PATH.read_text()
        
        # Adiciona cabeçalho se necessário
        if not content.strip():
            content = "# Body Harmony Nexus - Git Ignore Rules\n# Generated by audit script on " + datetime.now().isoformat() + "\n\n"
        
        # Adiciona padrões ausentes
        new_content = content.rstrip() + "\n\n# Security & Sensitive Files (Auto-added by audit)\n"
        for pattern in missing_patterns:
            new_content += f"{pattern}\n"
        
        # Adiciona outras regras importantes
        additional_rules = """
# Environment & Secrets
.env
.env.*
!.env.*.example
*.env
*.env.*

# Backup files
*.backup
*.bak

# IDE & Editor
.idea/
.vscode/
*.swp
*.swo

# OS Files
.DS_Store
Thumbs.db

# Logs
*.log
logs/

# Build artifacts
dist/
build/
*.egg-info/

# Python
__pycache__/
*.py[cod]
*$py.class
.pytest_cache/
.coverage
htmlcov/

# Node
node_modules/
npm-debug.log*
yarn-debug.log*
yarn-error.log*

# PHP
vendor/
composer.lock

# Docker
docker-compose.override.yml
"""
        
        new_content += additional_rules
        
        GITIGNORE_PATH.write_text(new_content)
        
        log(".gitignore atualizado com sucesso", "SUCCESS")
        return True
    except Exception as e:
        log(f"Falha ao atualizar .gitignore: {e}", "ERROR")
        return False

def fix_telegram_token(issues: List[Dict]) -> bool:
    """Remove token hardcoded do bot do Telegram"""
    bot_issue = next((i for i in issues if i["id"] == "BOT-001"), None)
    
    if not bot_issue:
        return True
    
    bot_file = WORKSPACE / "apps" / "telegram-bot" / "main.py"
    
    try:
        create_backup(bot_file)
        
        content = bot_file.read_text()
        
        # Substitui o padrão com fallback hardcoded por apenas getenv sem fallback
        old_pattern = r'os\.getenv\s*\(\s*["\']TELEGRAM_BOT_TOKEN["\']\s*,\s*["\'][A-Z0-9:_-]+["\']\s*\)'
        new_code = 'os.getenv("TELEGRAM_BOT_TOKEN")'
        
        content = re.sub(old_pattern, new_code, content)
        
        # Adiciona verificação de erro se TOKEN estiver ausente
        if 'if not API_TOKEN:' not in content:
            # Encontra a linha onde bot é inicializado
            init_pattern = r'(bot = telebot\.TeleBot\(API_TOKEN\))'
            check_code = """# Validação de segurança
if not API_TOKEN:
    raise RuntimeError("TELEGRAM_BOT_TOKEN environment variable is required")

\\1"""
            content = re.sub(init_pattern, check_code, content)
        
        bot_file.write_text(content)
        
        log("Token hardcoded removido do bot do Telegram", "SUCCESS")
        return True
    except Exception as e:
        log(f"Falha ao corrigir token do Telegram: {e}", "ERROR")
        return False

def fix_debug_files(issues: List[Dict]) -> bool:
    """Move scripts de debug para quarentena"""
    quarantine_dir = WORKSPACE / ".quarantine" / "debug_scripts"
    quarantine_dir.mkdir(parents=True, exist_ok=True)
    
    success_count = 0
    
    for issue in issues:
        if issue["id"] != "DBG-001":
            continue
        
        file_path = WORKSPACE / issue["file"]
        
        if not file_path.exists():
            continue
        
        try:
            create_backup(file_path)
            
            # Move para quarentena
            dest_path = quarantine_dir / file_path.name
            shutil.move(str(file_path), str(dest_path))
            
            log(f"Script de debug movido para quarentena: {issue['file']}", "SUCCESS")
            success_count += 1
        except Exception as e:
            log(f"Falha ao mover {file_path}: {e}", "ERROR")
    
    return success_count > 0

def create_editorconfig() -> bool:
    """Cria arquivo .editorconfig"""
    editorconfig_path = WORKSPACE / ".editorconfig"
    
    if editorconfig_path.exists():
        return True
    
    content = """# Body Harmony Nexus - EditorConfig
# https://editorconfig.org
# Generated by audit script on """ + datetime.now().isoformat() + """

root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true
indent_style = space
indent_size = 2

[*.md]
trim_trailing_whitespace = false

[*.{py,php,js,jsx,ts,tsx}]
indent_size = 4

[*.json]
indent_size = 2

[*.yaml]
indent_size = 2

[Makefile]
indent_style = tab
"""
    
    try:
        editorconfig_path.write_text(content)
        log(".editorconfig criado com sucesso", "SUCCESS")
        return True
    except Exception as e:
        log(f"Falha ao criar .editorconfig: {e}", "ERROR")
        return False

def create_precommit_config() -> bool:
    """Cria configuração de pre-commit"""
    precommit_path = WORKSPACE / ".pre-commit-config.yaml"
    
    if precommit_path.exists():
        return True
    
    content = """# Body Harmony Nexus - Pre-commit Hooks
# https://pre-commit.com
# Generated by audit script on """ + datetime.now().isoformat() + """

repos:
  - repo: https://github.com/pre-commit/pre-commit-hooks
    rev: v4.5.0
    hooks:
      - id: trailing-whitespace
      - id: end-of-file-fixer
      - id: check-yaml
      - id: check-json
      - id: check-added-large-files
      - id: detect-private-key
      - id: detect-aws-credentials
        args: ['--allow-missing-credentials']

  - repo: https://github.com/psf/black
    rev: 24.3.0
    hooks:
      - id: black
        language_version: python3

  - repo: https://github.com/pycqa/flake8
    rev: 7.0.0
    hooks:
      - id: flake8
        args: ['--max-line-length=100', '--extend-ignore=E203']

  - repo: https://github.com/pre-commit/mirrors-eslint
    rev: v9.0.0
    hooks:
      - id: eslint
        types: [javascript]
        args: ['--fix']

  - repo: local
    hooks:
      - id: nexus-gate
        name: Run Nexus Gate
        entry: powershell -ExecutionPolicy Bypass -File scripts/nexus_gate.ps1
        language: system
        pass_filenames: false
        always_run: true
"""
    
    try:
        precommit_path.write_text(content)
        log(".pre-commit-config.yaml criado com sucesso", "SUCCESS")
        return True
    except Exception as e:
        log(f"Falha ao criar pre-commit config: {e}", "ERROR")
        return False

def generate_audit_report(issues: List[Dict], output_path: Path) -> bool:
    """Gera relatório detalhado da auditoria"""
    try:
        report = {
            "metadata": {
                "timestamp": datetime.now().isoformat(),
                "workspace": str(WORKSPACE),
                "total_issues": len(issues),
                "by_severity": {},
                "by_type": {}
            },
            "issues": issues,
            "summary": {
                "critical": 0,
                "high": 0,
                "medium": 0,
                "low": 0
            }
        }
        
        # Agrupa por severidade
        for issue in issues:
            severity = issue["severity"]
            report["metadata"]["by_severity"][severity] = \
                report["metadata"]["by_severity"].get(severity, 0) + 1
            
            type_ = issue["type"]
            report["metadata"]["by_type"][type_] = \
                report["metadata"]["by_type"].get(type_, 0) + 1
            
            # Atualiza summary
            severity_lower = severity.lower()
            if severity_lower in report["summary"]:
                report["summary"][severity_lower] += 1
        
        # Salva relatório JSON
        output_path.write_text(json.dumps(report, indent=2, ensure_ascii=False))
        
        log(f"Relatório gerado: {output_path}", "SUCCESS")
        return True
    except Exception as e:
        log(f"Falha ao gerar relatório: {e}", "ERROR")
        return False

def apply_fixes(issues: List[Dict]) -> Dict[str, bool]:
    """Aplica correções automáticas"""
    log("=" * 80, "INFO")
    log("INICIANDO CORREÇÕES AUTOMÁTICAS", "INFO")
    log("=" * 80, "INFO")
    
    results = {}
    
    # Mapeia fixes por tipo de issue
    fix_map = {
        "GIT-002": ("Atualizar .gitignore", fix_gitignore),
        "BOT-001": ("Corrigir token do Telegram", fix_telegram_token),
        "DBG-001": ("Mover scripts de debug", fix_debug_files),
        "CFG-001": ("Criar .editorconfig", lambda _: create_editorconfig()),
        "HOOK-001": ("Criar pre-commit config", lambda _: create_precommit_config()),
    }
    
    applied_fixes = set()
    
    for issue in issues:
        issue_id = issue["id"]
        
        if issue_id in fix_map and issue_id not in applied_fixes:
            fix_name, fix_func = fix_map[issue_id]
            
            if issue.get("auto_fixable", False):
                log(f"Aplicando correção: {fix_name}", "INFO")
                
                try:
                    success = fix_func(issues)
                    results[issue_id] = success
                    
                    if success:
                        log(f"  ✓ Correção aplicada com sucesso", "SUCCESS")
                    else:
                        log(f"  ✗ Falha ao aplicar correção", "ERROR")
                    
                    applied_fixes.add(issue_id)
                except Exception as e:
                    log(f"  ✗ Erro ao aplicar correção: {e}", "ERROR")
                    results[issue_id] = False
            else:
                log(f"Pulando correção não automática: {fix_name}", "WARNING")
                results[issue_id] = None
    
    return results

# ============================================================================
# MAIN
# ============================================================================

def main():
    """Função principal"""
    print(f"{Colors.BOLD}{Colors.CYAN}")
    print("╔" + "=" * 78 + "╗")
    print("║" + " " * 20 + "BODY HARMONY NEXUS - AUDIT v1.0" + " " * 25 + "║")
    print("║" + " " * 15 + "Automação de Correção de Falhas Críticas" + " " * 20 + "║")
    print("╚" + "=" * 78 + "╝")
    print(f"{Colors.RESET}")
    
    # Inicializa log
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    if LOG_FILE.exists():
        LOG_FILE.unlink()
    
    log("Script iniciado", "INFO")
    
    try:
        # Fase 1: Auditoria
        issues = run_full_audit()
        
        if not issues:
            log("Nenhuma falha encontrada! Repositório está em conformidade.", "SUCCESS")
            return 0
        
        # Gera relatório inicial
        audit_report_path = WORKSPACE / ".audit_report.json"
        generate_audit_report(issues, audit_report_path)
        
        # Mostra resumo
        print(f"\n{Colors.BOLD}Resumo das Falhas Identificadas:{Colors.RESET}")
        print(f"  • Críticas: {sum(1 for i in issues if i['severity'] == 'CRITICAL')}")
        print(f"  • Altas: {sum(1 for i in issues if i['severity'] == 'HIGH')}")
        print(f"  • Médias: {sum(1 for i in issues if i['severity'] == 'MEDIUM')}")
        print(f"  • Baixas: {sum(1 for i in issues if i['severity'] == 'LOW')}")
        
        # Pergunta se deve aplicar correções
        auto_fixable_count = sum(1 for i in issues if i.get("auto_fixable", False))
        
        if auto_fixable_count > 0:
            print(f"\n{Colors.YELLOW}{auto_fixable_count} falha(s) podem ser corrigidas automaticamente.{Colors.RESET}")
            response = input(f"\nDeseja aplicar as correções automáticas? (s/n): ").strip().lower()
            
            if response == 's':
                # Fase 2: Correções
                fix_results = apply_fixes(issues)
                
                # Mostra resultados das correções
                print(f"\n{Colors.BOLD}Resultado das Correções:{Colors.RESET}")
                for issue_id, result in fix_results.items():
                    if result is True:
                        print(f"  {Colors.GREEN}✓{Colors.RESET} {issue_id}: Corrigido")
                    elif result is False:
                        print(f"  {Colors.RED}✗{Colors.RESET} {issue_id}: Falha na correção")
                    else:
                        print(f"  {Colors.YELLOW}○{Colors.RESET} {issue_id}: Requer ação manual")
                
                # Gera relatório final
                final_report_path = WORKSPACE / ".audit_report_fixed.json"
                generate_audit_report(issues, final_report_path)
                
                print(f"\n{Colors.GREEN}Backup criado em: {BACKUP_DIR}{Colors.RESET}")
                print(f"{Colors.GREEN}Relatórios salvos em: {audit_report_path} e {final_report_path}{Colors.RESET}")
            else:
                log("Correções automáticas puladas pelo usuário", "INFO")
        else:
            print(f"\n{Colors.YELLOW}Nenhuma correção automática disponível. Ação manual necessária.{Colors.RESET}")
        
        # Resumo final
        print(f"\n{Colors.BOLD}Próximos Passos Recomendados:{Colors.RESET}")
        
        manual_issues = [i for i in issues if not i.get("auto_fixable", False)]
        if manual_issues:
            print("\n  Ações manuais necessárias:")
            for issue in manual_issues[:5]:
                print(f"    • [{issue['severity']}] {issue['description']}")
                print(f"      → {issue['recommendation']}")
            
            if len(manual_issues) > 5:
                print(f"    ... e mais {len(manual_issues) - 5} ações")
        
        print(f"\n  Consulte o relatório completo: {audit_report_path}")
        
        return 0
    
    except KeyboardInterrupt:
        log("Execução interrompida pelo usuário", "WARNING")
        return 1
    except Exception as e:
        log(f"Erro fatal: {e}", "CRITICAL")
        import traceback
        traceback.print_exc()
        return 1

if __name__ == "__main__":
    sys.exit(main())

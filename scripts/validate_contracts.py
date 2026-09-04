#!/usr/bin/env python3
"""
Validador de Contratos de API - Body Harmony Nexus
Verifica se endpoints reais correspondem aos schemas JSON em openspec/contracts/
"""

import json
import sys
import requests
from pathlib import Path
from typing import Dict, List

WORKSPACE = Path("/workspace")
CONTRACTS_DIR = WORKSPACE / "openspec" / "contracts"

def validate_contract(contract_path: Path) -> Dict:
    """Valida um contrato individual"""
    try:
        with open(contract_path, 'r', encoding='utf-8') as f:
            contract = json.load(f)
        
        required = ["endpoint", "method", "response"]
        missing = [f for f in required if f not in contract]
        
        if missing:
            return {
                "valid": False,
                "file": str(contract_path),
                "errors": [f"Missing required field: {f}" for f in missing]
            }
        
        # TODO: Implementar validação contra endpoint real
        # Por enquanto, valida apenas estrutura JSON
        
        return {
            "valid": True,
            "file": str(contract_path),
            "endpoint": contract.get("endpoint"),
            "method": contract.get("method")
        }
    except json.JSONDecodeError as e:
        return {
            "valid": False,
            "file": str(contract_path),
            "errors": [f"Invalid JSON: {str(e)}"]
        }
    except Exception as e:
        return {
            "valid": False,
            "file": str(contract_path),
            "errors": [f"Unexpected error: {str(e)}"]
        }

def main():
    """Executa validação em todos os contratos"""
    contracts = list(CONTRACTS_DIR.rglob("*.json"))
    
    results = {
        "total": len(contracts),
        "valid": 0,
        "invalid": 0,
        "details": []
    }
    
    for contract in contracts:
        result = validate_contract(contract)
        results["details"].append(result)
        
        if result["valid"]:
            results["valid"] += 1
        else:
            results["invalid"] += 1
    
    print(json.dumps(results, indent=2))
    
    # Exit code 1 se houver contratos inválidos
    sys.exit(0 if results["invalid"] == 0 else 1)

if __name__ == "__main__":
    main()

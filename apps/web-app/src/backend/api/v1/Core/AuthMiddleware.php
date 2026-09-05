<?php
// api/v1/Core/AuthMiddleware.php

class AuthMiddleware {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function handle($requiredRole = null) {
        // --- 0. Nexus Firewall Engine (Signal Guard V3) ---
        $clientIp = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        $isBanned = false;
        require_once __DIR__ . '/NexusSQLite.php';
        
        if (NexusSQLite::isAvailable() && ($lite = NexusSQLite::get())) {
            $stmtLite = $lite->prepare("SELECT id FROM security_ip_rules WHERE ip_address = ? AND rule_type = 'BAN' AND (expires_at IS NULL OR expires_at > datetime('now','-3 hours'))");
            $stmtLite->execute([$clientIp]);
            if ($stmtLite->fetchColumn()) $isBanned = true;
        } else {
            // Fallback para PDO principal (Oracle/Hostinger) se SQLite falhar
            $stmtFirewall = $this->pdo->prepare("SELECT id FROM security_ip_rules WHERE ip_address = ? AND rule_type = 'BAN' AND (expires_at IS NULL OR expires_at > NOW())");
            $stmtFirewall->execute([$clientIp]);
            if ($stmtFirewall->fetchColumn()) $isBanned = true;
        }
        
        if ($isBanned) {
            error_log("[NEXUS_FIREWALL] Blocked request from Banned IP: " . $clientIp);
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Connection refused by Nexus Firewall (Signal Blacklisted).']);
            exit;
        }
        // ----------------------------------------------

        $headers = getallheaders_robust();
        
        $token = null;
        $deviceToken = null;

        // 1. Check Authorization Bearer (Using Uppercase Key)
        if (isset($headers['AUTHORIZATION'])) {
            if (preg_match('/Bearer\s(\S+)/', $headers['AUTHORIZATION'], $matches)) {
                $token = $matches[1];
            }
        }

        // 2. Check X-Device-Token (Licenciada Device)
        if (isset($headers['X-DEVICE-TOKEN'])) {
            $deviceToken = $headers['X-DEVICE-TOKEN'];
        }

        // 3. Check X-ALUNA-TOKEN (New Student Portal)
        if (isset($headers['X-ALUNA-TOKEN'])) {
            $deviceToken = $headers['X-ALUNA-TOKEN'];
        }

        // 4. Fallback: Query Param (Legacy support)
        if (!$token && !$deviceToken && isset($_GET['token'])) {
            $token = $_GET['token'];
        }

        $user = null;

        // Try validating Bearer Token first
        if ($token) {
            $user = $this->validateToken($token);
        }

        // V68: Bloquear tokens de aluna (al_*) em rotas que NÃO são de aluna
        // Mas atenção: se a rota for de admin, o admin usa Bearer.
        // Se um usuário tenta usar um token de aluna numa rota protegida (como listagem de alunas),
        // ele só deve passar se a rota permitir 'student' ou 'aluna'.
        
        // If Bearer failed (or wasn't present), try Device Token
        if (!$user && $deviceToken) {
            $user = $this->validateToken($deviceToken);
        }

        if (!$user) {
            error_log("[AUTH_ERROR] Token validation failed. Headers: " . json_encode($headers));
            Response::error('Invalid or expired token', 401);
        }

        // 4. Role Verification
        if ($requiredRole === 'admin' && empty($user['is_admin'])) {
            Response::error('Admin access required', 403);
        }

        if (($requiredRole === 'student' || $requiredRole === 'licenciada') && $user['id'] <= 0) {
             // Admin can bypass student check if needed? No, student role usually means real student.
             // But if we want to allow admin impersonation, we check if $user['id'] < 0 (Admin)
             if (!$user['is_admin']) {
                 Response::error('Licenciada access required', 403);
             }
        }

        // Inject into global or return
        global $loggedUser;
        $loggedUser = $user;
        
        return $user;
    }

    private function validateToken($token) {
        // 1. Check Admin Sessions (Bearer Token)
        $stmtAdmin = $this->pdo->prepare("
            SELECT s.*, u.username, u.role, u.id as user_id
            FROM admin_sessions s
            JOIN admin_users u ON s.user_id = u.id
            WHERE s.token = ? AND s.expires_at > ?
        ");
        $stmtAdmin->execute([$token, date('Y-m-d H:i:s')]);
        $adminSession = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

        if ($adminSession) {
            // Update last_activity if needed (optional)
            // Return unified user object
            return [
                'id' => $adminSession['user_id'],
                'name' => $adminSession['username'],
                'role' => $adminSession['role'],
                'is_admin' => true,
                'session_id' => $adminSession['id']
            ];
        }

        // 2. Check Licenciada Devices (X-Device-Token)
        $stmt = $this->pdo->prepare("
            SELECT d.id AS device_id, d.licenciada_id, d.device_token, d.is_active AS device_active,
                   s.id AS student_id, s.name AS student_name, s.is_active AS student_active, s.max_devices, s.force_password_change
            FROM licenciada_devices d
            LEFT JOIN licenciadas s ON d.licenciada_id = s.id
            WHERE d.device_token = ? AND d.is_active = 1
        ");
        $stmt->execute([$token]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($device) {
            $licId = (int)$device['licenciada_id'];
            if ($licId < 0) {
                $adminId = abs($licId);
                $stmtAdmin = $this->pdo->prepare("SELECT * FROM admin_users WHERE id = ? LIMIT 1");
                $stmtAdmin->execute([$adminId]);
                $admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
                if (!$admin) return null;

                $this->pdo->prepare("UPDATE licenciada_devices SET last_used_at = NOW() WHERE id = ?")->execute([$device['device_id']]);

                return [
                    'id' => $licId,
                    'name' => ucfirst($admin['username']) . ' (Admin)',
                    'device_id' => $device['device_id'],
                    'role' => $admin['role'] ?? 'admin',
                    'is_admin' => true,
                    'force_password_change' => false
                ];
            }

            // Real licenciada
            if ($licId > 0 && !$device['student_active']) return null;

            // Update usage
            $this->pdo->prepare("UPDATE licenciada_devices SET last_used_at = NOW() WHERE id = ?")->execute([$device['device_id']]);

            // Construct user object
            $user = [
                'id' => $licId,
                'name' => $device['student_name'],
                'device_id' => $device['device_id'],
                'role' => 'licenciada',
                'is_admin' => false,
                'force_password_change' => (bool)($device['force_password_change'] ?? 0)
            ];
            
            return $user;
        }

        // 3. Check Aluna Devices (X-ALUNA-TOKEN)
        $stmtAluna = $this->pdo->prepare("
            SELECT d.*, a.id as aluna_id, a.name, a.is_active, a.max_devices, a.force_password_change
            FROM aluna_devices d
            LEFT JOIN alunas a ON d.aluna_id = a.id
            WHERE d.device_token = ? AND d.is_active = 1
        ");
        $stmtAluna->execute([$token]);
        $alunaDevice = $stmtAluna->fetch(PDO::FETCH_ASSOC);

        if ($alunaDevice) {
            if ($alunaDevice['aluna_id'] > 0 && !$alunaDevice['is_active']) return null;

            // Update usage
            $this->pdo->prepare("UPDATE aluna_devices SET last_used_at = NOW() WHERE id = ?")->execute([$alunaDevice['id']]);

            return [
                'id' => $alunaDevice['aluna_id'],
                'name' => $alunaDevice['name'],
                'device_id' => $alunaDevice['id'],
                'role' => 'aluna',
                'is_admin' => false,
                'force_password_change' => (bool)($alunaDevice['force_password_change'] ?? 0)
            ];
        }

        return null;
    }
}

<?php
// api/v1/Controllers/AuthController.php

require_once __DIR__ . '/../libs/LoggerService.php';

class AuthController {
    private $pdo;
    private $logger;

    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
        $this->logger = new LoggerService($pdo);
    }

    private function logAuthAttempt($email, $success, $userId = null, $riskScore = 0, $riskDetails = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        try {
            $status = $success ? 'success' : 'failure_credentials';
            $detailsJson = $riskDetails ? json_encode($riskDetails) : null;
            $stmt = $this->pdo->prepare("INSERT INTO auth_logs (user_id, email, ip_address, user_agent, status, risk_score, risk_details) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $email, $ip, $ua, $status, $riskScore, $detailsJson]);
        } catch (PDOException $e) {
            error_log("Failed to log auth attempt: " . $e->getMessage());
        }
    }

    private function checkThrottling($email) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        // Default values
        $limit = 5;
        $minutes = 15;
        $whitelist = [];

        try {
            // Fetch dynamic rules
            $stmtRules = $this->pdo->query("SELECT rule_key, rule_value FROM nexus_security_rules WHERE is_active = 1");
            $rules = $stmtRules->fetchAll(PDO::FETCH_KEY_PAIR);

            if (isset($rules['MAX_LOGIN_ATTEMPTS'])) $limit = (int)$rules['MAX_LOGIN_ATTEMPTS'];
            if (isset($rules['LOCKOUT_DURATION_MINUTES'])) $minutes = (int)$rules['LOCKOUT_DURATION_MINUTES'];
            if (isset($rules['WHITELIST_IPS'])) {
                $decoded = json_decode($rules['WHITELIST_IPS'], true);
                if (is_array($decoded)) $whitelist = $decoded;
            }
        } catch (PDOException $e) {
            error_log("Failed to load security rules: " . $e->getMessage());
        }

        // Whitelist Bypass
        if (in_array($ip, $whitelist)) {
            return;
        }
        
        try {
            // 1. ACCOUNT-BASED THROTTLING (Strict)
            // Limits failures for a specific username/email regardless of IP
            $limitAccount = 5;
            $stmtAccount = $this->pdo->prepare("
                SELECT COUNT(*) 
                FROM auth_logs 
                WHERE email = ? 
                AND status != 'success' 
                AND created_at >= NOW() - INTERVAL ? MINUTE
            ");
            $stmtAccount->execute([$email, $minutes]);
            $failedAccount = $stmtAccount->fetchColumn();
            
            if ($failedAccount >= $limitAccount) {
                $this->logger->log(null, 'ACCOUNT_LOCKED_THROTTLE', ['email' => $email, 'ip' => $ip]);
                Response::error("Muitas tentativas falhas para esta conta. Tente novamente em $minutes minutos.", 429, 'ACCOUNT_LOCKED_THROTTLE');
            }

            // 2. IP-BASED THROTTLING (Lenient for CGNAT)
            // Higher limit to allow multiple users from the same shared IP
            $limitIp = 50; 
            $stmtIp = $this->pdo->prepare("
                SELECT COUNT(*) 
                FROM auth_logs 
                WHERE ip_address = ? 
                AND status != 'success' 
                AND created_at >= NOW() - INTERVAL ? MINUTE
            ");
            $stmtIp->execute([$ip, $minutes]);
            $failedIp = $stmtIp->fetchColumn();
            
            if ($failedIp >= $limitIp) {
                $this->logger->log(null, 'IP_BLOCKED_THROTTLE', ['ip' => $ip, 'count' => $failedIp]);
                Response::error("Bloqueio de rede temporário detectado. Entre em contato com o suporte.", 429, 'IP_BLOCKED_THROTTLE');
            }
        } catch (PDOException $e) {
            error_log("Throttling check failed: " . $e->getMessage());
        }
    }

    // POST /auth/login (Admin Panel)
    public function login() {
        $input = json_decode(file_get_contents("php://input"), true);
        if (!$input || !isset($input['username']) || !isset($input['password'])) {
             Response::error('Missing credentials', 400);
        }

        $loginKey = trim($input['username']);
        $password = (string)$input['password'];

        $stmt = $this->pdo->prepare("SELECT * FROM admin_users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->execute([$loginKey, $loginKey]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $storedHash = !empty($user['password_hash']) ? $user['password_hash'] : ($user['password'] ?? '');

        $isValidPassword = false;
        if ($user && $storedHash) {
            if (password_verify($password, $storedHash)) {
                $isValidPassword = true;
            } elseif ($storedHash === $password || md5($password) === $storedHash) {
                // Auto-upgrade legacy or plain password to modern BCrypt hash
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                $this->pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?")->execute([$newHash, $user['id']]);
                $isValidPassword = true;
            }
        }

        if ($user && $isValidPassword) {
            // Se a conta estiver inativa, rejeita
            if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
                Response::error('Sua conta está desativada. Entre em contato com a Diretoria.', 403, 'ACCOUNT_INACTIVE');
            }

            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+6 hours'));

            try {
                $stmt = $this->pdo->prepare("INSERT INTO admin_sessions (user_id, token, expires_at) VALUES (?, ?, ?)");
                $stmt->execute([$user['id'], $token, $expires_at]);

                require_once __DIR__ . '/../Services/RbacService.php';
                $rbacService = new \BodyHarmony\Services\RbacService($this->pdo);
                $permsInfo = $rbacService->getUserPermissions((int)$user['id']);

                Response::json([
                    'success' => true,
                    'token' => $token,
                    'user' => [
                        'id' => (int)$user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'] ?? null,
                        'role' => $user['role'] ?? ($permsInfo['is_superadmin'] ? 'superadmin' : 'admin'),
                        'department_id' => $permsInfo['department_id'],
                        'department_name' => $permsInfo['department_name'],
                        'role_id' => $permsInfo['role_id'],
                        'role_name' => $permsInfo['role_name'],
                        'hierarchy_level' => $permsInfo['hierarchy_level'],
                        'has_custom_permissions' => $permsInfo['has_custom_permissions'],
                        'permissions' => $permsInfo['permissions']
                    ]
                ]);
            } catch (PDOException $e) {
                Response::error('Failed to create session', 500);
            }
        } else {
             Response::error('Credenciais inválidas', 401, 'INVALID_CREDENTIALS');
        }
    }

    // POST /auth/licenciada/login (LMS / Device Token)
    public function loginLicenciada() {
        $input = json_decode(file_get_contents("php://input"), true);
        $loginValue = trim($input['login'] ?? '');
        $password = $input['password'] ?? '';
        $deviceToken = $input['device_token'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        // 0. Pre-flight Validation
        if (empty($loginValue) || empty($password)) {
             Response::error('Login e senha são obrigatórios.', 400);
        }

        // 0. Throttling Check (Guardian)
        $this->checkThrottling($loginValue);

        // 1. Find Licenciada (Prioritize CPF)
        $loginClean = preg_replace('/\D/', '', $loginValue);
        $licenciada = null;
        
        // Strategy V41: CPF First (11 Digits)
        if (strlen($loginClean) === 11) {
             $stmt = $this->pdo->prepare("SELECT * FROM licenciadas WHERE cpf = ? AND is_active = 1 LIMIT 1");
             $stmt->execute([$loginClean]);
             $licenciada = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Fallback: Legacy Email/Username (Only if not found by CPF and input does not have exactly 11 digits)
        if (!$licenciada && strlen($loginClean) !== 11) {
             $stmt = $this->pdo->prepare("SELECT * FROM licenciadas WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1");
             $stmt->execute([$loginValue, $loginValue]);
             $licenciada = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 2. Fallback Admin
        if (!$licenciada) {
            $adminUser = ltrim($loginValue, '@');
            $stmtAdmin = $this->pdo->prepare("SELECT * FROM admin_users WHERE username = ? LIMIT 1");
            $stmtAdmin->execute([$adminUser]);
            $admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
            if ($admin) {
                $licenciada = [
                    'id' => -1 * $admin['id'],
                    'name' => ucfirst($admin['username']) . ' (Admin)',
                    'instagram' => '@' . $admin['username'],
                    'photo_url' => 'https://ui-avatars.com/api/?name=Admin&background=000&color=fff',
                    'is_active' => 1,
                    'password_hash' => $admin['password_hash'],
                    'force_password_change' => 0,
                    'max_devices' => 999
                ];
            }
        }

        if (!$licenciada) Response::error('Dados de acesso não conferem.', 401, 'INVALID_CREDENTIALS');
        if (!$licenciada['is_active']) Response::error('Conta desativada.', 403, 'ACCOUNT_INACTIVE');

        // 1.5 Lockout Check
        if (isset($licenciada['locked_until']) && $licenciada['locked_until'] && strtotime($licenciada['locked_until']) > time()) {
            Response::error('Conta bloqueada temporariamente por excesso de tentativas falhas.', 429, 'ACCOUNT_LOCKED');
        }
        
        if (empty($licenciada['password_hash']) || !password_verify($password, $licenciada['password_hash'])) {
            // Behavioral Risk Assessment for failures (helps detect brute force bots)
            $riskEngine = new RiskEngineService($this->pdo);
            $risk = $riskEngine->calculateScore($licenciada['id'] ?? null, $loginValue, $_SERVER['REMOTE_ADDR'], $userAgent, getallheaders_robust());
            
            $this->logAuthAttempt($loginValue, false, $licenciada['id'] ?? null, $risk['score'], $risk['details']);
            
            // Update security counters
            $attempts = ($licenciada['failed_login_attempts'] ?? 0) + 1;
            if ($attempts >= 5) {
                $lockedUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                $this->pdo->prepare("UPDATE licenciadas SET failed_login_attempts = ?, locked_until = ? WHERE id = ?")
                     ->execute([$attempts, $lockedUntil, $licenciada['id']]);
            } else {
                $this->pdo->prepare("UPDATE licenciadas SET failed_login_attempts = ? WHERE id = ?")
                     ->execute([$attempts, $licenciada['id']]);
            }

            Response::error('Dados de acesso não conferem.', 401, 'INVALID_CREDENTIALS');
        }

        // 2. Behavioral Risk Assessment for success
        $riskEngine = new RiskEngineService($this->pdo);
        $risk = $riskEngine->calculateScore($licenciada['id'], $loginValue, $_SERVER['REMOTE_ADDR'], $userAgent, getallheaders_robust());

        // 2.1 Reset security counters on success
        if (($licenciada['failed_login_attempts'] ?? 0) > 0) {
            $this->pdo->prepare("UPDATE licenciadas SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")
                 ->execute([$licenciada['id']]);
        }

        // 2.5 Log Success (Guardian)
        $this->logAuthAttempt($loginValue, true, $licenciada['id'], $risk['score'], $risk['details']);

        // 3. Device Authorization & Concurrent Session Management (Kick Oldest)
        if ($licenciada['id'] > 0) {
            $currentIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $limit = $licenciada['max_devices'] ?? 2; // Default limit
            
            // A. Check if this specific device is already known (by Session Token)
            $existingDevice = null;
            if ($deviceToken) {
                $stmtDev = $this->pdo->prepare("SELECT * FROM licenciada_devices WHERE licenciada_id = ? AND device_token = ?");
                $stmtDev->execute([$licenciada['id'], $deviceToken]);
                $existingDevice = $stmtDev->fetch(PDO::FETCH_ASSOC);
            }

            if (!$existingDevice) {
                // Nexus V61: Strict Hardware-Link Identification
                // Ignoramos IP e UserAgent para reutilização de entrada se o Fingerprint bater.
                $fingerprint = $risk['fingerprint'] ?? null;
                if ($fingerprint) {
                    $stmtFinger = $this->pdo->prepare("
                        SELECT * FROM licenciada_devices 
                        WHERE licenciada_id = ? AND fingerprint_hash = ?
                        ORDER BY last_used_at DESC LIMIT 1
                    ");
                    $stmtFinger->execute([$licenciada['id'], $fingerprint]);
                    $existingDevice = $stmtFinger->fetch(PDO::FETCH_ASSOC);
                }
            }

            if ($existingDevice) {
                // Reuse existing entry: Update identity and session
                $newToken = $deviceToken ?: bin2hex(random_bytes(32));
                $this->pdo->prepare("
                    UPDATE licenciada_devices 
                    SET last_used_at = NOW(), 
                        is_active = 1, 
                        ip_address = ?, 
                        fingerprint_hash = ?, 
                        device_token = ?,
                        user_agent = ?
                    WHERE id = ?
                ")
                     ->execute([$currentIp, $risk['fingerprint'], $newToken, $userAgent, $existingDevice['id']]);
                $deviceToken = $newToken;
            } else {
                // Truly New Device Login (New Fingerprint)
                // B. Check active sessions count
                $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM licenciada_devices WHERE licenciada_id = ? AND is_active = 1");
                $stmtCount->execute([$licenciada['id']]);
                $activeCount = (int)$stmtCount->fetchColumn();

                if ($activeCount >= $limit) {
                    // C. Strict FIFO Kicker: Expulsar até ficar abaixo do limite
                    while ($activeCount >= $limit) {
                        $stmtOldest = $this->pdo->prepare("SELECT id FROM licenciada_devices WHERE licenciada_id = ? AND is_active = 1 ORDER BY last_used_at ASC LIMIT 1");
                        $stmtOldest->execute([$licenciada['id']]);
                        $oldestId = $stmtOldest->fetchColumn();
                        
                        if ($oldestId) {
                            $this->pdo->prepare("UPDATE licenciada_devices SET is_active = 0 WHERE id = ?")->execute([$oldestId]);
                            $this->logger->log($licenciada['id'], 'SESSION_KICK', ['kicked_device_id' => (int)$oldestId, 'reason' => 'limit_reached_fifo']);
                            $activeCount--;
                        } else {
                            break;
                        }
                    }
                }

                // D. Insert New Session
                $newToken = bin2hex(random_bytes(32));
                try {
                    $this->pdo->prepare("
                        INSERT INTO licenciada_devices (licenciada_id, device_token, user_agent, ip_address, fingerprint_hash, is_active, last_used_at) 
                        VALUES (?, ?, ?, ?, ?, 1, NOW())
                        ON DUPLICATE KEY UPDATE
                            device_token = VALUES(device_token),
                            user_agent = VALUES(user_agent),
                            ip_address = VALUES(ip_address),
                            is_active = 1,
                            last_used_at = NOW()
                    ")
                         ->execute([$licenciada['id'], $newToken, $userAgent, $currentIp, $risk['fingerprint']]);
                    $deviceToken = $newToken;
                } catch (\PDOException $e) {
                    if ($e->getCode() == 23000 || str_contains($e->getMessage(), '1062')) {
                        $stmtFinger = $this->pdo->prepare("
                            SELECT device_token FROM licenciada_devices 
                            WHERE licenciada_id = ? AND fingerprint_hash = ?
                            ORDER BY last_used_at DESC LIMIT 1
                        ");
                        $stmtFinger->execute([$licenciada['id'], $risk['fingerprint']]);
                        $existingToken = $stmtFinger->fetchColumn();
                        if ($existingToken !== false) {
                            $deviceToken = $existingToken;
                            $this->pdo->prepare("
                                UPDATE licenciada_devices 
                                SET last_used_at = NOW(), 
                                    is_active = 1, 
                                    ip_address = ?, 
                                    user_agent = ?
                                WHERE licenciada_id = ? AND fingerprint_hash = ?
                            ")->execute([$currentIp, $userAgent, $licenciada['id'], $risk['fingerprint']]);
                        } else {
                            throw $e;
                        }
                    } else {
                        throw $e;
                    }
                }
            }

            $this->pdo->prepare("UPDATE licenciadas SET last_login_at = NOW() WHERE id = ?")->execute([$licenciada['id']]);
            $this->logger->log($licenciada['id'], 'LOGIN', ['device_token' => $deviceToken, 'ip' => $currentIp, 'risk_score' => $risk['score']]);

        } else {
            // Admin Device (Legacy/Fallback)
            $newToken = 'admin-' . bin2hex(random_bytes(32));
            try {
                $this->pdo->prepare("
                    INSERT INTO licenciada_devices (licenciada_id, device_token, user_agent, fingerprint_hash, last_used_at, is_active) 
                    VALUES (?, ?, ?, ?, NOW(), 1)
                    ON DUPLICATE KEY UPDATE
                        device_token = VALUES(device_token),
                        user_agent = VALUES(user_agent),
                        last_used_at = NOW(),
                        is_active = 1
                ")
                     ->execute([$licenciada['id'], $newToken, $userAgent, $risk['fingerprint']]);
                $deviceToken = $newToken;
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000 || str_contains($e->getMessage(), '1062')) {
                    $stmtFinger = $this->pdo->prepare("
                        SELECT device_token FROM licenciada_devices 
                        WHERE licenciada_id = ? AND fingerprint_hash = ?
                        ORDER BY last_used_at DESC LIMIT 1
                    ");
                    $stmtFinger->execute([$licenciada['id'], $risk['fingerprint']]);
                    $existingToken = $stmtFinger->fetchColumn();
                    if ($existingToken !== false) {
                        $deviceToken = $existingToken;
                        $this->pdo->prepare("
                            UPDATE licenciada_devices 
                            SET last_used_at = NOW(), 
                                is_active = 1, 
                                user_agent = ?
                            WHERE licenciada_id = ? AND fingerprint_hash = ?
                        ")->execute([$userAgent, $licenciada['id'], $risk['fingerprint']]);
                    } else {
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }
            
            // Log as Admin (since this is a fallback for admin users logging as students)
            $this->logger->log($this->user['id'] ?? $licenciada['id'], 'LOGIN_ADMIN_AS_STUDENT', ['device_token' => $deviceToken], 'admin');
        }

        // Check Consents
        $consentPending = false;
        try {
            $stmtC = $this->pdo->prepare("SELECT lgpd_status FROM licenciadas WHERE id = ?");
            $stmtC->execute([$licenciada['id']]);
            $status = json_decode($stmtC->fetchColumn() ?: '{}', true);
            
            // Define required consents here (e.g., 'terms', 'privacy')
            // For now, we just flag if 'terms' is not accepted
            if (empty($status['terms'])) {
                $consentPending = true;
            }
        } catch (Exception $e) {
            // Fail safe: assume pending if check fails
            $consentPending = true;
        }

        // 5. Background Maintenance (Probability 5%)
        if (rand(1, 100) <= 5) {
            $cleaner = new LogCleaner($this->pdo);
            $cleaner->purgeSuccessLogs(30);
        }

        unset($licenciada['password_hash']);
        Response::json([
            'success' => true,
            'licenciada' => $licenciada,
            'token' => $deviceToken,
            'device_token' => $deviceToken,
            'forceChange' => (bool)$licenciada['force_password_change'],
            'consent_pending' => $consentPending,
            'message' => 'Login realizado com sucesso.'
        ]);
    }
    // POST /v1/auth/licenciada/change_password
    public function changePasswordLicenciada() {
        $input = json_decode(file_get_contents("php://input"), true);
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        
        // Use robust header retrieval from config.php
        $headers = getallheaders_robust();
        // Handle different casing from different servers/clients
        $token = $headers['X-Device-Token'] ?? $headers['X-DEVICE-TOKEN'] ?? $headers['x-device-token'] ?? null;
        
        if (!$token) {
             Response::error('Token de dispositivo ausente.', 401);
        }

        $stmtDev = $this->pdo->prepare("SELECT licenciada_id FROM licenciada_devices WHERE device_token = ? AND is_active = 1");
        $stmtDev->execute([$token]);
        $studentId = $stmtDev->fetchColumn();

        if (!$studentId) {
             Response::error('Sessão inválida ou expirada.', 401);
        }

        // Fetch User (Student or Admin)
        if ($studentId < 0) {
            // It's an admin logged as student
            $adminId = abs($studentId);
            $stmt = $this->pdo->prepare("SELECT id, password_hash, username as name FROM admin_users WHERE id = ?");
            $stmt->execute([$adminId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $table = "admin_users";
        } else {
            // Standard student
            $stmt = $this->pdo->prepare("SELECT id, password_hash, name FROM licenciadas WHERE id = ?");
            $stmt->execute([$studentId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $table = "licenciadas";
        }

        if (!$user) Response::error('Usuário não encontrado.', 404);

        // Verify Current
        if (!password_verify($currentPassword, $user['password_hash'])) {
            Response::error('Senha atual incorreta.', 400);
        }

        // Validate New
        if (strlen($newPassword) < 6) {
            Response::error('A nova senha deve ter pelo menos 6 caracteres.', 400);
        }

        // Update
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $sql = ($table === 'admin_users') 
               ? "UPDATE admin_users SET password_hash = ? WHERE id = ?" 
               : "UPDATE licenciadas SET password_hash = ?, force_password_change = 0 WHERE id = ?";
        
        $update = $this->pdo->prepare($sql);
        $update->execute([$newHash, abs($studentId)]);

        if ($update->rowCount() === 0) {
            // If hash is exactly same, rowCount is 0 in some cases, but here we expect change.
            // Or if ID mismatch happened.
            error_log("ChangePassword Warning: No rows affected for $table ID $studentId");
        }

        Response::json(['success' => true, 'message' => 'Senha alterada com sucesso.']);
    }
    // POST /v1/auth/licenciada/first-access
    public function changePasswordFirstAccess() {
        $input = json_decode(file_get_contents("php://input"), true);
        $newPassword = $input['new_password'] ?? '';
        $studentId = $input['student_id'] ?? null; // Optional if we use token, but frontend sends it

        // We can use the token to stricter auth or trust the ID if we are just fixing the route for now.
        // Given we are moving away from legacy, let's try to be secure but compatible.
        // The ForcePassword page has the student object from context (which comes from localStorage).
        // Standard student auth flows usually use X-Device-Token. 
        // Let's rely on that if available, or fall back to a basic check if this is a specialized flow.
        
        // Actually, ForcePassword.jsx calls api.studentChangePasswordFirstAccess(student.id, pass1)
        // But the user might NOT have a valid token if they were forced to change password *before* getting a token?
        // No, Login usually gives a token but sets force_password_change=1.
        
        // Let's Validate Session similar to changePasswordStudent
        $headers = getallheaders();
        $token = $headers['X-Device-Token'] ?? null;
        
        if (!$token) {
             Response::error('Token de dispositivo ausente.', 401);
        }

        $stmtDev = $this->pdo->prepare("SELECT licenciada_id FROM licenciada_devices WHERE device_token = ?");
        $stmtDev->execute([$token]);
        $licenciadaId = $stmtDev->fetchColumn();

        if (!$licenciadaId) {
             Response::error('Sessão inválida.', 401);
        }
        
        $authenticatedId = $licenciadaId;
        
        // Verify requested ID matches authenticated ID
        if ($studentId && $studentId != $authenticatedId) {
             Response::error('Acesso não autorizado para este usuário.', 403);
        }

        if (strlen($newPassword) < 6) {
            Response::error('A nova senha deve ter pelo menos 6 caracteres.', 400);
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update and CLEAR the force flag
        $update = $this->pdo->prepare("UPDATE licenciadas SET password_hash = ?, force_password_change = 0 WHERE id = ?");
        $update->execute([$newHash, $authenticatedId]);

        Response::json(['success' => true, 'message' => 'Senha definida com sucesso.']);
    }

    // POST /v1/auth/admin/change_password
    public function changePasswordAdmin() {
        $input = json_decode(file_get_contents("php://input"), true);
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';

        $headers = getallheaders();
        $token = null;
        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
                $token = $matches[1];
            }
        }

        if (!$token) Response::error('Unauthorized', 401);

        $stmt = $this->pdo->prepare("SELECT user_id FROM admin_sessions WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        $adminId = $stmt->fetchColumn();

        if (!$adminId) Response::error('Session invalid', 401);

        $stmt = $this->pdo->prepare("SELECT * FROM admin_users WHERE id = ?");
        $stmt->execute([$adminId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            Response::error('Senha atual incorreta', 403);
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?")->execute([$newHash, $adminId]);

        Response::json(['success' => true]);
    }

    // GET /v1/auth/licenciada/validate
    public function validateLicenciadaSession() {
        $headers = getallheaders_robust();
        $token = $headers['X-DEVICE-TOKEN'] ?? null;

        // DEBUG: Log header presence (Guardian)
        if (!$token) {
            error_log("[AUTH_DEBUG] X-DEVICE-TOKEN missing in validateStudentSession. Available headers: " . implode(', ', array_keys($headers)));
        }

        if (!$token) {
            // Fallback search for token in Authorization header if X-Device-Token is missing
            if (isset($headers['AUTHORIZATION'])) {
                if (preg_match('/Bearer\s(\S+)/', $headers['AUTHORIZATION'], $matches)) {
                    $token = $matches[1];
                }
            }
        }

        if (!$token) {
             Response::error('Token ausente.', 401);
        }

        $stmt = $this->pdo->prepare("
            SELECT sd.licenciada_id, sd.last_used_at, sd.is_active AS device_active,
                   s.id AS student_id, s.name, s.email, s.username, s.cpf, s.phone, s.instagram, s.photo_url, s.is_active, s.force_password_change, s.max_devices
            FROM licenciada_devices sd
            LEFT JOIN licenciadas s ON sd.licenciada_id = s.id
            WHERE sd.device_token = ?
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
             Response::error('Sessão inválida.', 401);
        }

        if (!$row['device_active']) {
             Response::error('Sessão encerrada.', 401);
        }

        $licenciadaId = (int)$row['licenciada_id'];
        $data = null;

        // Admin Fallback Check (licenciadaId < 0)
        if ($licenciadaId < 0) {
            $adminId = abs($licenciadaId);
            $stmtAdmin = $this->pdo->prepare("SELECT * FROM admin_users WHERE id = ? LIMIT 1");
            $stmtAdmin->execute([$adminId]);
            $admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
            if (!$admin) {
                Response::error('Sessão de administrador inválida.', 401);
            }
            $data = [
                'id' => $licenciadaId,
                'name' => ucfirst($admin['username']) . ' (Admin)',
                'username' => $admin['username'],
                'email' => $admin['email'] ?? ($admin['username'] . '@bodyharmony.com.br'),
                'instagram' => '@' . $admin['username'],
                'photo_url' => 'https://ui-avatars.com/api/?name=Admin&background=000&color=fff',
                'is_active' => 1,
                'force_password_change' => 0,
                'max_devices' => 999,
                'role' => $admin['role'] ?? 'admin',
                'last_used_at' => $row['last_used_at']
            ];
        } else if ($row['student_id']) {
            if (!$row['is_active']) {
                Response::error('Conta desativada.', 403);
            }
            $data = [
                'id' => (int)$row['student_id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'username' => $row['username'],
                'cpf' => $row['cpf'],
                'phone' => $row['phone'],
                'instagram' => $row['instagram'],
                'photo_url' => $row['photo_url'],
                'is_active' => (int)$row['is_active'],
                'force_password_change' => (int)$row['force_password_change'],
                'max_devices' => (int)$row['max_devices'],
                'last_used_at' => $row['last_used_at']
            ];
        }

        if (!$data) {
            Response::error('Sessão inválida.', 401);
        }

        // Update last used
        $this->pdo->prepare("UPDATE licenciada_devices SET last_used_at = NOW() WHERE device_token = ?")->execute([$token]);

        unset($data['password_hash']);
        Response::json([
            'success' => true,
            'licenciada' => $data,
            'student' => $data,
            'valid' => true
        ]);
    }
    
    // POST /v1/auth/aluna/login
    public function loginAluna() {
        $input = json_decode(file_get_contents("php://input"), true);
        $loginValue = trim($input['login'] ?? '');
        $password = $input['password'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        if (empty($loginValue) || empty($password)) {
             Response::error('E-mail/CPF e senha são obrigatórios.', 400);
        }

        $this->checkThrottling($loginValue);

        $loginClean = preg_replace('/\D/', '', $loginValue);
        $aluna = null;
        
        // CPF check (11 digits)
        if (strlen($loginClean) === 11) {
             $stmt = $this->pdo->prepare("SELECT * FROM alunas WHERE cpf = ? AND is_active = 1 LIMIT 1");
             $stmt->execute([$loginClean]);
             $aluna = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Email check
        if (!$aluna) {
             $stmt = $this->pdo->prepare("SELECT * FROM alunas WHERE email = ? AND is_active = 1 LIMIT 1");
             $stmt->execute([$loginValue]);
             $aluna = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$aluna) Response::error('Dados de acesso não conferem.', 401, 'INVALID_CREDENTIALS');
        if (!$aluna['is_active']) Response::error('Conta desativada.', 403, 'ACCOUNT_INACTIVE');

        if (empty($aluna['password_hash']) || !password_verify($password, $aluna['password_hash'])) {
            $this->logAuthAttempt($loginValue, false, $aluna['id'], 0, 'Failed Aluna Login');
            Response::error('Dados de acesso não conferem.', 401, 'INVALID_CREDENTIALS');
        }

        // Device Management
        $token = 'al_' . bin2hex(random_bytes(32));
        $currentIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        $this->pdo->prepare("
            INSERT INTO aluna_devices (aluna_id, device_token, user_agent, ip_address, is_active, last_used_at) 
            VALUES (?, ?, ?, ?, 1, NOW())
        ")->execute([$aluna['id'], $token, $userAgent, $currentIp]);

        $this->pdo->prepare("UPDATE alunas SET last_login_at = NOW() WHERE id = ?")->execute([$aluna['id']]);

        unset($aluna['password_hash']);
        Response::json([
            'success' => true,
            'aluna' => $aluna,
            'token' => $token,
            'forceChange' => (bool)$aluna['force_password_change'],
            'message' => 'Login realizado com sucesso.'
        ]);
    }

    // GET /v1/auth/aluna/validate
    public function validateAlunaSession() {
        $headers = getallheaders_robust();
        $token = $headers['X-ALUNA-TOKEN'] ?? null;

        if (!$token) Response::error('Token ausente.', 401);

        $stmt = $this->pdo->prepare("
            SELECT a.*, ad.last_used_at 
            FROM aluna_devices ad
            JOIN alunas a ON ad.aluna_id = a.id
            WHERE ad.device_token = ? AND ad.is_active = 1
        ");
        $stmt->execute([$token]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data || !$data['is_active']) {
             Response::error('Sessão inválida ou conta desativada.', 401);
        }

        $this->pdo->prepare("UPDATE aluna_devices SET last_used_at = NOW() WHERE device_token = ?")->execute([$token]);

        unset($data['password_hash']);
        Response::json([
            'success' => true,
            'aluna' => $data,
            'valid' => true
        ]);
    }
}


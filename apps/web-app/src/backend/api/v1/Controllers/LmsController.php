<?php
// api/v1/Controllers/LmsController.php

class LmsController {
    private $pdo;
    private $user;
    private $logger;

    public function __construct() {
        global $pdo, $loggedUser; // Injected by index/middleware
        $this->pdo = $pdo;
        $this->user = $loggedUser;
        $this->logger = new LoggerService($pdo);
    }

    // GET /lms/modules (Optimized Single Query — PLAN-011: shows exclusive modules as storefront)
    public function index() {
        $userId = $this->user['id'] ?? 0;
        
        ResponseCache::serve("api_lms_modules_$userId", function () use ($userId) {
            try {
                $sql = "
                    SELECT 
                        m.id as m_id, m.title as m_title, m.description as m_desc, m.is_exclusive,
                        EXISTS (
                            SELECT 1 FROM licenciada_course_access lca 
                            WHERE lca.licenciada_id = ? AND lca.module_id = m.id 
                              AND (lca.expires_at IS NULL OR lca.expires_at > NOW())
                        ) AS has_access,
                        l.id as l_id, l.title as l_title, l.description as l_desc,
                        l.video_type, l.video_ref, l.duration_seconds, l.thumbnail_ref, l.thumbnail_base64,
                        COALESCE(p.is_completed, 0) as is_completed,
                        COALESCE(p.progress_percent, 0) as progress_percent
                    FROM lms_modules m
                    LEFT JOIN lms_lessons l ON m.id = l.module_id AND l.is_active = 1
                    LEFT JOIN lms_progress p ON p.lesson_id = l.id AND p.licenciada_id = ?
                    WHERE m.is_active = 1
                    ORDER BY m.is_exclusive ASC, m.display_order ASC, l.display_order ASC
                ";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$userId, $userId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $modulesMap = [];
                foreach ($rows as $row) {
                    $mId = $row['m_id'];
                    if (!isset($modulesMap[$mId])) {
                        $isExclusive = (bool)$row['is_exclusive'];
                        $hasAccess   = (bool)$row['has_access'];
                        $modulesMap[$mId] = [
                            'id'          => $mId,
                            'title'       => $row['m_title'],
                            'description' => $row['m_desc'],
                            'is_exclusive'=> $isExclusive,
                            'has_access'  => $isExclusive ? $hasAccess : true,
                            'lessons'     => []
                        ];
                    }
                    
                    if ($row['l_id']) {
                        $hasAccess = (bool)$modulesMap[$mId]['has_access'];
                        $modulesMap[$mId]['lessons'][] = [
                            'id'               => (int)$row['l_id'],
                            'title'            => $row['l_title'],
                            'video_type'       => $hasAccess ? $row['video_type'] : null,
                            'video_ref'        => $hasAccess ? $row['video_ref'] : null,
                            'duration_seconds' => (int)$row['duration_seconds'],
                            'thumbnail_ref'    => $row['thumbnail_ref'],
                            'thumbnail_base64' => $row['thumbnail_base64'],
                            'is_completed'     => $hasAccess ? (bool)$row['is_completed'] : false,
                            'progress_percent' => $hasAccess ? (int)$row['progress_percent'] : 0
                        ];
                    }
                }
                
                $modules = array_values($modulesMap);
                
                return $modules;
            } catch (PDOException $e) {
                Response::error('Database Error: ' . $e->getMessage(), 500);
            }
        }, 300, false);
    }

    // GET /lms/modules/{id}/lessons
    public function lessons($moduleId) {
        try {
            // 1. Validate Module
            $stmtMod = $this->pdo->prepare("SELECT * FROM lms_modules WHERE id = ? AND is_active = 1");
            $stmtMod->execute([$moduleId]);
            $module = $stmtMod->fetch(PDO::FETCH_ASSOC);
            
            if (!$module) {
                Response::error('Módulo não encontrado', 404);
            }

            // Check exclusive module access
            if ($module['is_exclusive']) {
                $stmtCheck = $this->pdo->prepare("
                    SELECT id FROM licenciada_course_access 
                    WHERE licenciada_id = ? AND module_id = ? 
                      AND (expires_at IS NULL OR expires_at > NOW())
                ");
                $stmtCheck->execute([$this->user['id'], $moduleId]);
                if (!$stmtCheck->fetchColumn()) {
                    Response::error('Acesso restrito. Autorização do gestor necessária.', 403);
                }
            }
            
            // 0. Strict Progression Check (Phase 4)
            // Can user access this module? Wrap in try-catch for stabilization
            try {
                // Get current module order
                $stmtOrder = $this->pdo->prepare("SELECT display_order FROM lms_modules WHERE id = ?");
                $stmtOrder->execute([$moduleId]);
                $currentOrder = $stmtOrder->fetchColumn();

                if ($currentOrder > 1) { // Assuming order starts at 1
                    // Find previous module
                    $stmtPrev = $this->pdo->prepare("SELECT id FROM lms_modules WHERE display_order < ? ORDER BY display_order DESC LIMIT 1");
                    $stmtPrev->execute([$currentOrder]);
                    $prevModId = $stmtPrev->fetchColumn();

                    if ($prevModId) {
                        // Check if previous module has a quiz
                        $stmtPrevQuiz = $this->pdo->prepare("SELECT id FROM lms_quizzes WHERE module_id = ?");
                        $stmtPrevQuiz->execute([$prevModId]);
                        $prevQuizId = $stmtPrevQuiz->fetchColumn();

                        if ($prevQuizId) {
                            // Check if passed
                            $stmtPassed = $this->pdo->prepare("SELECT passed FROM lms_quiz_attempts WHERE quiz_id = ? AND licenciada_id = ? AND passed = 1 LIMIT 1");
                            $stmtPassed->execute([$prevQuizId, $this->user['id']]);
                            $isPassed = $stmtPassed->fetchColumn();

                            if (!$isPassed) {
                                // BLOCKED!
                                Response::json([
                                    'module' => $module,
                                    'lessons' => [], // Hide lessons
                                    'quiz' => null,
                                    'locked' => true,
                                    'locked_reason' => 'Complete a avaliação do módulo anterior para desbloquear.'
                                ]);
                                return;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // Progression check failed? Log and ignore to allow entrance (Stabilization)
                error_log("Progression Check Error (Bypassed): " . $e->getMessage());
            }

            // 2. Fetch Lessons (Existing Code...)
            $sqlLessons = "
                SELECT 
                    l.id, l.title, l.description, l.video_type, l.duration_seconds, l.attachment_count, l.display_order,
                    l.thumbnail_base64,
                    COALESCE(p.is_completed, 0) as is_completed,
                    COALESCE(p.progress_percent, 0) as progress_percent,
                    (SELECT video_ref FROM lms_lessons WHERE id = l.id) as video_ref 
                FROM lms_lessons l
                LEFT JOIN lms_progress p ON p.lesson_id = l.id AND p.licenciada_id = ?
                WHERE l.module_id = ?
                ORDER BY l.display_order ASC
            ";
            
            $stmtLess = $this->pdo->prepare($sqlLessons);
            $stmtLess->execute([$this->user['id'], $moduleId]);
            $lessons = $stmtLess->fetchAll(PDO::FETCH_ASSOC);

            // 3. Attachments Logic (Existing Code...)
            foreach ($lessons as &$lesson) {
                if ($lesson['attachment_count'] > 0) {
                    $stmtAtt = $this->pdo->prepare("SELECT id, title, file_type, is_downloadable FROM lms_attachments WHERE lesson_id = ?");
                    $stmtAtt->execute([$lesson['id']]);
                    $lesson['attachments'] = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $lesson['attachments'] = [];
                }
            }

            // 4. Fetch Module Quiz (Phase 3)
            $quiz = null;
            $certificate_available = false;

            try {
                $stmtQuiz = $this->pdo->prepare("SELECT id, title, min_score FROM lms_quizzes WHERE module_id = ?");
                $stmtQuiz->execute([$moduleId]);
                $quizData = $stmtQuiz->fetch(PDO::FETCH_ASSOC);

                if ($quizData) {
                    // Check status
                    $stmtAttempt = $this->pdo->prepare("SELECT score, passed FROM lms_quiz_attempts WHERE quiz_id = ? AND licenciada_id = ? ORDER BY attempted_at DESC LIMIT 1");
                    $stmtAttempt->execute([$quizData['id'], $this->user['id']]);
                    $attempt = $stmtAttempt->fetch(PDO::FETCH_ASSOC);

                    $quiz = [
                        'id' => $quizData['id'],
                        'title' => $quizData['title'],
                        'min_score' => $quizData['min_score'],
                        'is_completed' => $attempt && $attempt['passed'],
                        'last_score' => $attempt ? $attempt['score'] : null
                    ];
                    
                    // Certificate Logic (Phase 4)
                    if ($quiz['is_completed']) {
                        $certificate_available = true;
                    }
                }
            } catch (Throwable $e) {
                // Quiz table missing? Log and continue with quiz: null
                error_log("Quiz Logic Error (Bypassed): " . $e->getMessage());
                $quiz = null;
            }

            Response::json([
                'module' => $module,
                'lessons' => $lessons,
                'quiz' => $quiz,
                'certificate_available' => $certificate_available,
                'locked' => false
            ]);

        } catch (PDOException $e) {
            Response::error('Database Error: ' . $e->getMessage(), 500);
        }
    }
    // GET /lms/resources
    public function resources() {
        try {
            $sql = "
                SELECT r.* 
                FROM lms_resources r
                INNER JOIN lms_resource_access ra ON r.id = ra.resource_id
                WHERE r.is_active = 1 
                  AND r.status = 'approved'
                  AND ra.licenciada_id = ?
                ORDER BY r.created_at DESC
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->user['id']]);
            $resources = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fallback de resiliência: se a tabela de acessos individuais estiver vazia, retornar recursos públicos aprovados
            if (empty($resources)) {
                try {
                    $fallbackSql = "SELECT * FROM lms_resources WHERE is_active = 1 AND status = 'approved' ORDER BY created_at DESC LIMIT 50";
                    $stmtFallback = $this->pdo->prepare($fallbackSql);
                    $stmtFallback->execute();
                    $resources = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
                } catch (\Throwable $e) {
                    $resources = [];
                }
            }
            
            // Add signed URLs for secure download and streaming
            $resourceService = new ResourceService($this->pdo);
            foreach ($resources as &$res) {
                $isAudio = strpos($res['file_type'], 'audio') !== false || strpos($res['file_type'], 'mp3') !== false;
                
                $res['download_url'] = $resourceService->generateSignedUrl($res['id'], $this->user['id']);
                
                if ($isAudio) {
                    $res['stream_url'] = $resourceService->generateStreamUrl($res['id']);
                }
                
                // Sanitize sensitive path
                unset($res['file_path']);
            }
            
            Response::json($resources);
        } catch (PDOException $e) {
            Response::error('Database Error: ' . $e->getMessage(), 500);
        }
    }

    // POST /lms/progress
    public function saveProgress() {
        $input = json_decode(file_get_contents("php://input"), true);
        $lessonId = $input['lesson_id'] ?? 0;
        $progress = $input['progress_percent'] ?? 0;
        $completed = isset($input['is_completed']) ? (int)$input['is_completed'] : 0;
        $time = $input['current_time'] ?? 0;

        if (!$lessonId) Response::error('Lesson ID required', 400);

        // V97: Validate lesson exists before saving progress
        $userId = $this->user['id'] ?? 0;
        if (!$userId) {
            error_log("[V97_SAVE_PROGRESS] CRITICAL: No user ID in session. Input: " . json_encode($input));
            Response::error('User session invalid', 401);
            return;
        }

        try {
            // Atualizar last_active_lesson_id para a inteligência do Dashboard Bento (V65)
            $stmtLast = $this->pdo->prepare("UPDATE licenciadas SET last_active_lesson_id = ? WHERE id = ?");
            $stmtLast->execute([$lessonId, $userId]);

            // Check if progress entry exists
            $stmt = $this->pdo->prepare("SELECT id, is_completed FROM lms_progress WHERE licenciada_id = ? AND lesson_id = ?");
            $stmt->execute([$userId, $lessonId]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Log LESSON_COMPLETE only on state change
                if ($completed && !$existing['is_completed']) {
                    $this->logger->log($userId, 'LESSON_COMPLETE', ['lesson_id' => $lessonId]);
                }
                
                $sql = "UPDATE lms_progress SET progress_percent = ?, is_completed = ?, last_watched_at = NOW() WHERE id = ?";
                $updateStmt = $this->pdo->prepare($sql);
                $updateStmt->execute([$progress, $completed, $existing['id']]);
                
                // V97: Verify update actually affected a row
                if ($updateStmt->rowCount() === 0) {
                    error_log("[V97_SAVE_PROGRESS] WARNING: UPDATE affected 0 rows for progress_id={$existing['id']}, licenciada={$userId}, lesson={$lessonId}");
                }
            } else {
                // First time -> Log PLAY (throttled by frontend to avoid flood)
                $this->logger->log($userId, 'PLAY', ['lesson_id' => $lessonId]);

                $sql = "INSERT INTO lms_progress (licenciada_id, lesson_id, progress_percent, is_completed, last_watched_at) VALUES (?, ?, ?, ?, NOW())";
                $insertStmt = $this->pdo->prepare($sql);
                $insertResult = $insertStmt->execute([$userId, $lessonId, $progress, $completed]);
                
                // V97: Verify insert succeeded
                if (!$insertResult || $insertStmt->rowCount() === 0) {
                    error_log("[V97_SAVE_PROGRESS] CRITICAL: INSERT failed for licenciada={$userId}, lesson={$lessonId}. " .
                              "PDO errorInfo: " . json_encode($insertStmt->errorInfo()));
                }
            }
            
            // V76: Invalidate cached module list so UI gets fresh progress on reload
            ResponseCache::invalidate("api_lms_modules_{$userId}");

            Response::json(['success' => true]);
        } catch (PDOException $e) {
            // V97: Log full context on failure
            error_log("[V97_SAVE_PROGRESS] PDOException for licenciada={$userId}, lesson={$lessonId}: " . $e->getMessage());
            Response::error('Database Error: ' . $e->getMessage(), 500);
        }
    }

    // POST /lms/sign-url
    public function signUrl() {
        $data = json_decode(file_get_contents('php://input'), true);
        $lessonId = $data['lesson_id'] ?? null;

        if (!$lessonId) Response::error('Lesson ID required', 400);

        try {
            // Verify student has access to this lesson (Simple check: is it in an active module?)
            $stmt = $this->pdo->prepare("
                SELECT l.id, l.video_type 
                FROM lms_lessons l
                INNER JOIN lms_modules m ON l.module_id = m.id
                WHERE l.id = ? AND m.is_active = 1 AND l.is_active = 1
            ");
            $stmt->execute([$lessonId]);
            $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lesson || $lesson['video_type'] !== 'hostinger') {
                Response::error('Video not available for streaming', 404);
            }

            $secret = getenv('APP_SECRET') ?: 'BodyHarmonySecretKey2026';
            $expires = time() + 3600; // 1 Hour
            $signature = hash_hmac('sha256', "$lessonId:$expires", $secret);

            $url = "/api/v1/stream.php?id=$lessonId&expires=$expires&signature=$signature";

            Response::json(['url' => $url]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // GET /lms/thumbnail/{filename} - Serve thumbnails from private_uploads
    /**
     * POST /api/v1/lms/auto-thumbnail
     * Saves a frame extracted by the frontend to the local storage.
     */
    public function saveAutoThumbnail() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $moduleId = $data['moduleId'] ?? null;
        $lessonId = $data['lessonId'] ?? null;
        $imageData = $data['image'] ?? null; // Base64
        
        if (!$imageData || (!$moduleId && !$lessonId)) {
            Response::error('Dados incompletos', 400);
        }

        try {
            // 1. Process Base64
            if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
                $imageData = substr($imageData, strpos($imageData, ',') + 1);
                $type = strtolower($type[1]); // jpg, png, etc
                if (!in_array($type, ['jpg', 'jpeg', 'png', 'webp'])) {
                    throw new Exception('Formato de imagem inválido');
                }
                $imageData = base64_decode($imageData);
                if ($imageData === false) {
                    throw new Exception('Falha ao decodificar base64');
                }
            } else {
                throw new Exception('Formato base64 inválido');
            }

            // 2. Prepare Directory
            $thumbDir = PRIVATE_UPLOADS_DIR . '/thumbnails';
            if (!is_dir($thumbDir)) {
                mkdir($thumbDir, 0755, true);
            }

            // 3. Generate Filename
            $filename = ($moduleId ? "module_{$moduleId}" : "lesson_{$lessonId}") . "_" . time() . ".jpg";
            $filepath = $thumbDir . '/' . $filename;

            // 4. Save File
            file_put_contents($filepath, $imageData);

            // 5. Update Database
            if ($moduleId) {
                $stmt = $this->pdo->prepare("UPDATE lms_modules SET cover_image = ? WHERE id = ?");
                $stmt->execute(['thumbnails/' . $filename, $moduleId]);
            } else {
                $stmt = $this->pdo->prepare("UPDATE lms_lessons SET thumbnail_ref = ? WHERE id = ?");
                $stmt->execute(['thumbnails/' . $filename, $lessonId]);
            }

            // 6. Clear cache to reflect changes immediately
            ResponseCache::clear("api_lms_modules_");
            ResponseCache::clear("admin_lms_modules_");

            Response::json([
                'success' => true,
                'path' => 'thumbnails/' . $filename
            ]);

        } catch (Exception $e) {
            Response::error('Erro ao salvar thumbnail: ' . $e->getMessage(), 500);
        }
    }

    public function serveThumbnail($filename) {
        $filename = urldecode($filename);
        // Normalize: Strip 'thumbnails/' prefix if passed (DB stores relative path)
        $cleanName = str_replace('thumbnails/', '', $filename);
        $cleanName = basename($cleanName); // Double security

        // Security: Allow alphanumeric, dash, underscore, dot, spaces, and accented chars (UTF-8 safe)
        if (!preg_match('/^[\w\s\.\-\(\)\p{L}]+$/u', $cleanName)) {
            http_response_code(400);
            die('Invalid filename');
        }
        
        $pathThumb = PRIVATE_UPLOADS_DIR . '/thumbnails/' . $cleanName;
        $pathRoot  = PRIVATE_UPLOADS_DIR . '/' . $cleanName;
        
        $path = null;
        if (file_exists($pathThumb)) {
            $path = $pathThumb;
        } elseif (file_exists($pathRoot)) {
            $path = $pathRoot;
        } else {
            // Case-Insensitive Fallback (Crucial for Linux servers)
            $dir = PRIVATE_UPLOADS_DIR;
            $files = scandir($dir);
            foreach ($files as $f) {
                if (strcasecmp($f, $cleanName) === 0) {
                    $path = $dir . '/' . $f;
                    break;
                }
            }
            
            // Also check thumbnails subfolder case-insensitively
            if (!$path && is_dir($dir . '/thumbnails')) {
                $subFiles = scandir($dir . '/thumbnails');
                foreach ($subFiles as $f) {
                    if (strcasecmp($f, $cleanName) === 0) {
                        $path = $dir . '/thumbnails/' . $f;
                        break;
                    }
                }
            }

            // Fuzzy Fallback: Extract keywords from requested name and match against disk files
            // Handles BD↔Disk naming mismatch (e.g. legacy accented names vs sanitized filenames)
            if (!$path && is_dir($dir . '/thumbnails')) {
                $normalized = preg_replace('/[_\s]+/', ' ', pathinfo($cleanName, PATHINFO_FILENAME));
                $normalized = strtolower(trim($normalized));
                // Extract meaningful words (>= 3 chars) for fuzzy matching
                $keywords = array_filter(explode(' ', $normalized), fn($w) => strlen($w) >= 3);
                
                if (count($keywords) >= 2) {
                    $thumbFiles = scandir($dir . '/thumbnails');
                    $bestMatch = null;
                    $bestScore = 0;
                    
                    foreach ($thumbFiles as $f) {
                        if ($f === '.' || $f === '..') continue;
                        $fNorm = strtolower(preg_replace('/[_\s]+/', ' ', pathinfo($f, PATHINFO_FILENAME)));
                        $score = 0;
                        foreach ($keywords as $kw) {
                            if (strpos($fNorm, $kw) !== false) $score++;
                        }
                        // Require at least 60% keyword match
                        if ($score > $bestScore && $score >= max(2, intval(count($keywords) * 0.6))) {
                            $bestScore = $score;
                            $bestMatch = $f;
                        }
                    }
                    
                    if ($bestMatch) {
                        $path = $dir . '/thumbnails/' . $bestMatch;
                        error_log("[THUMB_FUZZY] Resolved '$cleanName' → '$bestMatch' (score: $bestScore/" . count($keywords) . ")");
                    }
                }
            }
        }
        
        if (!$path || !file_exists($path)) {
            error_log("[THUMB_404] File not found: '$cleanName' in " . PRIVATE_UPLOADS_DIR);
            http_response_code(404);
            die('Thumbnail not found');
        }
        
        $mimeType = mime_content_type($path);
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
        
        if (!in_array($mimeType, $allowedTypes)) {
            http_response_code(403);
            die('Invalid file type');
        }
        
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=31536000'); // 1 year cache
        header('Access-Control-Allow-Origin: *'); // Allow CORS
        readfile($path);
        exit;
    }

    // PATCH /lms/lessons/{id}/duration
    public function updateDuration($lessonId) {
        $input = json_decode(file_get_contents('php://input'), true);
        $duration = $input['duration_seconds'] ?? null;
        
        if (!$duration || $duration <= 0) {
            Response::error('Invalid duration', 400);
        }
        
        // Update only if current duration is 0 (to avoid overwriting manual edits)
        $stmt = $this->pdo->prepare("UPDATE lms_lessons SET duration_seconds = ? WHERE id = ? AND duration_seconds = 0");
        $stmt->execute([$duration, $lessonId]);
        
        Response::json(['success' => true, 'duration_seconds' => $duration]);
    }

    // PATCH /lms/lessons/{id}/thumbnail
    public function updateThumbnail($lessonId) {
        $input = json_decode(file_get_contents('php://input'), true);
        $thumbnail = $input['thumbnail_base64'] ?? null;
        
        if (!$thumbnail || !str_starts_with($thumbnail, 'data:image/')) {
            Response::error('Invalid thumbnail data', 400);
        }
        
        $stmt = $this->pdo->prepare("UPDATE lms_lessons SET thumbnail_base64 = ? WHERE id = ?");
        $stmt->execute([$thumbnail, $lessonId]);
        
        Response::json(['success' => true]);
    }
}

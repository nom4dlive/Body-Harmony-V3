<?php
// api/config.php

/**
 * --------------------------------------------------------------------------
 * ENVIRONMENT VARIABLE LOADER (Unified & Robust)
 * --------------------------------------------------------------------------
 * Single source of truth for loading .env files
 */

// Autoload Composer Dependencies
require_once __DIR__ . '/../vendor/autoload.php';

class EnvLoader
{
    private static $loaded = false;
    private static $loadedPath = null;

    /**
     * Load .env file from multiple possible locations
     * Populates $_ENV, $_SERVER, and putenv() for maximum compatibility
     */
        /**
     * Load .env file from standardized location
     */
    public static function load()
    {
        if (self::$loaded) {
            return self::$loadedPath;
        }

        // Docker environment detection
        if (file_exists('/.dockerenv')) {
            self::$loaded = true;
            return null;
        }

        // Standardized path resolution
        $basePath = dirname(__DIR__, 3); // Always /apps/web-app from /api
        $envPath = $basePath . '/.env';
        
        if (file_exists($envPath) && is_readable($envPath)) {
            self::parseEnvFile($envPath);
            self::$loaded = true;
            self::$loadedPath = $envPath;
            return $envPath;
        }

        // Fallback to project root for local development
        $rootPath = dirname(__DIR__, 4) . '/.env';
        if (file_exists($rootPath) && is_readable($rootPath)) {
            self::parseEnvFile($rootPath);
            self::$loaded = true;
            self::$loadedPath = $rootPath;
            return $rootPath;
        }

        self::$loaded = true;
        return null;
    }

    /**
     * Parse .env file and populate environment
     */
    private static function parseEnvFile($path)
    {
        if (!file_exists($path))
            return;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false)
            return;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#')
                continue;

            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if (preg_match('/^"(.*)"$/', $value, $m))
                    $value = $m[1];
                elseif (preg_match("/^'(.*)'$/", $value, $m))
                    $value = $m[1];

                // Nexus Protocol: The file is the Source of Truth. Overwrite existing env.
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

/**
 * Helper function to get environment variables
 * Checks $_ENV, $_SERVER, and getenv() in that order
 * 
 * @param string $key Variable name
 * @param mixed $default Default value if not found
 * @return mixed
 */
function env($key, $default = null)
{
    // Priority 1: $_ENV
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }

    // Priority 2: $_SERVER
    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }

    // Priority 3: getenv()
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }

    return $default;
}

/**
 * --------------------------------------------------------------------------
 * DB STAGE CONFIGURATION (Oracle Migration Support)
 * --------------------------------------------------------------------------
 * Allows switching between Hostinger (legacy) and Oracle Cloud (stage/stable).
 */
function get_db_credentials()
{
    $stage = env('DB_STAGE', 'PROD'); // PROD = Hostinger, STAGE = Oracle

    if ($stage === 'STAGE') {
        return [
            'host' => env('DB_STAGE_HOST'),
            'name' => env('DB_STAGE_NAME'),
            'user' => env('DB_STAGE_USER'),
            'pass' => env('DB_STAGE_PASS'),
            'label' => 'ORACLE_STAGE'
        ];
    }

    return [
        'host' => env('DB_HOST'),
        'name' => env('DB_NAME'),
        'user' => env('DB_USER'),
        'pass' => env('DB_PASS'),
        'label' => 'HOSTINGER_PROD'
    ];
}

// Load environment variables
$envPath = EnvLoader::load();
if ($envPath) {
    define('ENV_PATH', $envPath);
}

/**
 * --------------------------------------------------------------------------
 * PATH STANDARDIZATION (NO-DOCKER SUPPORT)
 * --------------------------------------------------------------------------
 * Define absolute paths to avoid relative path hell (`../../..`) and 
 * ensure compatibility between Windows (Local) and Hostinger (Production).
 */

// 1. Detect FileSystem Root (Project Root)
// config.php is in [Root]/apps/web-app/src/backend/api/
$currentDir = __DIR__;

// Try 5 levels up first (Local Dev Structure: apps/web-app/src/backend/api -> Root)
$candidateRoot = realpath(__DIR__ . '/../../../../..');


if (!$candidateRoot || !file_exists($candidateRoot . '/private_uploads')) {
    // Fallback: Production Structure (public_html/api -> public_html -> Root)
    $candidateRoot = realpath(__DIR__ . '/../..');
}

// Final Fallback: If still not found, try 4 levels (Legacy/Docker?)
if ((!$candidateRoot || !file_exists($candidateRoot . '/private_uploads'))) {
    $candidateRoot = realpath(__DIR__ . '/../../../..');
}

define('FS_ROOT', $candidateRoot);

// 2. Define Storage Paths
// Private: Outside public_html (Security)
define('PRIVATE_UPLOADS_DIR', FS_ROOT . '/private_uploads');

// Public: Accessible via Browser
$serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
$isLocal = ($serverName === 'localhost' || $serverName === '127.0.0.1');

if ($isLocal) {
    // Local Dev (No Docker) - Use Backend Source as Public Root
    if (!defined('PUBLIC_ROOT')) {
        define('PUBLIC_ROOT', FS_ROOT . '/apps/web-app/src/backend');
    }
}
else {
    // Production - Use standard structure
    if (!defined('PUBLIC_ROOT')) {
        define('PUBLIC_ROOT', FS_ROOT . '/public_html');
    }

    // Safety check for Hostinger "jailed" paths
    if (!file_exists(PUBLIC_ROOT)) {
        define('PUBLIC_ROOT', realpath(__DIR__ . '/../../..'));
    }
}

define('PUBLIC_UPLOADS_DIR', PUBLIC_ROOT . '/uploads');

// Auto-create symlink for private_uploads inside public_html if not exists (Hostinger Production)
if (!$isLocal) {
    $symlinkPath = PUBLIC_ROOT . '/private_uploads';
    if (is_link($symlinkPath) && !file_exists($symlinkPath)) {
        @unlink($symlinkPath); // delete broken symlink
    }
    if (!file_exists($symlinkPath)) {
        @symlink(PRIVATE_UPLOADS_DIR, $symlinkPath);
    }
}

// 3. Define Logs Path (Nexus V94)
if ($isLocal) {
    define('LOGS_DIR', FS_ROOT . '/apps/web-app/src/backend/logs');
} else {
    // Production (Hostinger): Must be inside public_html for writability usually
    define('LOGS_DIR', PUBLIC_ROOT . '/api/logs');
}


/**
 * --------------------------------------------------------------------------
 * DATABASE CONFIGURATION
 * --------------------------------------------------------------------------
 */

// Get database credentials
$creds = get_db_credentials();
$db_host = $creds['host'];
$db_name = $creds['name'];
$db_user = $creds['user'];
$db_pass = $creds['pass'];

// DEV FALLBACK (Docker/Local) — Credentials MUST come from .env (Zero Hardcode Policy V81)
if (!$db_host && (file_exists('/.dockerenv') || ($_SERVER['SERVER_NAME'] ?? '') === 'localhost')) {
    error_log("DB CONNECT: No credentials found. Ensure .env file exists with DB_HOST, DB_NAME, DB_USER, DB_PASS.");
}

// Fail fast if credentials are not configured
if (!$db_host || !$db_name || !$db_user || $db_pass === null) {
    $debugInfo = [
        'ENV_PATH' => defined('ENV_PATH') ? ENV_PATH : 'NOT LOADED',
        'STAGE' => env('DB_STAGE', 'PROD'),
        'DB_HOST' => $db_host ? 'SET' : 'MISSING',
        'DB_NAME' => $db_name ? 'SET' : 'MISSING',
        'DB_USER' => $db_user ? 'SET' : 'MISSING',
        'DB_PASS' => ($db_pass !== null) ? 'SET' : 'MISSING',
    ];

    error_log('Database credentials error: ' . json_encode($debugInfo));
    die('FATAL: Database credentials not configured. Check .env file. Debug: ' . json_encode($debugInfo));
}

/**
 * --------------------------------------------------------------------------
 * HEADER HELPER (Case-Insensitive)
 * --------------------------------------------------------------------------
 */
function getallheaders_robust()
{
    $headers = [];

    // 1. Try native function (if available and reliable)
    if (function_exists('getallheaders')) {
        $native = getallheaders();
        if ($native !== false) {
            foreach ($native as $name => $value) {
                $headers[strtoupper($name)] = $value;
            }
        }
    }

    // 2. Fallback via $_SERVER (HTTP_...) - Essential for CGI/Hostinger
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headerName = str_replace('_', '-', substr($name, 5));
            $headers[strtoupper($headerName)] = $value;
        }
    }

    // 3. Special cases (Authorization often hidden)
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers['AUTHORIZATION'] = $_SERVER['HTTP_AUTHORIZATION'];
    }
    elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $headers['AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    // 4. Custom device token often sent without HTTP_ prefix in some configs
    if (isset($_SERVER['X_DEVICE_TOKEN'])) {
        $headers['X-DEVICE-TOKEN'] = $_SERVER['X_DEVICE_TOKEN'];
    }

    return $headers;
}

/**
 * --------------------------------------------------------------------------
 * DATABASE CONNECTION
 * --------------------------------------------------------------------------
 */

// Timezone configuration
date_default_timezone_set('America/Sao_Paulo');

// DEBUG: Dump config values to screen
// if (isset($_GET['thumb']) || isset($_GET['debug_db'])) { ... } (Removed)

/**
 * --------------------------------------------------------------------------
 * DATABASE CONNECTION (Lazy Loader V44.0.2)
 * --------------------------------------------------------------------------
 * We use a Proxy approach to avoid opening connections until actually needed.
 * This saves connections for static/fallback routes and avoids MySQL 1226 errors.
 */

class LazyDb
{
    private $pdo = null;
    private $host, $name, $user, $pass, $label;

    public function __construct($host, $name, $user, $pass, $label = 'DB_NODE')
    {
        $this->host = $host;
        $this->name = $name;
        $this->user = $user;
        $this->pass = $pass;
        $this->label = $label;
    }

    private function connect()
    {
        if ($this->pdo !== null)
            return $this->pdo;

        // Circuit Breaker for Oracle Cloud (Fast-Fail)
        $isOracle = ($this->label === 'ORACLE_CLOUD' || $this->label === 'ORACLE_STAGE');
        $circuitFile = sys_get_temp_dir() . '/bh_oracle_down.tmp';

        if ($isOracle) {
            if (file_exists($circuitFile)) {
                $lastFailure = (int)@file_get_contents($circuitFile);
                if (time() - $lastFailure < 300) {
                    // Stability Shield V100: 5min cooldown (era 60s)
                    throw new PDOException("Circuit Breaker: Oracle Cloud node is offline (Fast-Fail Active).");
                }
            }
        }

        $oldSocketTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', 2);

        try {
            $this->pdo = new PDO("mysql:host={$this->host};dbname={$this->name};charset=utf8mb4", $this->user, $this->pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Persistent: desativado em produção para evitar estouro de max_connections_per_hour na Hostinger
                PDO::ATTR_PERSISTENT => false,
                // Timeout de 2s para não bloquear em caso de DB overload (Stability Shield V100)
                PDO::ATTR_TIMEOUT => 2,
            ]);
            
            ini_set('default_socket_timeout', $oldSocketTimeout);

            // Tenta definir o timezone, mas falha silenciosamente se o banco não permitir
            try {
                $this->pdo->exec("SET time_zone = '-03:00'");
            }
            catch (Exception $e) {
            }

            // Connection succeeded: ensure circuit file is cleared if it exists
            if ($isOracle && file_exists($circuitFile)) {
                @unlink($circuitFile);
            }

            return $this->pdo;
        }
        catch (PDOException $e) {
            ini_set('default_socket_timeout', $oldSocketTimeout);

            // Write failure timestamp to trip the circuit breaker
            if ($isOracle) {
                @file_put_contents($circuitFile, time());
            }
            // Throw so upper layers (like ResponseCache) can catch and serve slate data
            throw $e;
        }
    }

    public function exec($statement)
    {
        return $this->connect()->exec($statement);
    }

    public function __call($method, $args)
    {
        return call_user_func_array([$this->connect(), $method], $args);
    }

    // Explicitly handle prepare for common usage
    public function prepare($sql, $options = [])
    {
        return $this->connect()->prepare($sql, $options);
    }

    public function query($sql, $fetchMode = null, ...$fetchModeArgs)
    {
        return $this->connect()->query($sql, $fetchMode, ...$fetchModeArgs);
    }

    public function lastInsertId($name = null)
    {
        return $this->connect()->lastInsertId($name);
    }

    public function beginTransaction()
    {
        return $this->connect()->beginTransaction();
    }

    public function commit()
    {
        return $this->connect()->commit();
    }

    public function rollBack()
    {
        return $this->connect()->rollBack();
    }

    public function inTransaction()
    {
        return $this->connect()->inTransaction();
    }

    public function getLabel()
    {
        return $this->label;
    }

    public function getActiveNode(): string
    {
        return $this->label;
    }
}

/**
 * --------------------------------------------------------------------------
 * DATABASE FAILOVER ENGINE (V98 - PLAN-002)
 * --------------------------------------------------------------------------
 * Tries Oracle (primary) first, falls back to Hostinger if Oracle is down.
 * Tracks active node globally for observability.
 */
class DbFailover
{
    private $primary;       // LazyDb Oracle
    private $fallback;      // LazyDb Hostinger
    private $activePdo = null;
    private $activeNode = null;

    public function __construct(LazyDb $primary, LazyDb $fallback)
    {
        $this->primary = $primary;
        $this->fallback = $fallback;
    }

    private function resolve()
    {
        if ($this->activePdo !== null) return $this->activePdo;

        // Try primary node first
        try {
            $this->primary->query("SELECT 1");
            $this->activePdo = $this->primary;
            // Infer node name from pdo instance
            $this->activeNode = $this->primary->getLabel(); 
        } catch (\Exception $e) {
            // Fallback to secondary node
            try {
                $this->fallback->query("SELECT 1");
                $this->activePdo = $this->fallback;
                $this->activeNode = $this->fallback->getLabel();
            } catch (\Exception $e2) {
                throw $e2; // Both nodes down
            }
        }
        return $this->activePdo;
    }

    public function getActiveNode(): string
    {
        if ($this->activeNode === null) $this->resolve();
        return $this->activeNode;
    }

    public function __call($method, $args)
    {
        return call_user_func_array([$this->resolve(), $method], $args);
    }

    public function prepare($sql, $options = [])
    {
        return $this->resolve()->prepare($sql, $options);
    }

    public function query($sql, $fetchMode = null, ...$fetchModeArgs)
    {
        return $this->resolve()->query($sql, $fetchMode, ...$fetchModeArgs);
    }

    public function lastInsertId($name = null)
    {
        return $this->resolve()->lastInsertId($name);
    }

    public function exec($sql)
    {
        return $this->resolve()->exec($sql);
    }

    public function beginTransaction() { return $this->resolve()->beginTransaction(); }
    public function commit() { return $this->resolve()->commit(); }
    public function rollBack() { return $this->resolve()->rollBack(); }
    public function inTransaction() { return $this->resolve()->inTransaction(); }
}

// Oracle DB Credentials
function get_oracle_credentials()
{
    return [
        'host' => env('DB_STAGE_HOST', env('DB_HOST')),
        'name' => env('DB_STAGE_NAME', env('DB_NAME')),
        'user' => env('DB_STAGE_USER', env('DB_USER')),
        'pass' => env('DB_STAGE_PASS', env('DB_PASS')),
        'label' => 'ORACLE_STAGE'
    ];
}

// Hostinger Credentials (always local/direct)
$hostingerCreds = [
    'host' => env('DB_HOST', 'localhost'),
    'name' => env('DB_NAME'),
    'user' => env('DB_USER'),
    'pass' => env('DB_PASS')
];

// Initialize local LazyDb node directly (decoupled from Oracle)
$pdoHostinger = new LazyDb($hostingerCreds['host'], $hostingerCreds['name'], $hostingerCreds['user'], $hostingerCreds['pass'], 'HOSTINGER_PROD');

// Main connection: Direct Hostinger local node
$pdo = $pdoHostinger;

// Initialize VPS dedicated connection specifically for Logging (to avoid shared host CPU query limits)
$vpsLogCreds = [
    'host' => env('DB_STAGE_HOST', '2.25.156.25'),
    'name' => env('DB_STAGE_NAME'),
    'user' => env('DB_STAGE_USER'),
    'pass' => env('DB_STAGE_PASS')
];
$pdoVPSLogs = new LazyDb($vpsLogCreds['host'], $vpsLogCreds['name'], $vpsLogCreds['user'], $vpsLogCreds['pass'], 'VPS_LOGS');

// Track active node globally
$ACTIVE_DB_NODE = 'HOSTINGER_PROD';

/**
 * Global helpers (Backward Compatibility)
 */
function get_db_connection()
{
    global $pdo;
    return $pdo;
}

function get_oracle_connection()
{
    global $pdoOracle;
    return $pdoOracle;
}

function get_hostinger_connection()
{
    global $pdoHostinger;
    return $pdoHostinger;
}

function get_active_node()
{
    global $pdo;
    return $pdo->getActiveNode();
}

function getDbConnection()
{
    return get_db_connection();
}

function getConnection()
{
    return get_db_connection();
}

// Observability Initialization: Initialize NexusLogger on the VPS logs connection
require_once __DIR__ . '/v1/Core/NexusLogger.php';
NexusLogger::init($pdoVPSLogs);
set_error_handler(['NexusLogger', 'handleError']);
set_exception_handler(['NexusLogger', 'handleException']);


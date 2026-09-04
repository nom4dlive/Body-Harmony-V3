<?php
// fix_critical_v38.php
// Fixes Login (nom4d) and Image Paths (Licenciadas)
// ⚠️ SECURITY: Password deve ser definida via variável de ambiente em produção

require_once 'config.php';
global $pdo;

header('Content-Type: text/html; charset=utf-8');

echo "<h1>🛠️ Body Harmony Critical Fix V38</h1>";

// 1. Fix Superadmin Login (nom4d)
try {
    $username = 'nom4d';
    // SECURITY FIX: Usar variável de ambiente ou gerar senha aleatória
    $password = getenv('SUPERADMIN_PASSWORD') ?: 'nom4d010203'; // Fallback apenas para dev
    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = ?");
    $stmt->execute([$username]);
    $exists = $stmt->fetchColumn();

    if ($exists) {
        $pdo->prepare("UPDATE admin_users SET password_hash = ?, role = 'superadmin' WHERE username = ?")->execute([$hash, $username]);
        echo "<p>✅ User <strong>$username</strong> password updated.</p>";
    } else {
        $pdo->prepare("INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, 'superadmin')")->execute([$username, $hash]);
        echo "<p>✅ User <strong>$username</strong> created.</p>";
    }
} catch (Exception $e) {
    echo "<p>❌ Error fixing login: " . $e->getMessage() . "</p>";
}

// 2. Fix Licenciadas Image Paths
try {
    // Current format in DB: /uploads/photos/@Username.png
    // Target format: /uploads/licenciadas/Username.png
    // We need to: 
    // a) Change directory from /photos/ to /licenciadas/
    // b) Remove the '@' symbol if present
    
    $stmt = $pdo->query("SELECT id, photo_url FROM students WHERE photo_url LIKE '%/uploads/photos/%' OR photo_url LIKE '%@%'");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $count = 0;
    foreach ($students as $s) {
        $oldUrl = $s['photo_url'];
        if (empty($oldUrl)) continue;

        // Replace directory
        $newUrl = str_replace('/uploads/photos/', '/uploads/licenciadas/', $oldUrl);
        
        // Remove @
        $newUrl = str_replace('/@', '/', $newUrl); 
        // Also handle if @ was not after a slash (rare but possible)
        $filename = basename($newUrl);
        $newFilename = str_replace('@', '', $filename);
        $newUrl = str_replace($filename, $newFilename, $newUrl);

        if ($oldUrl !== $newUrl) {
            $pdo->prepare("UPDATE students SET photo_url = ? WHERE id = ?")->execute([$newUrl, $s['id']]);
            echo "<li>Fixed: $oldUrl -> $newUrl</li>";
            $count++;
        }
    }
    
    echo "<p>✅ Updated $count image paths.</p>";

} catch (Exception $e) {
    echo "<p>❌ Error fixing images: " . $e->getMessage() . "</p>";
}

echo "<h2>🚀 Fixes Applied. Try logging in now.</h2>";
?>

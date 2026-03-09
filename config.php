<?php
// ── DB CONFIG ── Uses environment variables on Render, falls back to XAMPP defaults locally
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'bikevalue');
define('DB_PORT', getenv('DB_PORT') ?: '3306');

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        die('<div style="font-family:monospace;background:#1a0a0a;color:#f87171;padding:2rem;margin:2rem;border-radius:8px;border:1px solid #f87171;">
        <b>⚠ Database not connected.</b><br><br>
        Run <code>setup_db.sql</code> in phpMyAdmin first, then update <code>config.php</code> with your credentials.<br><br>
        Error: '.$e->getMessage().'
        </div>');
    }
    return $pdo;
}

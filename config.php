<?php
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
            'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]
        );
    } catch (PDOException $e) {
        die('<div style="font-family:monospace;background:#1a0a0a;color:#f87171;padding:2rem;margin:2rem;border-radius:8px;border:1px solid #7f1d1d">
        <b>⚠ Database not connected.</b><br><br>
        Error: '.$e->getMessage().'
        </div>');
    }
    return $pdo;
}
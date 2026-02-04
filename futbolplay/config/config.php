<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'futbolplay');
define('DB_USER', 'root');
define('DB_PASS', '');  // Vacío por defecto en XAMPP/WAMP
define('DB_CHARSET', 'utf8mb4');


date_default_timezone_set('America/Bogota');


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


function getDBConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch(PDOException $e) {
        die("Error de conexión: " . $e->getMessage());
    }
}

?>
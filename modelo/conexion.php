<?php
// conexion.php

class Conexion {
    private $host = '127.0.0.1'; // O la IP de tu servidor de base de datos
    private $db_name = 'bibliotecau'; // Nombre de tu base de datos
    private $username = 'root'; // Tu usuario de base de datos
    private $password = ''; // Tu contraseña de base de datos (vacía por defecto en XAMPP/WAMP)
    public $pdo;

    public function __construct() {
        $this->pdo = null;
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ, // Fetches results as objects
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8" // Ensures UTF-8 encoding
            ];
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            die("Error de conexión a la base de datos: " . $e->getMessage());
        }
    }
}
?>
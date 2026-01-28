<?php
// conexion.php

class Conexion
{
    private $host = '127.0.0.1'; // O la IP de tu servidor de base de datos
    private $db_name = 'bibliotecau'; // Nombre de tu base de datos
    private $username = 'root'; // Tu usuario de base de datos
    private $password = ''; // Tu contraseña de base de datos (vacía por defecto en XAMPP/WAMP)
    public $pdo;

    public function __construct()
    {
        // Configurar Zona Horaria en PHP
        date_default_timezone_set('America/Lima');

        $this->pdo = null;
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8, time_zone = '-05:00'"
            ];
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            die("Error de conexión a la base de datos: " . $e->getMessage());
        }
    }
}

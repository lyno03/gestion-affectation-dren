<?php
// Paramètres de connexion à la base de données
$hote   = "localhost";
$base   = "dren_db";
$user   = "root";
$mdp    = "";   // XAMPP n'a pas de mot de passe par défaut

try {
    $pdo = new PDO(
        "mysql:host=$hote;dbname=$base;charset=utf8mb4",
        $user,
        $mdp,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données.");
}
<?php
require "config/database.php";

$comptes = [
    ["admin", "Admin@2026", "ADMIN"],
    ["rh1",   "Rh@2026",    "RH"],
    ["dir1",  "Dir@2026",   "DIR"],
];

$stmt = $pdo->prepare(
    "INSERT IGNORE INTO utilisateur (login, mot_de_passe, code_role) VALUES (?, ?, ?)"
);

foreach ($comptes as [$login, $mdp, $role]) {
    $stmt->execute([$login, password_hash($mdp, PASSWORD_DEFAULT), $role]);
    echo "Compte créé : $login ($role)<br>";
}
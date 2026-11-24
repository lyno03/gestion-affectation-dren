<?php
require "config/database.php";

echo "<h2>Connexion réussie</h2>";

$requete = $pdo->query("SELECT nom, district FROM cisco");
foreach ($requete as $ligne) {
    echo $ligne['nom'] . " (" . $ligne['district'] . ")<br>";
}
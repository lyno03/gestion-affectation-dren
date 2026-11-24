<?php
require "config/database.php";
require "includes/auth.php";

// Si déjà connecté, aller à l'accueil
if (!empty($_SESSION['user'])) {
    header("Location: /dren/index.php");
    exit;
}

$erreur = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $mdp   = $_POST['mdp'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE login = ? AND actif = 1");
    $stmt->execute([$login]);
    $u = $stmt->fetch();

    if ($u && password_verify($mdp, $u['mot_de_passe'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'         => $u['id_user'],
            'login'      => $u['login'],
            'role'       => $u['code_role'],
            'code_cisco' => $u['code_cisco'],
            'code_etab'  => $u['code_etab'],
        ];
        header("Location: /dren/index.php");
        exit;
    } else {
        $erreur = "Identifiant ou mot de passe incorrect.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion – DREN Atsimo Andrefana</title>
    <script>
    (function () {
        var t = null;
        try { t = localStorage.getItem('dren_theme'); } catch (e) {}
        if (!t) t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link rel="icon" type="image/png" href="/dren/assets/img/logo-dren.png">
    <link rel="stylesheet" href="/dren/assets/style.css">
</head>
<body class="login">
    <form class="login-card" method="post">
        <div class="login-logos">
            <img src="/dren/assets/img/logo-men.png" alt="Ministère de l'Éducation Nationale" width="84" height="84">
            <img src="/dren/assets/img/logo-dren.png" alt="DREN Atsimo Andrefana" width="84" height="84">
        </div>
        <h1>DREN Atsimo Andrefana</h1>
        <p>Gestion des affectations et suivi des personnels</p>

        <?php if ($erreur): ?>
            <div class="erreur" role="alert"><?= htmlspecialchars($erreur) ?></div>
        <?php endif; ?>

        <div class="champ">
            <label for="login">Identifiant</label>
            <input type="text" id="login" name="login" required autofocus autocomplete="username">
        </div>
        <div class="champ">
            <label for="mdp">Mot de passe</label>
            <div class="mdp-ligne">
                <input type="password" id="mdp" name="mdp" required autocomplete="current-password">
                <button type="button" id="voir-mdp" aria-label="Afficher le mot de passe">Afficher</button>
            </div>
        </div>
        <button class="btn" type="submit">Se connecter</button>
    </form>

    <script>
    var champ = document.getElementById('mdp'), bouton = document.getElementById('voir-mdp');
    bouton.addEventListener('click', function () {
        var cache = champ.type === 'password';
        champ.type = cache ? 'text' : 'password';
        bouton.textContent = cache ? 'Masquer' : 'Afficher';
    });
    </script>
</body>
</html>

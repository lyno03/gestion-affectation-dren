<?php
// Démarre la session (permet de "se souvenir" de l'utilisateur connecté)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Oblige l'utilisateur à être connecté
function exiger_connexion() {
    global $pdo;
    if (empty($_SESSION['user'])) {
        header("Location: /dren/login.php");
        exit;
    }
    if (isset($pdo)) {
        $stmt = $pdo->prepare("SELECT actif, code_role, code_cisco, code_etab FROM utilisateur WHERE id_user = ?");
        $stmt->execute([$_SESSION['user']['id']]);
        $u = $stmt->fetch();
        if (!$u || !$u['actif']) {
            $_SESSION = [];
            session_destroy();
            header("Location: /dren/login.php");
            exit;
        }
        // Rôle et rattachement toujours à jour
        $_SESSION['user']['role']       = $u['code_role'];
        $_SESSION['user']['code_cisco'] = $u['code_cisco'];
        $_SESSION['user']['code_etab']  = $u['code_etab'];
    }
}

// Oblige l'utilisateur à avoir un des rôles autorisés
function exiger_role(array $roles) {
    exiger_connexion();
    if (!in_array($_SESSION['user']['role'], $roles)) {
        http_response_code(403);
        die("Accès refusé : vous n'avez pas les droits nécessaires.");
    }
}
// Crée (ou retrouve) le jeton de sécurité de la session
function jeton_csrf() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

// Vérifie que le jeton reçu est le bon
function verifier_csrf($jeton) {
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $jeton ?? '');
}
// Condition SQL limitant les agents visibles selon le rôle de l'utilisateur
// Retourne [texte SQL, paramètres]. $a est l'alias de la table agent dans la requête.
function filtre_agent($a = 'a') {
    $r = $_SESSION['user']['role'] ?? '';
    if (in_array($r, ['ADMIN', 'RH', 'DIR'])) {
        return ['1=1', []];
    }
    $base = "EXISTS (SELECT 1 FROM affectation fx
                     JOIN etablissement ex ON ex.code_etab = fx.code_etab
                     WHERE fx.matricule = $a.matricule AND fx.date_fin IS NULL AND ";
    if ($r === 'CISCO' && !empty($_SESSION['user']['code_cisco'])) {
        return [$base . "ex.code_cisco = ?)", [$_SESSION['user']['code_cisco']]];
    }
    if ($r === 'ETAB' && !empty($_SESSION['user']['code_etab'])) {
        return [$base . "fx.code_etab = ?)", [$_SESSION['user']['code_etab']]];
    }
    return ['0=1', []];   // rôle sans rattachement : aucun agent visible
}

// Cet agent appartient-il au périmètre de l'utilisateur connecté ?
function agent_visible(PDO $pdo, $matricule) {
    [$sql, $params] = filtre_agent('a');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM agent a WHERE a.matricule = ? AND $sql");
    $stmt->execute(array_merge([$matricule], $params));
    return $stmt->fetchColumn() > 0;
}
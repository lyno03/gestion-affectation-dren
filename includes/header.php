<?php
// Mise en page commune : barre latérale + barre du haut.
// Les variables locales commencent par h_ pour ne jamais écraser celles des pages.

if (!function_exists('dren_ico')) {
    function dren_ico($nom) {
        static $i = [
            'home'      => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'repeat'    => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
            'chart'     => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            'school'    => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
            'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'clock'     => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'search'    => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'bell'      => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
            'moon'      => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
            'menu'      => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
            'plus'      => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
            'book'      => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
            'file'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
            'eye'       => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'edit'      => '<path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>',
            'trash'     => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
            'user'      => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
            'check'     => '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
            'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            'pin'       => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        ];
        return '<svg class="ico" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             . ($i[$nom] ?? '') . '</svg>';
    }
}

$h_role   = $_SESSION['user']['role'] ?? '';
$h_login  = $_SESSION['user']['login'] ?? '';
$h_global = in_array($h_role, ['ADMIN', 'RH', 'DIR']);
$h_script = $_SERVER['SCRIPT_NAME'] ?? '';
$h_libelles = ['ADMIN' => 'Administrateur', 'RH' => 'Responsable RH', 'DIR' => 'Directeur régional',
               'CISCO' => 'Chef de CISCO', 'ETAB' => "Chef d'établissement"];
$h_titre_role = $h_libelles[$h_role] ?? $h_role;

// ----- Identité de la personne connectée : nom, fonction, lieu de travail -----
$h_nom  = $h_login;                       // remplacé par le nom complet s'il est renseigné
$h_lieu = 'DREN Atsimo Andrefana';        // lieu de travail par défaut (direction régionale)
try {
    $h_st = $pdo->prepare(
        "SELECT c.nom AS cisco, e.nom AS etab, ce.nom AS etab_cisco
         FROM utilisateur u
         LEFT JOIN cisco c ON c.code_cisco = u.code_cisco
         LEFT JOIN etablissement e ON e.code_etab = u.code_etab
         LEFT JOIN cisco ce ON ce.code_cisco = e.code_cisco
         WHERE u.id_user = ?"
    );
    $h_st->execute([$_SESSION['user']['id'] ?? 0]);
    if ($h_r = $h_st->fetch()) {
        if ($h_role === 'CISCO' && $h_r['cisco']) {
            $h_lieu = $h_r['cisco'];
        }
        if ($h_role === 'ETAB' && $h_r['etab']) {
            $h_lieu = $h_r['etab'] . ($h_r['etab_cisco'] ? ' (' . $h_r['etab_cisco'] . ')' : '');
        }
    }
} catch (Throwable $h_e) { /* on garde le lieu par défaut */ }
try {
    $h_st = $pdo->prepare("SELECT nom_complet FROM utilisateur WHERE id_user = ?");
    $h_st->execute([$_SESSION['user']['id'] ?? 0]);
    $h_n = trim((string)$h_st->fetchColumn());
    if ($h_n !== '') { $h_nom = $h_n; }
} catch (Throwable $h_e) { /* la colonne nom_complet n'existe peut-être pas encore */ }

$h_ligne = $h_titre_role . ' · ' . $h_lieu;

// Initiales de l'avatar : première lettre du premier et du dernier mot
$h_mots = preg_split('/\s+/u', trim($h_nom)) ?: [$h_nom];
$h_init = mb_strtoupper(mb_substr($h_mots[0], 0, 1)
        . (count($h_mots) > 1 ? mb_substr(end($h_mots), 0, 1) : mb_substr($h_mots[0], 1, 1)));

// ----- Petits chiffres pour la barre latérale et les notifications -----
$h_total = 0; $h_poste = 0;
try {
    [$h_per, $h_pp] = function_exists('filtre_agent') ? filtre_agent('a') : ['1=1', []];
    $h_st = $pdo->prepare("SELECT COUNT(*) FROM agent a WHERE $h_per");
    $h_st->execute($h_pp);
    $h_total = (int)$h_st->fetchColumn();
    $h_st = $pdo->prepare(
        "SELECT COUNT(*) FROM agent a WHERE $h_per AND EXISTS
         (SELECT 1 FROM affectation x WHERE x.matricule = a.matricule AND x.date_fin IS NULL)"
    );
    $h_st->execute($h_pp);
    $h_poste = (int)$h_st->fetchColumn();
} catch (Throwable $h_e) { /* le menu s'affiche même si ces chiffres échouent */ }
$h_pct = $h_total > 0 ? (int)round($h_poste / $h_total * 100) : 0;
$h_attente = $h_global ? max(0, $h_total - $h_poste) : 0;

// Demandes en attente de décision (visibles par le directeur régional)
$h_dem = 0;   // demandes d'affectation
$h_dp  = 0;   // demandes de personnel
if ($h_role === 'DIR') {
    try {
        $h_dem = (int)$pdo->query("SELECT COUNT(*) FROM demande WHERE statut = 'EN_ATTENTE'")->fetchColumn();
    } catch (Throwable $h_e) { /* la table n'existe peut-être pas encore */ }
    try {
        $h_dp = (int)$pdo->query("SELECT COUNT(*) FROM demande_personnel WHERE statut = 'EN_ATTENTE'")->fetchColumn();
    } catch (Throwable $h_e) { /* la table n'existe peut-être pas encore */ }
}
$h_cg = 0;    // demandes de congé
if ($h_role === 'DIR') {
    try {
        $h_cg = (int)$pdo->query("SELECT COUNT(*) FROM demande_conge WHERE statut = 'EN_ATTENTE'")->fetchColumn();
    } catch (Throwable $h_e) { /* la table n'existe peut-être pas encore */ }
}
// Postes à pourvoir : à traiter par le responsable RH (et l'administrateur)
$h_postes = 0;
if (in_array($h_role, ['RH', 'ADMIN'])) {
    try {
        $h_postes = (int)$pdo->query("SELECT COUNT(*) FROM poste_a_pourvoir WHERE statut = 'A_POURVOIR'")->fetchColumn();
    } catch (Throwable $h_e) { /* la table n'existe peut-être pas encore */ }
}
$h_notifs = $h_dem + $h_dp + $h_cg + $h_postes + $h_attente;

// Entrées du menu : [adresse, texte, icône, visible, fragment pour repérer la page active, pastille, fragment exclu]
$h_menu = [
    ['/dren/index.php', 'Accueil', 'home', true, 'exact', 0, ''],
    ['/dren/pages/agents/liste.php', 'Personnels', 'users', true, '/pages/agents/', 0, ''],
    ['/dren/pages/demandes/liste.php', "Demandes d'affectation", 'check', true, '/pages/demandes/', $h_dem, ''],
    ['/dren/pages/besoins/liste.php', 'Demandes de personnel', 'briefcase', true, '/pages/besoins/', $h_dp, '/pourvoir'],
    ['/dren/pages/besoins/pourvoir.php', 'Postes à pourvoir', 'pin', in_array($h_role, ['RH', 'ADMIN', 'DIR']), '/pourvoir', $h_postes, ''],
    ['/dren/pages/conges/liste.php', 'Demandes de congé', 'calendar', true, '/pages/conges/', $h_cg, '/suivi'],
    ['/dren/pages/conges/suivi.php', 'Suivi des congés', 'clock', true, '/conges/suivi.php', 0, ''],
    ['/dren/pages/affectations/historique.php', 'Affectations', 'repeat', true, '/pages/affectations/', 0, ''],
    ['/dren/pages/statistiques.php', 'Statistiques', 'chart', $h_global, '/statistiques.php', 0, ''],
];
$h_admin = [
    ['/dren/pages/admin/etablissements.php', 'Établissements', 'school', '/admin/etablissements.php'],
    ['/dren/pages/admin/utilisateurs.php', 'Utilisateurs', 'shield', '/admin/utilisateurs.php'],
    ['/dren/pages/admin/rapports.php', 'Rapports', 'file', '/admin/rapport'],
    ['/dren/pages/admin/journal.php', 'Journal', 'clock', '/admin/journal.php'],
];
$h_mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août',
           'septembre', 'octobre', 'novembre', 'décembre'];
$h_date = (int)date('j') . ' ' . $h_mois[(int)date('n')] . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($titre_page ?? 'Accueil') ?> – DREN Atsimo Andrefana</title>
    <script>
    // Applique le thème avant l'affichage pour éviter un clignotement
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
<body>
<div class="app">

    <aside class="side" id="side">
        <a class="marque" href="/dren/index.php">
            <span class="logos">
                <img src="/dren/assets/img/logo-men.png" alt="Ministère de l'Éducation Nationale" width="56" height="56">
                <img src="/dren/assets/img/logo-dren.png" alt="DREN Atsimo Andrefana" width="56" height="56">
            </span>
            <span class="marque-txt">DREN<small>Atsimo Andrefana</small></span>
        </a>

        <?php if ($h_role === 'ADMIN'): ?>
            <a class="bouton-haut" href="/dren/pages/affectations/nouvelle.php">+ Nouvelle affectation</a>
        <?php elseif (in_array($h_role, ['RH', 'CISCO', 'ETAB'])): ?>
            <a class="bouton-haut" href="/dren/pages/demandes/nouvelle.php">+ Demande d'affectation</a>
        <?php endif; ?>
        <?php if ($h_role === 'ETAB'): ?>
            <a class="bouton-haut" href="/dren/pages/besoins/nouvelle.php">+ Demande de personnel</a>
        <?php endif; ?>
        <?php if (in_array($h_role, ['RH', 'CISCO', 'ETAB'])): ?>
            <a class="bouton-haut" href="/dren/pages/conges/nouvelle.php">+ Demande de congé</a>
        <?php endif; ?>

        <nav aria-label="Menu principal">
            <?php foreach ($h_menu as $h_m): if (!$h_m[3]) continue;
                $h_actif = $h_m[4] === 'exact' ? ($h_script === $h_m[0]) : (strpos($h_script, $h_m[4]) !== false);
                if ($h_actif && $h_m[6] !== '' && strpos($h_script, $h_m[6]) !== false) { $h_actif = false; } ?>
                <a href="<?= $h_m[0] ?>" class="<?= $h_actif ? 'actif' : '' ?>"
                   <?= $h_actif ? 'aria-current="page"' : '' ?>><?= dren_ico($h_m[2]) ?> <?= $h_m[1] ?>
                   <?php if ($h_m[5] > 0): ?><span class="nav-badge"><?= (int)$h_m[5] ?></span><?php endif; ?></a>
            <?php endforeach; ?>

            <?php if ($h_role === 'ADMIN'): ?>
                <span class="titre-nav">Administration</span>
                <?php foreach ($h_admin as $h_m): $h_actif = strpos($h_script, $h_m[3]) !== false; ?>
                    <a href="<?= $h_m[0] ?>" class="<?= $h_actif ? 'actif' : '' ?>"
                       <?= $h_actif ? 'aria-current="page"' : '' ?>><?= dren_ico($h_m[2]) ?> <?= $h_m[1] ?></a>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>

        <div class="resume">
            <?= $h_global ? 'Personnels de la région' : 'Personnels de votre périmètre' ?><br>
            <b><?= $h_poste ?></b> en poste sur <b><?= $h_total ?></b>
            <div class="barre"><span style="width:<?= $h_pct ?>%"></span></div>
        </div>
    </aside>
    <div class="voile" id="voile"></div>

    <div class="col">
        <div class="topbar">
            <button class="ico-btn burger" id="btn-menu" aria-label="Ouvrir le menu" aria-expanded="false">
                <?= dren_ico('menu') ?>
            </button>

            <form class="recherche" action="/dren/pages/agents/liste.php" method="get" role="search">
                <?= dren_ico('search') ?>
                <input type="search" name="q" id="recherche-globale" autocomplete="off"
                       placeholder="Rechercher un agent (nom, prénom, matricule)" aria-label="Rechercher un agent">
                <kbd>/</kbd>
            </form>

            <span class="espace"></span>

            <div class="dd">
                <button class="ico-btn" data-dd aria-expanded="false" aria-label="Notifications">
                    <?= dren_ico('bell') ?>
                    <?php if ($h_notifs > 0): ?><span class="pastille"><?= $h_notifs ?></span><?php endif; ?>
                </button>
                <div class="dd-menu" hidden>
                    <?php if ($h_dem > 0): ?>
                        <a href="/dren/pages/demandes/liste.php?statut=EN_ATTENTE"><?= $h_dem ?> demande(s) d'affectation à traiter</a>
                    <?php endif; ?>
                    <?php if ($h_cg > 0): ?>
                        <a href="/dren/pages/conges/liste.php?statut=EN_ATTENTE"><?= $h_cg ?> demande(s) de congé à traiter</a>
                    <?php endif; ?>
                    <?php if ($h_dp > 0): ?>
                        <a href="/dren/pages/besoins/liste.php?statut=EN_ATTENTE"><?= $h_dp ?> demande(s) de personnel à traiter</a>
                    <?php endif; ?>
                    <?php if ($h_postes > 0): ?>
                        <a href="/dren/pages/besoins/pourvoir.php"><?= $h_postes ?> poste(s) à pourvoir</a>
                    <?php endif; ?>
                    <?php if ($h_attente > 0): ?>
                        <a href="/dren/pages/statistiques.php"><?= $h_attente ?> agent(s) en attente d'affectation</a>
                    <?php endif; ?>
                    <?php if ($h_notifs === 0): ?>
                        <span class="ligne-menu">Aucune notification.</span>
                    <?php endif; ?>
                </div>
            </div>

            <button class="ico-btn" id="btn-theme" aria-label="Changer de thème (clair ou sombre)">
                <?= dren_ico('moon') ?>
            </button>

            <div class="dd">
                <button class="profil" data-dd aria-expanded="false" aria-label="Menu du compte de <?= htmlspecialchars($h_nom) ?>">
                    <span class="avatar"><?= htmlspecialchars($h_init) ?></span>
                    <span class="profil-txt">
                        <b><?= htmlspecialchars($h_nom) ?></b>
                        <small><?= htmlspecialchars($h_ligne) ?></small>
                    </span>
                </button>
                <div class="dd-menu" hidden>
                    <div class="id-bloc">
                        <b><?= htmlspecialchars($h_nom) ?></b>
                        <small><?= htmlspecialchars($h_titre_role) ?></small>
                        <small><?= htmlspecialchars($h_lieu) ?></small>
                    </div>
                    <a href="/dren/logout.php"><?= dren_ico('logout') ?> Déconnexion</a>
                </div>
            </div>
        </div>

        <main>
            <div class="page-titre">
                <h1><?= htmlspecialchars($titre_page ?? 'Accueil') ?></h1>
                <span class="date"><?= $h_date ?></span>
                <span class="qui" title="Compte connecté"><?= dren_ico('user') ?> <?= htmlspecialchars($h_nom) ?> · <?= htmlspecialchars($h_ligne) ?></span>
            </div>

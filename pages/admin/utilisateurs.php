<?php
require "../../config/database.php";
require "../../includes/auth.php";
exiger_role(['ADMIN']);

$titre_page = "Utilisateurs";
$erreurs = [];
$v = [];

// La colonne nom_complet existe-t-elle ?
$col_ok = $pdo->query("SHOW COLUMNS FROM utilisateur LIKE 'nom_complet'")->fetch() !== false;
if (!$col_ok) {
    require "../../includes/header.php";
    echo '<div class="erreur">La colonne « nom_complet » n\'existe pas encore. Dans phpMyAdmin, onglet <b>SQL</b> de la base '
       . '<b>dren_db</b>, exécutez : <code>ALTER TABLE utilisateur ADD COLUMN nom_complet VARCHAR(100) NULL;</code> '
       . 'puis rechargez cette page.</div>';
    require "../../includes/footer.php";
    exit;
}

$roles = $pdo->query("SELECT code_role, libelle FROM role ORDER BY libelle")->fetchAll();
$codes_roles = array_column($roles, 'code_role');

$ciscos = $pdo->query("SELECT code_cisco, nom FROM cisco ORDER BY nom")->fetchAll();
$etabs  = $pdo->query("SELECT code_etab, nom FROM etablissement ORDER BY nom")->fetchAll();
$codes_cisco = array_column($ciscos, 'code_cisco');
$codes_etab  = array_column($etabs, 'code_etab');

// Rôles qui n'ont qu'un seul titulaire actif dans tout le système
$roles_uniques = ['ADMIN' => 'administrateur', 'DIR' => 'directeur régional'];

// Au moins 8 caractères, avec au moins une lettre et un chiffre
function mot_de_passe_valide($m) {
    return strlen($m) >= 8 && preg_match('/[A-Za-z]/', $m) && preg_match('/[0-9]/', $m);
}
function nb_actifs_role(PDO $pdo, $role) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM utilisateur WHERE code_role = ? AND actif = 1");
    $st->execute([$role]);
    return (int)$st->fetchColumn();
}
function journaliser(PDO $pdo, $action, $details) {
    $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
        ->execute([$action, $details, $_SESSION['user']['id']]);
}
// Nom complet : espaces nettoyés, lettres, espaces, point, apostrophe et tiret seulement
function nom_nettoye($n) {
    return trim(preg_replace('/\s+/u', ' ', is_string($n) ? $n : ''));
}
function nom_valide($n) {
    return mb_strlen($n) >= 3 && mb_strlen($n) <= 100
        && preg_match('/^\p{L}[\p{L}\p{M} .\'’-]*$/u', $n);
}
$msg_nom = "Nom complet : 3 à 100 caractères (lettres, espaces, tiret, apostrophe ou point).";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifier_csrf($_POST['csrf'] ?? '')) {
        die("Requête invalide.");
    }
    $action = $_POST['action'] ?? '';

    try {
        // ---- Créer un compte ----
        if ($action === 'ajouter') {
            $v['login'] = strtolower(trim($_POST['login'] ?? ''));
            $v['nom_complet'] = nom_nettoye($_POST['nom_complet'] ?? '');
            $v['code_role'] = trim($_POST['code_role'] ?? '');
            $v['code_cisco'] = trim($_POST['code_cisco'] ?? '');
            $v['code_etab']  = trim($_POST['code_etab'] ?? '');
            $mdp  = $_POST['mdp'] ?? '';
            $mdp2 = $_POST['mdp2'] ?? '';

            if (!preg_match('/^[a-z0-9._-]{3,40}$/', $v['login'])) {
                $erreurs[] = "Identifiant : 3 à 40 caractères (lettres minuscules, chiffres, point, tiret ou underscore).";
            }
            if (!nom_valide($v['nom_complet'])) { $erreurs[] = $msg_nom; }
            if (!in_array($v['code_role'], $codes_roles)) {
                $erreurs[] = "Choisissez un rôle.";
            } elseif (isset($roles_uniques[$v['code_role']]) && nb_actifs_role($pdo, $v['code_role']) >= 1) {
                $erreurs[] = "Le système n'admet qu'un seul " . $roles_uniques[$v['code_role']] . " actif."
                           . ($v['code_role'] === 'DIR' ? " Désactivez d'abord le compte actuel." : "");
            }
            if ($v['code_role'] === 'CISCO' && !in_array($v['code_cisco'], $codes_cisco)) {
                $erreurs[] = "Un chef de CISCO doit être rattaché à une CISCO.";
            }
            if ($v['code_role'] === 'ETAB' && !in_array($v['code_etab'], $codes_etab)) {
                $erreurs[] = "Un chef d'établissement doit être rattaché à un établissement.";
            }
            if (!mot_de_passe_valide($mdp)) {
                $erreurs[] = "Mot de passe : 8 caractères minimum, avec au moins une lettre et un chiffre.";
            } elseif ($mdp !== $mdp2) {
                $erreurs[] = "Les deux mots de passe ne correspondent pas.";
            }
            if (!$erreurs) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateur WHERE login = ?");
                $stmt->execute([$v['login']]);
                if ($stmt->fetchColumn() > 0) { $erreurs[] = "Cet identifiant existe déjà."; }
            }
            if (!$erreurs) {
                $pdo->prepare("INSERT INTO utilisateur (login, nom_complet, mot_de_passe, code_role, code_cisco, code_etab)
                               VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $v['login'], $v['nom_complet'], password_hash($mdp, PASSWORD_DEFAULT), $v['code_role'],
                        $v['code_role'] === 'CISCO' ? $v['code_cisco'] : null,
                        $v['code_role'] === 'ETAB'  ? $v['code_etab']  : null,
                    ]);
                journaliser($pdo, 'USER_AJOUT', "Compte {$v['login']} créé (rôle {$v['code_role']})");
                header("Location: utilisateurs.php?ok=1");
                exit;
            }
        }

        // ---- Modifier le nom complet d'un compte ----
        if ($action === 'nom') {
            $id  = (int)($_POST['id_user'] ?? 0);
            $nom = nom_nettoye($_POST['nom_complet'] ?? '');
            $stmt = $pdo->prepare("SELECT login FROM utilisateur WHERE id_user = ?");
            $stmt->execute([$id]);
            $login = $stmt->fetchColumn();

            if (!$login) {
                $erreurs[] = "Utilisateur introuvable.";
            } elseif (!nom_valide($nom)) {
                $erreurs[] = $msg_nom;
            } else {
                $pdo->prepare("UPDATE utilisateur SET nom_complet = ? WHERE id_user = ?")->execute([$nom, $id]);
                journaliser($pdo, 'USER_NOM', "Nom du compte $login : $nom");
                header("Location: utilisateurs.php?ok=1");
                exit;
            }
        }

        // ---- Activer / désactiver un compte ----
        if ($action === 'basculer') {
            $id = (int)($_POST['id_user'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE id_user = ?");
            $stmt->execute([$id]);
            $u = $stmt->fetch();

            if (!$u) {
                $erreurs[] = "Utilisateur introuvable.";
            } elseif ($id === (int)$_SESSION['user']['id']) {
                $erreurs[] = "Vous ne pouvez pas désactiver votre propre compte.";
            } elseif ($u['actif'] && $u['code_role'] === 'ADMIN') {
                $erreurs[] = "Impossible : le système doit toujours avoir son administrateur actif.";
            } elseif (!$u['actif'] && isset($roles_uniques[$u['code_role']])
                      && nb_actifs_role($pdo, $u['code_role']) >= 1) {
                $erreurs[] = "Impossible de réactiver ce compte : un " . $roles_uniques[$u['code_role']]
                           . " est déjà actif. Désactivez-le d'abord.";
            } else {
                $nouveau = $u['actif'] ? 0 : 1;
                $pdo->prepare("UPDATE utilisateur SET actif = ? WHERE id_user = ?")->execute([$nouveau, $id]);
                journaliser($pdo, $nouveau ? 'USER_ACTIVE' : 'USER_DESACTIVE', "Compte {$u['login']}");
                header("Location: utilisateurs.php?ok=1");
                exit;
            }
        }

        // ---- Réinitialiser un mot de passe ----
        if ($action === 'reinit') {
            $id  = (int)($_POST['id_user'] ?? 0);
            $mdp = $_POST['mdp'] ?? '';
            $stmt = $pdo->prepare("SELECT login FROM utilisateur WHERE id_user = ?");
            $stmt->execute([$id]);
            $login = $stmt->fetchColumn();

            if (!$login) {
                $erreurs[] = "Utilisateur introuvable.";
            } elseif (!mot_de_passe_valide($mdp)) {
                $erreurs[] = "Mot de passe : 8 caractères minimum, avec au moins une lettre et un chiffre.";
            } else {
                $pdo->prepare("UPDATE utilisateur SET mot_de_passe = ? WHERE id_user = ?")
                    ->execute([password_hash($mdp, PASSWORD_DEFAULT), $id]);
                journaliser($pdo, 'USER_MDP', "Mot de passe de $login réinitialisé");
                header("Location: utilisateurs.php?ok=1");
                exit;
            }
        }
    } catch (PDOException $e) {
        $erreurs[] = "Erreur technique : l'opération n'a pas été enregistrée.";
    }
}

// Titulaires actuels des deux postes uniques
$titulaires = [];
foreach ($roles_uniques as $code => $lib) {
    $st = $pdo->prepare("SELECT login FROM utilisateur WHERE code_role = ? AND actif = 1 LIMIT 1");
    $st->execute([$code]);
    $titulaires[$code] = $st->fetchColumn() ?: null;
}

$liste = $pdo->query(
    "SELECT u.id_user, u.login, u.nom_complet, u.actif, u.code_role, r.libelle AS role,
            c.nom AS cisco, e.nom AS etab
     FROM utilisateur u
     JOIN role r ON r.code_role = u.code_role
     LEFT JOIN cisco c ON c.code_cisco = u.code_cisco
     LEFT JOIN etablissement e ON e.code_etab = u.code_etab
     ORDER BY u.login"
)->fetchAll();

require "../../includes/header.php";
?>
<div class="carte">
    <h2>Gestion des utilisateurs</h2>
    <?php if (isset($_GET['ok'])): ?>
        <div class="succes">L'opération a bien été enregistrée.</div>
    <?php endif; ?>
    <?php if ($erreurs): ?>
        <div class="erreur">
            <?php foreach ($erreurs as $e): ?>
                <?= htmlspecialchars($e) ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <p>
        <b>Administrateur :</b> <?= $titulaires['ADMIN'] ? htmlspecialchars($titulaires['ADMIN']) : 'poste vacant' ?>
        &nbsp;·&nbsp;
        <b>Directeur régional :</b> <?= $titulaires['DIR'] ? htmlspecialchars($titulaires['DIR']) : 'poste vacant' ?>
    </p>
    <small>Le système compte un seul administrateur et un seul directeur régional actifs.</small>
    <?php if (!$titulaires['DIR']): ?>
        <div class="alerte" style="margin-top:10px">Le poste de directeur régional est vacant : créez le compte du nouveau directeur.</div>
    <?php endif; ?>
</div>

<div class="carte tableau">
    <table>
        <tr><th>Identifiant</th><th>Nom complet</th><th>Rôle</th><th>Rattachement</th><th>État</th><th>Actions</th></tr>
        <?php foreach ($liste as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['login']) ?></td>
            <td>
                <form method="post" class="actions">
                    <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                    <input type="hidden" name="action" value="nom">
                    <input type="hidden" name="id_user" value="<?= (int)$u['id_user'] ?>">
                    <input type="text" name="nom_complet" maxlength="100" placeholder="Nom et prénom"
                           value="<?= htmlspecialchars($u['nom_complet'] ?? '') ?>"
                           aria-label="Nom complet du compte <?= htmlspecialchars($u['login']) ?>">
                    <button class="btn sec" type="submit">Enregistrer</button>
                </form>
            </td>
            <td><?= htmlspecialchars($u['role']) ?></td>
            <td><?= htmlspecialchars($u['cisco'] ?? $u['etab'] ?? '—') ?></td>
            <td><span class="badge <?= $u['actif'] ? '' : 'off' ?>"><?= $u['actif'] ? 'Actif' : 'Désactivé' ?></span></td>
            <td class="actions">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                    <input type="hidden" name="action" value="reinit">
                    <input type="hidden" name="id_user" value="<?= (int)$u['id_user'] ?>">
                    <input type="password" name="mdp" placeholder="Nouveau mot de passe" autocomplete="new-password">
                    <button class="btn sec" type="submit">Réinitialiser</button>
                </form>
                <?php if ($u['code_role'] !== 'ADMIN'): ?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                    <input type="hidden" name="action" value="basculer">
                    <input type="hidden" name="id_user" value="<?= (int)$u['id_user'] ?>">
                    <button class="btn <?= $u['actif'] ? 'danger' : '' ?>" type="submit">
                        <?= $u['actif'] ? 'Désactiver' : 'Activer' ?>
                    </button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="carte">
    <h3>Créer un compte</h3>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
        <input type="hidden" name="action" value="ajouter">

        <div class="champ">
            <label for="login">Identifiant *</label>
            <input type="text" id="login" name="login" maxlength="40" value="<?= htmlspecialchars($v['login'] ?? '') ?>">
        </div>
        <div class="champ">
            <label for="nom_complet">Nom complet * (affiché sur toutes les pages)</label>
            <input type="text" id="nom_complet" name="nom_complet" maxlength="100"
                   placeholder="Ex. Rakoto Jean" value="<?= htmlspecialchars($v['nom_complet'] ?? '') ?>">
        </div>
        <div class="champ">
            <label for="code_role">Rôle *</label>
            <select id="code_role" name="code_role">
                <option value="">-- Choisir --</option>
                <?php foreach ($roles as $r):
                    $pris = isset($roles_uniques[$r['code_role']]) && $titulaires[$r['code_role']] !== null; ?>
                    <option value="<?= htmlspecialchars($r['code_role']) ?>"
                        <?= $pris ? 'disabled' : '' ?>
                        <?= ($v['code_role'] ?? '') === $r['code_role'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['libelle']) ?><?= $pris ? ' (déjà attribué)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="code_cisco">CISCO (pour un chef de CISCO)</label>
            <select id="code_cisco" name="code_cisco">
                <option value="">-- Aucune --</option>
                <?php foreach ($ciscos as $c): ?>
                    <option value="<?= htmlspecialchars($c['code_cisco']) ?>"
                        <?= ($v['code_cisco'] ?? '') === $c['code_cisco'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="code_etab">Établissement (pour un chef d'établissement)</label>
            <select id="code_etab" name="code_etab">
                <option value="">-- Aucun --</option>
                <?php foreach ($etabs as $e): ?>
                    <option value="<?= htmlspecialchars($e['code_etab']) ?>"
                        <?= ($v['code_etab'] ?? '') === $e['code_etab'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="mdp">Mot de passe * (8 caractères minimum, lettres et chiffres)</label>
            <input type="password" id="mdp" name="mdp" autocomplete="new-password">
        </div>
        <div class="champ">
            <label for="mdp2">Confirmer le mot de passe *</label>
            <input type="password" id="mdp2" name="mdp2" autocomplete="new-password">
        </div>

        <button class="btn" type="submit">Créer le compte</button>
    </form>
</div>
<?php require "../../includes/footer.php"; ?>

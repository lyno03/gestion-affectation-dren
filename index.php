<?php
require "config/database.php";
require "includes/auth.php";
exiger_connexion();

$titre_page = "Accueil";
$role = $_SESSION['user']['role'];
$global = in_array($role, ['ADMIN', 'RH', 'DIR']);
[$perimetre, $p_params] = filtre_agent('a');

// Agents du périmètre
$stmt = $pdo->prepare("SELECT COUNT(*) FROM agent a WHERE $perimetre");
$stmt->execute($p_params);
$nb_agents = $stmt->fetchColumn();

// Établissements du périmètre
if ($global) {
    $nb_etabs = $pdo->query("SELECT COUNT(*) FROM etablissement")->fetchColumn();
} elseif ($role === 'CISCO') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM etablissement WHERE code_cisco = ?");
    $stmt->execute([$_SESSION['user']['code_cisco']]);
    $nb_etabs = $stmt->fetchColumn();
} else {
    $nb_etabs = 1;
}

// Agents en situation particulière aujourd'hui (congé...), dans le périmètre
$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT s.matricule) FROM situation s
     JOIN agent a ON a.matricule = s.matricule
     WHERE s.date_debut <= CURDATE() AND (s.date_fin IS NULL OR s.date_fin >= CURDATE())
       AND $perimetre"
);
$stmt->execute($p_params);
$nb_situation = $stmt->fetchColumn();

// Agents sans poste : visible seulement pour la direction (hors périmètre des autres rôles)
$nb_attente = 0;
if ($global) {
    $nb_attente = $pdo->query(
        "SELECT COUNT(*) FROM agent a
         WHERE NOT EXISTS (SELECT 1 FROM affectation af
                           WHERE af.matricule = a.matricule AND af.date_fin IS NULL)"
    )->fetchColumn();
}

$stmt = $pdo->prepare(
    "SELECT af.date_debut, af.motif, a.matricule, a.nom, a.prenoms, e.nom AS etablissement
     FROM affectation af
     JOIN agent a ON a.matricule = af.matricule
     JOIN etablissement e ON e.code_etab = af.code_etab
     WHERE $perimetre
     ORDER BY af.date_debut DESC, af.id_affectation DESC
     LIMIT 5"
);
$stmt->execute($p_params);
$derniers = $stmt->fetchAll();

function date_fr($d) { return $d ? date("d/m/Y", strtotime($d)) : "—"; }

require "includes/header.php";
?>
<div class="carte bandeau-accueil">
    <div class="bandeau-txt">
        <small>Ministère de l'Éducation Nationale</small>
        <small>Direction Régionale de l'Éducation Nationale · Atsimo Andrefana</small>
        <h2>Bienvenue, <?= htmlspecialchars($_SESSION['user']['login']) ?></h2>
        <p>Rôle : <?= htmlspecialchars($role) ?>
        <?php if (!$global): ?> · Vous voyez uniquement les agents de votre périmètre.<?php endif; ?></p>
    </div>
</div>

<?php if ($global && $nb_attente > 0): ?>
    <div class="alerte"><?= $nb_attente ?> agent(s) en attente d'affectation.</div>
<?php endif; ?>

<div class="stats">
    <div class="stat"><b><?= $nb_agents ?></b>Agents suivis</div>
    <div class="stat"><b><?= $nb_etabs ?></b>Établissements</div>
    <?php if ($global): ?>
    <div class="stat"><b><?= $nb_attente ?></b>En attente d'affectation</div>
    <?php endif; ?>
    <div class="stat"><b><?= $nb_situation ?></b>En situation particulière (congé...)</div>
</div>

<div class="carte tableau">
    <h3>Derniers mouvements</h3>
    <table>
        <tr><th>Date</th><th>Agent</th><th>Poste</th><th>Motif</th></tr>
        <?php foreach ($derniers as $d): ?>
        <tr>
            <td><?= date_fr($d['date_debut']) ?></td>
            <td><a href="/dren/pages/agents/fiche.php?m=<?= urlencode($d['matricule']) ?>">
                <?= htmlspecialchars($d['nom'] . ' ' . $d['prenoms']) ?></a></td>
            <td><?= htmlspecialchars($d['etablissement']) ?></td>
            <td><?= htmlspecialchars($d['motif']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$derniers): ?>
        <tr><td colspan="4">Aucun mouvement enregistré.</td></tr>
        <?php endif; ?>
    </table>
</div>
<?php require "includes/footer.php"; ?>
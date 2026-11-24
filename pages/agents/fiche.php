<?php
require "../../config/database.php";
require "../../includes/auth.php";
exiger_role(['ADMIN', 'RH', 'DIR', 'CISCO', 'ETAB']);

$matricule = $_GET['m'] ?? '';

// 1. L'agent
$stmt = $pdo->prepare(
    "SELECT a.*, g.libelle AS grade, f.libelle AS fonction
     FROM agent a
     LEFT JOIN grade g ON g.code_grade = a.code_grade
     LEFT JOIN fonction f ON f.code_fonction = a.code_fonction
     WHERE a.matricule = ?"
);
$stmt->execute([$matricule]);
$agent = $stmt->fetch();

if (!$agent) {
    http_response_code(404);
    die("Agent introuvable.");
}
if (!agent_visible($pdo, $matricule)) {
    http_response_code(403);
    die("Accès refusé : cet agent n'appartient pas à votre périmètre.");
}

// 2. Ses affectations, de la plus récente à la plus ancienne
$stmt = $pdo->prepare(
    "SELECT af.*, e.nom AS etablissement, e.type, c.nom AS cisco
     FROM affectation af
     JOIN etablissement e ON e.code_etab = af.code_etab
     JOIN cisco c ON c.code_cisco = e.code_cisco
     WHERE af.matricule = ?
     ORDER BY af.date_debut DESC, af.id_affectation DESC"
);
$stmt->execute([$matricule]);
$affectations = $stmt->fetchAll();

// 3. Ses situations
$stmt = $pdo->prepare("SELECT * FROM situation WHERE matricule = ? ORDER BY date_debut DESC");
$stmt->execute([$matricule]);
$situations = $stmt->fetchAll();

// 4. Poste actuel (affectation sans date de fin) et ancienneté
$actuelle = null;
foreach ($affectations as $af) {
    if ($af['date_fin'] === null) { $actuelle = $af; break; }
}

$anciennete = "—";
if ($agent['date_recrutement']) {
    $diff = (new DateTime($agent['date_recrutement']))->diff(new DateTime());
    $anciennete = $diff->y . " an(s) et " . $diff->m . " mois";
}

// CIN affiché par groupes de 3 chiffres
$cin_affiche = $agent['cin'] ? trim(chunk_split($agent['cin'], 3, ' ')) : '—';

function date_fr($d) { return $d ? date("d/m/Y", strtotime($d)) : "—"; }

$titre_page = "Fiche agent";
require "../../includes/header.php";
?>
<?php if (isset($_GET['ok'])): ?>
    <div class="succes">Les informations ont bien été enregistrées.</div>
<?php endif; ?>

<div class="carte">
    <h2><?= htmlspecialchars($agent['nom'] . ' ' . $agent['prenoms']) ?></h2>
    <div class="grille">
        <div><small>Matricule</small><?= htmlspecialchars($agent['matricule']) ?></div>
        <div><small>Grade</small><?= htmlspecialchars($agent['grade'] ?? '—') ?></div>
        <div><small>Fonction</small><?= htmlspecialchars($agent['fonction'] ?? '—') ?></div>
        <div><small>Statut</small><span class="badge"><?= htmlspecialchars($agent['statut']) ?></span></div>
        <div><small>Poste actuel</small>
            <?= $actuelle ? htmlspecialchars($actuelle['etablissement']) : 'Aucune affectation' ?></div>
        <div><small>Circonscription</small>
            <?= $actuelle ? htmlspecialchars($actuelle['cisco']) : '—' ?></div>
        <div><small>Date de recrutement</small><?= date_fr($agent['date_recrutement']) ?></div>
        <div><small>Ancienneté</small><?= $anciennete ?></div>
        <div><small>Date de naissance</small><?= date_fr($agent['date_naissance']) ?></div>
        <div><small>CIN</small><?= htmlspecialchars($cin_affiche) ?></div>
        <div><small>Téléphone</small><?= htmlspecialchars($agent['telephone'] ?? '—') ?></div>
    </div>
</div>

<div class="carte">
    <h3>Parcours des affectations</h3>
    <?php if ($affectations): ?>
    <ul class="parcours">
        <?php foreach ($affectations as $af): ?>
        <li class="<?= $af['date_fin'] === null ? 'actuel' : '' ?>">
            <b><?= htmlspecialchars($af['etablissement']) ?></b>
            (<?= htmlspecialchars($af['cisco']) ?>)<br>
            Du <?= date_fr($af['date_debut']) ?>
            au <?= $af['date_fin'] ? date_fr($af['date_fin']) : 'ce jour' ?><br>
            <small><?= htmlspecialchars($af['motif']) ?>
            <?= $af['reference_acte'] ? ' – ' . htmlspecialchars($af['reference_acte']) : '' ?></small>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
        <p>Aucune affectation enregistrée.</p>
    <?php endif; ?>
</div>

<div class="carte tableau">
    <h3>Situations administratives</h3>
    <?php if ($situations): ?>
    <table>
        <tr><th>Type</th><th>Début</th><th>Fin</th></tr>
        <?php foreach ($situations as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['type']) ?></td>
            <td><?= date_fr($s['date_debut']) ?></td>
            <td><?= $s['date_fin'] ? date_fr($s['date_fin']) : 'En cours' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php else: ?>
        <p>Aucune situation particulière.</p>
    <?php endif; ?>
</div>

<?php if (in_array($_SESSION['user']['role'], ['ADMIN', 'RH'])): ?>
    <a class="btn" href="modifier.php?m=<?= urlencode($agent['matricule']) ?>">Modifier</a>
    <a class="btn" href="situation.php?m=<?= urlencode($agent['matricule']) ?>">Situations</a>
    <a class="btn" href="/dren/pages/affectations/nouvelle.php?m=<?= urlencode($agent['matricule']) ?>">Affecter cet agent</a>
<?php endif; ?>
<a class="btn sec" href="liste.php">← Retour à la liste</a>
<?php require "../../includes/footer.php"; ?>
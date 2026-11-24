<?php
require "../../config/database.php";
require "../../includes/auth.php";
exiger_role(['ADMIN', 'RH', 'DIR', 'CISCO', 'ETAB']);

$titre_page = "Affectations";
$q = trim($_GET['q'] ?? '');
$like = "%$q%";
$global = in_array($_SESSION['user']['role'], ['ADMIN', 'RH', 'DIR']);
[$perimetre, $p_params] = filtre_agent('a');

$stmt = $pdo->prepare(
    "SELECT af.*, a.nom, a.prenoms, e.nom AS etablissement, c.nom AS cisco
     FROM affectation af
     JOIN agent a ON a.matricule = af.matricule
     JOIN etablissement e ON e.code_etab = af.code_etab
     JOIN cisco c ON c.code_cisco = e.code_cisco
     WHERE (a.nom LIKE ? OR a.prenoms LIKE ? OR a.matricule LIKE ? OR e.nom LIKE ?)
       AND $perimetre
     ORDER BY af.date_debut DESC, af.id_affectation DESC"
);
$stmt->execute(array_merge([$like, $like, $like, $like], $p_params));
$lignes = $stmt->fetchAll();

function date_fr($d) { return $d ? date("d/m/Y", strtotime($d)) : "—"; }

require "../../includes/header.php";
?>
<div class="carte">
    <h2>Historique des affectations</h2>
    <?php if (isset($_GET['ok'])): ?>
        <div class="succes">L'affectation a bien été enregistrée.</div>
    <?php endif; ?>
    <form method="get">
        <input type="text" name="q" placeholder="Agent, matricule ou établissement"
               value="<?= htmlspecialchars($q) ?>">
        <button class="btn" type="submit">Rechercher</button>
        <?php if (in_array($_SESSION['user']['role'], ['ADMIN', 'RH'])): ?>
            <a class="btn sec" href="nouvelle.php">+ Nouvelle affectation</a>
        <?php endif; ?>
    </form>
</div>

<div class="carte tableau">
    <table>
        <tr>
            <th>Date d'effet</th><th>Agent</th><th>Poste</th>
            <th>Motif</th><th>Référence</th><th>État</th>
            <?php if ($global): ?><th></th><?php endif; ?>
        </tr>
        <?php foreach ($lignes as $l): ?>
        <tr>
            <td><?= date_fr($l['date_debut']) ?></td>
            <td>
                <a href="/dren/pages/agents/fiche.php?m=<?= urlencode($l['matricule']) ?>">
                    <?= htmlspecialchars($l['nom'] . ' ' . $l['prenoms']) ?>
                </a>
            </td>
            <td><?= htmlspecialchars($l['etablissement']) ?><br><small><?= htmlspecialchars($l['cisco']) ?></small></td>
            <td><?= htmlspecialchars($l['motif']) ?></td>
            <td><?= htmlspecialchars($l['reference_acte'] ?? '—') ?></td>
            <td><span class="badge"><?= $l['date_fin'] ? 'Clôturée le ' . date_fr($l['date_fin']) : 'En cours' ?></span></td>
            <?php if ($global): ?>
            <td><a href="decision.php?id=<?= (int)$l['id_affectation'] ?>" target="_blank">Décision</a></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$lignes): ?>
        <tr><td colspan="<?= $global ? 7 : 6 ?>">Aucune affectation trouvée.</td></tr>
        <?php endif; ?>
    </table>
</div>
<?php require "../../includes/footer.php"; ?>
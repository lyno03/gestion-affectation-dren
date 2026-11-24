<?php
require "../config/database.php";
require "../includes/auth.php";
exiger_role(['ADMIN', 'RH', 'DIR']);

$titre_page = "Statistiques";

// Effectifs par CISCO (affectations actives uniquement)
$par_cisco = $pdo->query(
    "SELECT c.nom AS libelle, COUNT(af.id_affectation) AS nb
     FROM cisco c
     LEFT JOIN etablissement e ON e.code_cisco = c.code_cisco
     LEFT JOIN affectation af ON af.code_etab = e.code_etab AND af.date_fin IS NULL
     GROUP BY c.code_cisco, c.nom
     ORDER BY nb DESC, c.nom"
)->fetchAll();

// Effectifs par établissement (les établissements vides apparaissent avec 0)
$par_etab = $pdo->query(
    "SELECT e.nom AS libelle, COUNT(af.id_affectation) AS nb
     FROM etablissement e
     LEFT JOIN affectation af ON af.code_etab = e.code_etab AND af.date_fin IS NULL
     GROUP BY e.code_etab, e.nom
     ORDER BY nb DESC, e.nom"
)->fetchAll();

// Établissements sans aucun personnel
$vides = array_filter($par_etab, fn($l) => (int)$l['nb'] === 0);

// Répartition par grade
$par_grade = $pdo->query(
    "SELECT COALESCE(g.libelle, 'Non renseigné') AS libelle, COUNT(*) AS nb
     FROM agent a LEFT JOIN grade g ON g.code_grade = a.code_grade
     GROUP BY g.code_grade, g.libelle
     ORDER BY nb DESC"
)->fetchAll();

// Répartition par sexe
$par_sexe = $pdo->query(
    "SELECT CASE sexe WHEN 'M' THEN 'Hommes' WHEN 'F' THEN 'Femmes'
                      ELSE 'Non renseigné' END AS libelle, COUNT(*) AS nb
     FROM agent GROUP BY sexe ORDER BY nb DESC"
)->fetchAll();

// Agents en attente d'affectation
$attente = $pdo->query(
    "SELECT a.matricule, a.nom, a.prenoms
     FROM agent a
     WHERE NOT EXISTS (SELECT 1 FROM affectation af
                       WHERE af.matricule = a.matricule AND af.date_fin IS NULL)
     ORDER BY a.nom"
)->fetchAll();

// Mouvements par motif
$par_motif = $pdo->query(
    "SELECT motif AS libelle, COUNT(*) AS nb FROM affectation
     GROUP BY motif ORDER BY nb DESC"
)->fetchAll();

// Affiche une série de barres horizontales
function barres(array $lignes) {
    if (!$lignes) { echo '<p>Aucune donnée.</p>'; return; }
    $max = 1;
    foreach ($lignes as $l) { $max = max($max, (int)$l['nb']); }
    foreach ($lignes as $l) {
        $n = (int)$l['nb'];
        $pct = round($n / $max * 100);
        echo '<div class="ligne"><span>' . htmlspecialchars($l['libelle']) . '</span>'
           . '<i style="width:' . $pct . '%"></i><b>' . $n . '</b></div>';
    }
}

require "../includes/header.php";
?>
<div class="carte">
    <h2>Statistiques du personnel</h2>
    <p>Les effectifs comptent uniquement les affectations en cours.</p>
</div>

<?php if ($attente): ?>
<div class="carte tableau">
    <h3>Agents en attente d'affectation (<?= count($attente) ?>)</h3>
    <table>
        <tr><th>Matricule</th><th>Agent</th><th></th></tr>
        <?php foreach ($attente as $a): ?>
        <tr>
            <td><?= htmlspecialchars($a['matricule']) ?></td>
            <td><?= htmlspecialchars($a['nom'] . ' ' . $a['prenoms']) ?></td>
            <td>
                <?php if (in_array($_SESSION['user']['role'], ['ADMIN', 'RH'])): ?>
                <a href="/dren/pages/affectations/nouvelle.php?m=<?= urlencode($a['matricule']) ?>">Affecter</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<div class="deux">
    <div class="carte"><h3>Effectifs par CISCO</h3><?php barres($par_cisco); ?></div>
    <div class="carte"><h3>Effectifs par établissement</h3><?php barres($par_etab); ?></div>
    <div class="carte"><h3>Répartition par grade</h3><?php barres($par_grade); ?></div>
    <div class="carte"><h3>Répartition par sexe</h3><?php barres($par_sexe); ?></div>
    <div class="carte"><h3>Mouvements par motif</h3><?php barres($par_motif); ?></div>
    <div class="carte">
        <h3>Établissements sans personnel (<?= count($vides) ?>)</h3>
        <?php if ($vides): ?>
            <ul>
            <?php foreach ($vides as $v): ?>
                <li><?= htmlspecialchars($v['libelle']) ?></li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>Tous les établissements ont du personnel.</p>
        <?php endif; ?>
    </div>
</div>
<?php require "../includes/footer.php"; ?>
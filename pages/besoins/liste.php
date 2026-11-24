<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/besoins.php";
exiger_role(['ADMIN', 'RH', 'DIR', 'CISCO', 'ETAB']);

$titre_page = "Demandes de personnel";
$role = $_SESSION['user']['role'];
$est_dir = ($role === 'DIR');
$peut_envoyer = ($role === 'ETAB');
$peut_pourvoir = in_array($role, ['RH', 'ADMIN']);

$flash = bes_flash_lire();
$tables_ok = bes_tables_ok($pdo);

// Périmètre : un chef d'établissement voit ses demandes, un chef de CISCO celles de sa circonscription
$cond_scope = '';
$p_scope = [];
if ($role === 'ETAB') {
    $cond_scope = "d.code_etab = ?";
    $p_scope = [$_SESSION['user']['code_etab'] ?? ''];
} elseif ($role === 'CISCO') {
    $cond_scope = "e.code_cisco = ?";
    $p_scope = [$_SESSION['user']['code_cisco'] ?? ''];
}

// Onglet choisi (le directeur arrive sur « En attente »)
$statut = bes_str($_GET['statut'] ?? null);
if ($statut !== '' && !isset(BESOIN_STATUTS[$statut])) { $statut = ''; }
if (!isset($_GET['statut']) && $est_dir) { $statut = 'EN_ATTENTE'; }

$demandes = [];
$compteurs = ['EN_ATTENTE' => 0, 'ACCEPTEE' => 0, 'REFUSEE' => 0];

if ($tables_ok) {
    $st = $pdo->prepare(
        "SELECT d.statut, COUNT(*) FROM demande_personnel d
         JOIN etablissement e ON e.code_etab = d.code_etab"
        . ($cond_scope ? " WHERE $cond_scope" : "") . " GROUP BY d.statut"
    );
    $st->execute($p_scope);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $code => $nb) {
        if (isset($compteurs[$code])) { $compteurs[$code] = (int)$nb; }
    }

    $where = $cond_scope ? [$cond_scope] : [];
    $params = $p_scope;
    if ($statut !== '') { $where[] = "d.statut = ?"; $params[] = $statut; }

    $st = $pdo->prepare(
        "SELECT d.*, e.nom AS etab, c.nom AS cisco, fo.libelle AS fonction, fo.categorie,
                u.login AS demandeur, ud.login AS decideur,
                (SELECT COUNT(*) FROM affectation af
                 WHERE af.code_etab = d.code_etab AND af.date_fin IS NULL) AS effectif,
                (SELECT COUNT(*) FROM affectation af JOIN agent a ON a.matricule = af.matricule
                 WHERE af.code_etab = d.code_etab AND af.date_fin IS NULL
                   AND a.code_fonction = d.code_fonction) AS effectif_fonction,
                (SELECT COUNT(*) FROM poste_a_pourvoir p
                 WHERE p.id_dp = d.id_dp AND p.statut = 'POURVU') AS pourvus,
                (SELECT GROUP_CONCAT(TRIM(CONCAT(a2.nom, ' ', COALESCE(a2.prenoms, ''))) ORDER BY a2.nom SEPARATOR ', ')
                 FROM poste_a_pourvoir p JOIN agent a2 ON a2.matricule = p.matricule
                 WHERE p.id_dp = d.id_dp AND p.statut = 'POURVU') AS affectes
         FROM demande_personnel d
         JOIN etablissement e ON e.code_etab = d.code_etab
         JOIN cisco c ON c.code_cisco = e.code_cisco
         JOIN fonction fo ON fo.code_fonction = d.code_fonction
         JOIN utilisateur u ON u.id_user = d.id_demandeur
         LEFT JOIN utilisateur ud ON ud.id_user = d.id_decideur"
         . ($where ? " WHERE " . implode(" AND ", $where) : "")
         . " ORDER BY (d.statut = 'EN_ATTENTE') DESC, (d.urgence = 'URGENTE') DESC, d.date_demande DESC LIMIT 100"
    );
    $st->execute($params);
    $demandes = $st->fetchAll();
}
$total = array_sum($compteurs);

require "../../includes/header.php";
?>
<p class="sous-titre">
    <?= $est_dir ? "Examinez les besoins en personnel des établissements, puis acceptez-les ou refusez-les."
                 : "Suivez les demandes de personnel, la décision du directeur régional et les affectations réalisées." ?>
</p>

<?php if ($flash): ?>
    <div class="<?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
<?php endif; ?>
<?php if (!$tables_ok): ?>
    <div class="erreur">
        Le module n'est pas complètement installé. Dans phpMyAdmin, onglet <b>SQL</b> de la base <b>dren_db</b>,
        exécutez les scripts <b>demande_personnel.sql</b> puis <b>poste_a_pourvoir.sql</b>, et rechargez cette page.
    </div>
<?php endif; ?>

<div class="carte">
    <div class="entete-liste">
        <div class="onglets">
            <a href="?statut=EN_ATTENTE" class="<?= $statut === 'EN_ATTENTE' ? 'sel' : '' ?>">En attente <b><?= $compteurs['EN_ATTENTE'] ?></b></a>
            <a href="?statut=ACCEPTEE" class="<?= $statut === 'ACCEPTEE' ? 'sel' : '' ?>">Acceptées <b><?= $compteurs['ACCEPTEE'] ?></b></a>
            <a href="?statut=REFUSEE" class="<?= $statut === 'REFUSEE' ? 'sel' : '' ?>">Refusées <b><?= $compteurs['REFUSEE'] ?></b></a>
            <a href="?statut=" class="<?= $statut === '' ? 'sel' : '' ?>">Toutes <b><?= $total ?></b></a>
        </div>
        <?php if ($peut_envoyer): ?>
            <a class="btn" href="nouvelle.php"><?= dren_ico('plus') ?> Nouvelle demande de personnel</a>
        <?php endif; ?>
    </div>
</div>

<div class="carte tableau">
    <table>
        <tr>
            <th>N°</th><th>Date</th><th>Établissement</th><th>Personnel demandé</th>
            <th>Urgence</th><th>Justification</th><th>Statut</th><th><?= $est_dir ? 'Décision' : 'Détail' ?></th>
        </tr>
        <?php foreach ($demandes as $d): ?>
        <tr>
            <td><?= htmlspecialchars($d['numero']) ?></td>
            <td><?= date('d/m/Y', strtotime($d['date_demande'])) ?></td>
            <td><?= htmlspecialchars($d['etab']) ?><br><small><?= htmlspecialchars($d['cisco']) ?></small></td>
            <td>
                <b><?= (int)$d['nombre'] ?> × <?= htmlspecialchars($d['fonction']) ?></b>
                <span class="badge"><?= htmlspecialchars(BESOIN_CATEGORIES[$d['categorie']] ?? $d['categorie']) ?></span>
                <?php if ($d['precision_poste']): ?><br><small><?= htmlspecialchars($d['precision_poste']) ?></small><?php endif; ?>
                <br><small>Effectif actuel : <?= (int)$d['effectif'] ?> (dont <?= (int)$d['effectif_fonction'] ?> même fonction)</small>
            </td>
            <td><span class="urg <?= $d['urgence'] === 'URGENTE' ? 'urgente' : '' ?>"><?= htmlspecialchars(BESOIN_URGENCES[$d['urgence']] ?? $d['urgence']) ?></span></td>
            <td><small><?= htmlspecialchars($d['justification']) ?></small><br><small>Par <?= htmlspecialchars($d['demandeur']) ?></small></td>
            <td><span class="dem <?= BESOIN_STATUTS[$d['statut']][1] ?>"><?= BESOIN_STATUTS[$d['statut']][0] ?></span></td>
            <td>
                <?php if ($d['statut'] === 'EN_ATTENTE'): ?>
                    <?php if ($est_dir): ?>
                        <details class="action">
                            <summary class="btn">Accepter</summary>
                            <form method="post" action="traiter.php" class="panneau">
                                <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                                <input type="hidden" name="id_dp" value="<?= (int)$d['id_dp'] ?>">
                                <input type="hidden" name="decision" value="accepter">
                                <div class="champ">
                                    <label>Nombre accordé (1 à <?= (int)$d['nombre'] ?>)</label>
                                    <input type="number" name="nombre_accorde" min="1" max="<?= (int)$d['nombre'] ?>"
                                           value="<?= (int)$d['nombre'] ?>" required>
                                </div>
                                <div class="champ">
                                    <label>Observation (facultatif)</label>
                                    <textarea name="observation" rows="2" maxlength="255"></textarea>
                                </div>
                                <small>L'acceptation crée les postes à pourvoir et les transmet au responsable RH.</small><br>
                                <button class="btn" type="submit">Confirmer l'acceptation</button>
                            </form>
                        </details>
                        <details class="action">
                            <summary class="btn danger">Refuser</summary>
                            <form method="post" action="traiter.php" class="panneau">
                                <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                                <input type="hidden" name="id_dp" value="<?= (int)$d['id_dp'] ?>">
                                <input type="hidden" name="decision" value="refuser">
                                <div class="champ">
                                    <label>Motif du refus (obligatoire)</label>
                                    <textarea name="observation" rows="3" minlength="5" maxlength="255" required></textarea>
                                </div>
                                <button class="btn danger" type="submit">Confirmer le refus</button>
                            </form>
                        </details>
                    <?php else: ?>
                        <small>En attente de décision du directeur régional</small>
                    <?php endif; ?>

                <?php elseif ($d['statut'] === 'ACCEPTEE'): ?>
                    <small><b><?= (int)$d['nombre_accorde'] ?> accordé(s)</b> sur <?= (int)$d['nombre'] ?>
                        le <?= date('d/m/Y', strtotime($d['date_decision'])) ?> par <?= htmlspecialchars($d['decideur'] ?? '—') ?>
                        <?= $d['observation'] ? '<br>' . htmlspecialchars($d['observation']) : '' ?></small>
                    <br>
                    <span class="badge"><?= (int)$d['pourvus'] ?> / <?= (int)$d['nombre_accorde'] ?> pourvu(s)</span>
                    <?php if ($d['affectes']): ?><br><small>Affecté(s) : <?= htmlspecialchars($d['affectes']) ?></small><?php endif; ?>
                    <?php if ((int)$d['pourvus'] < (int)$d['nombre_accorde'] && in_array($role, ['RH', 'ADMIN', 'DIR'])): ?>
                        <br><a href="pourvoir.php">Voir les postes à pourvoir</a>
                    <?php endif; ?>

                <?php else: ?>
                    <small>Refusée le <?= date('d/m/Y', strtotime($d['date_decision'])) ?> par <?= htmlspecialchars($d['decideur'] ?? '—') ?>
                        <br><b>Motif :</b> <?= htmlspecialchars($d['observation'] ?? '') ?></small>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$demandes): ?>
        <tr><td colspan="8">Aucune demande dans cette catégorie.</td></tr>
        <?php endif; ?>
    </table>
</div>
<?php require "../../includes/footer.php"; ?>

<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/conges.php";
exiger_role(['ADMIN', 'DIR', 'RH', 'CISCO', 'ETAB']);

$titre_page = "Suivi des congés";
$role = $_SESSION['user']['role'];
$aujourdhui = date('Y-m-d');
$voit_fiches = in_array($role, ['ADMIN', 'RH', 'DIR']);
$voit_tout_comptes = in_array($role, ['ADMIN', 'RH', 'DIR']);   // CISCO / ETAB : leurs propres congés seulement

$libelles_roles = ['ADMIN' => 'Administrateur', 'RH' => 'Responsable RH', 'DIR' => 'Directeur régional',
                   'CISCO' => 'Chef de CISCO', 'ETAB' => "Chef d'établissement"];

$lignes = [];

// 1. Congés des agents (enregistrés dans leur situation), limités au périmètre de l'utilisateur
[$per, $pp] = filtre_agent('a');
$st = $pdo->prepare(
    "SELECT s.type, s.date_debut, s.date_fin, a.matricule, a.nom, a.prenoms
     FROM situation s JOIN agent a ON a.matricule = s.matricule
     WHERE s.type LIKE 'Congé%' AND $per
     ORDER BY s.date_debut DESC LIMIT 1000"
);
$st->execute($pp);
foreach ($st->fetchAll() as $r) {
    $lignes[] = [
        'nom'     => trim($r['nom'] . ' ' . ($r['prenoms'] ?? '')),
        'detail'  => $r['matricule'],
        'matr'    => $r['matricule'],
        'origine' => 'Agent',
        'type'    => $r['type'],
        'debut'   => $r['date_debut'],
        'fin'     => $r['date_fin'],
    ];
}

// 2. Congés acceptés du personnel qui se connecte (demandes de congé)
try {
    $sql = "SELECT c.type_conge, c.date_debut, c.date_fin, u.login, u.nom_complet, u.code_role
            FROM demande_conge c JOIN utilisateur u ON u.id_user = c.id_user
            WHERE c.statut = 'ACCEPTEE'";
    $par = [];
    if (!$voit_tout_comptes) { $sql .= " AND c.id_user = ?"; $par[] = $_SESSION['user']['id']; }
    $st = $pdo->prepare($sql . " ORDER BY c.date_debut DESC LIMIT 1000");
    $st->execute($par);
    foreach ($st->fetchAll() as $r) {
        $lignes[] = [
            'nom'     => trim((string)$r['nom_complet']) !== '' ? $r['nom_complet'] : $r['login'],
            'detail'  => $libelles_roles[$r['code_role']] ?? $r['code_role'],
            'matr'    => null,
            'origine' => 'Responsable',
            'type'    => $r['type_conge'],
            'debut'   => $r['date_debut'],
            'fin'     => $r['date_fin'],
        ];
    }
} catch (PDOException $ex) { /* la table demande_conge n'existe pas encore */ }

// État de chaque congé : en cours, à venir ou terminé
foreach ($lignes as &$l) {
    if ($l['debut'] > $aujourdhui)                          { $l['etat'] = 'avenir'; }
    elseif ($l['fin'] === null || $l['fin'] >= $aujourdhui) { $l['etat'] = 'cours'; }
    else                                                    { $l['etat'] = 'termine'; }
}
unset($l);

// Filtres : onglet, type, recherche
$etat = dem_str($_GET['etat'] ?? null);
if (!in_array($etat, ['cours', 'avenir', 'termine', 'tous'], true)) { $etat = 'cours'; }
$type = dem_str($_GET['type'] ?? null);
$q    = mb_strtolower(dem_str($_GET['q'] ?? null));

$types_dispo = array_keys(CONGE_TYPES);
if ($type !== '' && !in_array($type, $types_dispo, true)) { $type = ''; }

// Compteurs (tiennent compte du type et de la recherche, pas de l'onglet)
$compteurs = ['cours' => 0, 'avenir' => 0, 'termine' => 0];
$affiches = [];
foreach ($lignes as $l) {
    if ($type !== '' && $l['type'] !== $type) { continue; }
    if ($q !== '' && mb_strpos(mb_strtolower($l['nom'] . ' ' . $l['detail']), $q) === false) { continue; }
    $compteurs[$l['etat']]++;
    if ($etat === 'tous' || $l['etat'] === $etat) { $affiches[] = $l; }
}
// Tri : en cours = fin la plus proche d'abord ; à venir = début le plus proche ; terminés = les plus récents
usort($affiches, function ($a, $b) use ($etat) {
    $ordre = ['cours' => 0, 'avenir' => 1, 'termine' => 2];
    if ($a['etat'] !== $b['etat']) { return $ordre[$a['etat']] <=> $ordre[$b['etat']]; }
    if ($a['etat'] === 'cours')  { return strcmp($a['fin'] ?? '9999-12-31', $b['fin'] ?? '9999-12-31'); }
    if ($a['etat'] === 'avenir') { return strcmp($a['debut'], $b['debut']); }
    return strcmp($b['fin'], $a['fin']);
});
$total = array_sum($compteurs);

function date_fr($d) { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
function lien($e, $type, $q) { return '?' . http_build_query(['etat' => $e, 'type' => $type, 'q' => $q]); }
$ETATS = ['cours' => ['En cours', 'conge'], 'avenir' => ['À venir', 'autre'], 'termine' => ['Terminé', 'retraite']];

require "../../includes/header.php";
?>
<p class="sous-titre">
    <?= $voit_tout_comptes ? "Tous les congés des agents et des responsables : en cours, à venir et terminés."
                           : "Les congés des agents de votre périmètre et vos propres congés acceptés." ?>
</p>

<div class="carte">
    <div class="entete-liste">
        <div class="onglets">
            <a href="<?= lien('cours', $type, $q) ?>" class="<?= $etat === 'cours' ? 'sel' : '' ?>">En cours <b><?= $compteurs['cours'] ?></b></a>
            <a href="<?= lien('avenir', $type, $q) ?>" class="<?= $etat === 'avenir' ? 'sel' : '' ?>">À venir <b><?= $compteurs['avenir'] ?></b></a>
            <a href="<?= lien('termine', $type, $q) ?>" class="<?= $etat === 'termine' ? 'sel' : '' ?>">Terminés <b><?= $compteurs['termine'] ?></b></a>
            <a href="<?= lien('tous', $type, $q) ?>" class="<?= $etat === 'tous' ? 'sel' : '' ?>">Tous <b><?= $total ?></b></a>
        </div>
    </div>

    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;align-items:center">
        <input type="hidden" name="etat" value="<?= htmlspecialchars($etat) ?>">
        <input type="search" name="q" value="<?= htmlspecialchars(dem_str($_GET['q'] ?? null)) ?>"
               placeholder="Rechercher un nom ou un matricule" style="flex:1;min-width:200px">
        <select name="type">
            <option value="">Tous les types</option>
            <?php foreach ($types_dispo as $t): ?>
                <option <?= $type === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Filtrer</button>
        <?php if ($type !== '' || $q !== ''): ?><a class="btn sec" href="?etat=<?= urlencode($etat) ?>">Effacer</a><?php endif; ?>
    </form>
</div>

<div class="carte tableau">
    <table>
        <tr>
            <th>Nom</th><th>Congé</th><th>Début</th><th>Fin</th><th>Durée</th><th>État</th>
        </tr>
        <?php foreach ($affiches as $l):
            $duree = $l['fin'] ? conge_nb_jours($l['debut'], $l['fin']) . ' jour(s)' : '—';
            $reste = ($l['etat'] === 'cours' && $l['fin'])
                   ? (int)(new DateTime($aujourdhui))->diff(new DateTime($l['fin']))->days : null;
        ?>
        <tr>
            <td>
                <?php if ($voit_fiches && $l['matr']): ?>
                    <a href="/dren/pages/agents/fiche.php?m=<?= urlencode($l['matr']) ?>"><?= htmlspecialchars($l['nom']) ?></a>
                <?php else: ?>
                    <?= htmlspecialchars($l['nom']) ?>
                <?php endif; ?>
                <br><small><?= htmlspecialchars($l['detail']) ?></small>
            </td>
            <td><?= htmlspecialchars($l['type']) ?></td>
            <td><?= date_fr($l['debut']) ?></td>
            <td><?= $l['fin'] ? date_fr($l['fin']) : '<small>Sans date de fin</small>' ?></td>
            <td><?= $duree ?></td>
            <td>
                <span class="statut <?= $ETATS[$l['etat']][1] ?>"><?= $ETATS[$l['etat']][0] ?></span>
                <?php if ($reste !== null): ?><br><small><?= $reste === 0 ? "Se termine aujourd'hui" : "Reste $reste jour(s)" ?></small><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$affiches): ?>
        <tr><td colspan="6">Aucun congé dans cette catégorie.</td></tr>
        <?php endif; ?>
    </table>
</div>
<?php require "../../includes/footer.php"; ?>

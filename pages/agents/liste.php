<?php
require "../../config/database.php";
require "../../includes/auth.php";
exiger_role(['ADMIN', 'RH', 'DIR', 'CISCO', 'ETAB']);

$titre_page = "Personnels";
$q = trim($_GET['q'] ?? '');
[$perimetre, $p_params] = filtre_agent('a');

$sql = "SELECT a.matricule, a.nom, a.prenoms, a.statut,
               g.libelle AS grade, f.libelle AS fonction, e.nom AS etablissement
        FROM agent a
        LEFT JOIN grade g ON g.code_grade = a.code_grade
        LEFT JOIN fonction f ON f.code_fonction = a.code_fonction
        LEFT JOIN affectation af ON af.matricule = a.matricule AND af.date_fin IS NULL
        LEFT JOIN etablissement e ON e.code_etab = af.code_etab
        WHERE (a.nom LIKE ? OR a.prenoms LIKE ? OR a.matricule LIKE ?)
          AND $perimetre
        ORDER BY a.nom";
$like = "%$q%";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$like, $like, $like], $p_params));
$agents = $stmt->fetchAll();

require "../../includes/header.php";
?>
<div class="carte">
    <h2>Liste des personnels</h2>
    <?php if (isset($_GET['ok'])): ?>
        <div class="succes">L'agent a bien été enregistré.</div>
    <?php endif; ?>
    <form method="get">
        <input type="text" name="q" placeholder="Nom, prénom ou matricule"
               value="<?= htmlspecialchars($q) ?>">
        <button class="btn" type="submit">Rechercher</button>
        <?php if (in_array($_SESSION['user']['role'], ['ADMIN', 'RH'])): ?>
            <a class="btn sec" href="ajout.php">+ Ajouter un agent</a>
        <?php endif; ?>
    </form>
</div>

<div class="carte tableau">
    <table>
        <tr>
            <th>Matricule</th><th>Nom et prénoms</th><th>Grade</th>
            <th>Poste actuel</th><th>Statut</th>
        </tr>
        <?php foreach ($agents as $a): ?>
        <tr>
            <td><?= htmlspecialchars($a['matricule']) ?></td>
            <td>
                <a href="fiche.php?m=<?= urlencode($a['matricule']) ?>">
                    <?= htmlspecialchars($a['nom'] . ' ' . $a['prenoms']) ?>
                </a>
            </td>
            <td><?= htmlspecialchars($a['grade'] ?? '—') ?></td>
            <td><?= htmlspecialchars($a['etablissement'] ?? 'Aucune affectation') ?></td>
            <td><?= htmlspecialchars($a['statut']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$agents): ?>
        <tr><td colspan="5">Aucun agent trouvé.</td></tr>
        <?php endif; ?>
    </table>
</div>
<?php require "../../includes/footer.php"; ?><?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/agents.php";
exiger_role(['ADMIN', 'RH', 'DIR', 'CISCO', 'ETAB']);

$titre_page = "Personnels";
$role = $_SESSION['user']['role'];
$peut_ecrire   = in_array($role, ['ADMIN', 'RH']);
$peut_exporter = in_array($role, ['ADMIN', 'RH', 'DIR']);
$est_admin     = ($role === 'ADMIN');
$par_page = 10;

$f = agents_filtres($_GET);

// Tri (liste blanche : jamais de nom de colonne venant de l'adresse)
$tris = ['nom' => 'a.nom', 'matricule' => 'a.matricule', 'fonction' => 'f.libelle',
         'etab' => 'e.nom', 'statut' => 'a.statut'];
$tri = ag_str($_GET['tri'] ?? null);
if (!isset($tris[$tri])) { $tri = 'nom'; }
$ordre = (($_GET['ordre'] ?? '') === 'desc') ? 'desc' : 'asc';

// ----- Liste paginée -----
[$from, $params] = agents_from_where($f);

$st = $pdo->prepare("SELECT COUNT(*) $from");
$st->execute($params);
$total = (int)$st->fetchColumn();
$nb_pages = max(1, (int)ceil($total / $par_page));
$page = min(max(1, (int)ag_str($_GET['p'] ?? null)), $nb_pages);
$offset = ($page - 1) * $par_page;

$st = $pdo->prepare(
    "SELECT a.matricule, a.nom, a.prenoms, a.statut, a.telephone,
            f.libelle AS fonction, e.nom AS etablissement
     $from
     ORDER BY {$tris[$tri]} $ordre, a.nom, a.prenoms
     LIMIT " . (int)$par_page . " OFFSET " . (int)$offset
);
$st->execute($params);
$agents = $st->fetchAll();

// ----- Chiffres clés du périmètre -----
[$per, $pp] = function_exists('filtre_agent') ? filtre_agent('a') : ['1=1', []];
$st = $pdo->prepare(
    "SELECT COUNT(*) AS total, SUM(a.sexe = 'M') AS h, SUM(a.sexe = 'F') AS fe,
            SUM(a.statut = 'En poste') AS p, SUM(a.date_recrutement BETWEEN ? AND ?) AS m
     FROM agent a WHERE $per"
);
$st->execute(array_merge([date('Y-m-01'), date('Y-m-t')], $pp));
$k = $st->fetch();
$k_total = (int)$k['total'];
$k_h = (int)($k['h'] ?? 0);
$k_f = (int)($k['fe'] ?? 0);
$k_p = (int)($k['p'] ?? 0);
$k_m = (int)($k['m'] ?? 0);
function pourcent($n, $t) { return $t > 0 ? round($n / $t * 100) : 0; }

// ----- Listes des filtres -----
$fonctions = $pdo->query("SELECT code_fonction, libelle FROM fonction ORDER BY libelle")->fetchAll();
$statuts   = $pdo->query("SELECT DISTINCT statut FROM agent ORDER BY statut")->fetchAll(PDO::FETCH_COLUMN);
if ($role === 'CISCO') {
    $st = $pdo->prepare("SELECT code_etab, nom FROM etablissement WHERE code_cisco = ? ORDER BY nom");
    $st->execute([$_SESSION['user']['code_cisco'] ?? '']);
    $etabs = $st->fetchAll();
} elseif ($role === 'ETAB') {
    $st = $pdo->prepare("SELECT code_etab, nom FROM etablissement WHERE code_etab = ?");
    $st->execute([$_SESSION['user']['code_etab'] ?? '']);
    $etabs = $st->fetchAll();
} else {
    $etabs = $pdo->query("SELECT code_etab, nom FROM etablissement ORDER BY nom")->fetchAll();
}

// ----- Messages -----
$messages = [
    'suppr'   => ['succes', 'Le personnel a bien été supprimé.'],
    'histo'   => ['erreur', "Suppression impossible : ce personnel a un historique d'affectations ou de situations, conservé pour la traçabilité."],
    'inconnu' => ['erreur', 'Personnel introuvable.'],
    'erreur'  => ['erreur', "Erreur technique : la suppression n'a pas été effectuée."],
];
$m = ag_str($_GET['m'] ?? null);

// ----- Adresses (filtres + tri conservés) -----
$base = array_filter([
    'q' => $f['q'], 'fonction' => $f['fonction'], 'statut' => $f['statut'], 'etab' => $f['etab'],
    'tri' => $tri, 'ordre' => $ordre,
], function ($v) { return $v !== ''; });
function url_liste(array $base, array $extra = []) {
    return '?' . http_build_query(array_merge($base, $extra));
}
function th_tri($cle, $libelle, $tri, $ordre, array $base) {
    $actif = ($tri === $cle);
    $suivant = ($actif && $ordre === 'asc') ? 'desc' : 'asc';
    $aria = $actif ? ' aria-sort="' . ($ordre === 'asc' ? 'ascending' : 'descending') . '"' : '';
    return '<th class="tri"' . $aria . '><a class="th-tri" href="'
         . htmlspecialchars(url_liste($base, ['tri' => $cle, 'ordre' => $suivant, 'p' => 1]))
         . '">' . $libelle . '</a></th>';
}
$filtres_export = http_build_query(array_filter([
    'q' => $f['q'], 'fonction' => $f['fonction'], 'statut' => $f['statut'], 'etab' => $f['etab'],
], function ($v) { return $v !== ''; }));

$debut = $total > 0 ? $offset + 1 : 0;
$fin   = min($offset + $par_page, $total);

// Pages à afficher : 1, voisines de la page courante, dernière
$pages = [1];
for ($i = $page - 1; $i <= $page + 1; $i++) { if ($i > 1 && $i < $nb_pages) { $pages[] = $i; } }
if ($nb_pages > 1) { $pages[] = $nb_pages; }
$pages = array_values(array_unique($pages));
sort($pages);

require "../../includes/header.php";
?>
<p class="sous-titre">Consultez et gérez la liste des personnels<?= $role === 'CISCO' || $role === 'ETAB' ? ' de votre périmètre' : ' de la DREN' ?>.</p>

<?php if (isset($_GET['ok'])): ?>
    <div class="succes">Le personnel a bien été enregistré.</div>
<?php endif; ?>
<?php if (isset($messages[$m])): ?>
    <div class="<?= $messages[$m][0] ?>"><?= htmlspecialchars($messages[$m][1]) ?></div>
<?php endif; ?>

<!-- ===== Recherche et filtres ===== -->
<div class="carte">
    <form method="get" id="filtres" class="filtres-grille">
        <input type="hidden" name="tri" value="<?= htmlspecialchars($tri) ?>">
        <input type="hidden" name="ordre" value="<?= htmlspecialchars($ordre) ?>">
        <div class="champ">
            <label for="q">Recherche</label>
            <div class="rech-in">
                <?= dren_ico('search') ?>
                <input type="search" id="q" name="q" value="<?= htmlspecialchars($f['q']) ?>"
                       placeholder="Nom, prénom, matricule…" autocomplete="off">
            </div>
        </div>
        <div class="champ">
            <label for="fonction">Fonction</label>
            <select id="fonction" name="fonction">
                <option value="">Toutes les fonctions</option>
                <?php foreach ($fonctions as $fo): ?>
                    <option value="<?= htmlspecialchars($fo['code_fonction']) ?>" <?= $f['fonction'] === $fo['code_fonction'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($fo['libelle']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="statut">Statut</label>
            <select id="statut" name="statut">
                <option value="">Tous les statuts</option>
                <?php foreach ($statuts as $s): ?>
                    <option <?= $f['statut'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="etab">Établissement</label>
            <select id="etab" name="etab">
                <option value="">Tous les établissements</option>
                <?php foreach ($etabs as $et): ?>
                    <option value="<?= htmlspecialchars($et['code_etab']) ?>" <?= $f['etab'] === $et['code_etab'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($et['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <?php if ($peut_ecrire): ?>
                <a class="btn" href="ajout.php"><?= dren_ico('plus') ?> Ajouter un personnel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ===== Chiffres clés ===== -->
<div class="kpis">
    <div class="kpi bleu">
        <span class="kpi-ico"><?= dren_ico('users') ?></span>
        <div><span class="t">Total des personnels</span><b><?= $k_total ?></b>
            <small><?= $k_m ?> recruté(s) ce mois</small></div>
    </div>
    <div class="kpi vert">
        <span class="kpi-ico"><?= dren_ico('user') ?></span>
        <div><span class="t">Personnel homme</span><b><?= $k_h ?></b>
            <small><?= pourcent($k_h, $k_total) ?> % de l'effectif</small></div>
    </div>
    <div class="kpi violet">
        <span class="kpi-ico"><?= dren_ico('user') ?></span>
        <div><span class="t">Personnel femme</span><b><?= $k_f ?></b>
            <small><?= pourcent($k_f, $k_total) ?> % de l'effectif</small></div>
    </div>
    <div class="kpi orange">
        <span class="kpi-ico"><?= dren_ico('briefcase') ?></span>
        <div><span class="t">En poste</span><b><?= $k_p ?></b>
            <small><?= pourcent($k_p, $k_total) ?> % de l'effectif</small></div>
    </div>
</div>

<!-- ===== Liste ===== -->
<div class="carte tableau">
    <div class="entete-liste">
        <h3 class="carte-titre"><?= dren_ico('users') ?> Liste des personnels</h3>
        <?php if ($peut_exporter): ?>
            <a class="btn sec" id="btn-export" href="exporter.php?<?= htmlspecialchars($filtres_export) ?>">
                <?= dren_ico('download') ?> <span>Exporter</span>
            </a>
        <?php endif; ?>
    </div>

    <table data-sans-outils>
        <tr>
            <th><input type="checkbox" id="tout" aria-label="Tout sélectionner"></th>
            <th>N°</th>
            <?= th_tri('nom', 'Nom et prénoms', $tri, $ordre, $base) ?>
            <?= th_tri('matricule', 'Matricule', $tri, $ordre, $base) ?>
            <?= th_tri('fonction', 'Fonction', $tri, $ordre, $base) ?>
            <?= th_tri('etab', 'Établissement actuel', $tri, $ordre, $base) ?>
            <?= th_tri('statut', 'Statut', $tri, $ordre, $base) ?>
            <th>Téléphone</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($agents as $i => $a): ?>
        <tr>
            <td><input type="checkbox" class="sel" value="<?= htmlspecialchars($a['matricule']) ?>"
                       aria-label="Sélectionner <?= htmlspecialchars($a['nom']) ?>"></td>
            <td><?= $offset + $i + 1 ?></td>
            <td><a href="fiche.php?m=<?= urlencode($a['matricule']) ?>">
                <?= htmlspecialchars(mb_strtoupper($a['nom']) . ' ' . $a['prenoms']) ?></a></td>
            <td><?= htmlspecialchars($a['matricule']) ?></td>
            <td><?= htmlspecialchars($a['fonction'] ?? '—') ?></td>
            <td><?= htmlspecialchars($a['etablissement'] ?? 'Aucune affectation') ?></td>
            <td><span class="statut <?= ag_classe_statut($a['statut']) ?>"><?= htmlspecialchars($a['statut']) ?></span></td>
            <td><?= htmlspecialchars($a['telephone'] ?? '—') ?></td>
            <td class="actions">
                <a class="ico-act" href="fiche.php?m=<?= urlencode($a['matricule']) ?>" title="Consulter"
                   aria-label="Consulter la fiche de <?= htmlspecialchars($a['nom']) ?>"><?= dren_ico('eye') ?></a>
                <?php if ($peut_ecrire): ?>
                <a class="ico-act" href="modifier.php?m=<?= urlencode($a['matricule']) ?>" title="Modifier"
                   aria-label="Modifier la fiche de <?= htmlspecialchars($a['nom']) ?>"><?= dren_ico('edit') ?></a>
                <?php endif; ?>
                <?php if ($est_admin): ?>
                <form method="post" action="supprimer.php" data-sans-garde
                      onsubmit="return confirm('Supprimer définitivement ce personnel ?\nCette action est impossible s\'il a un historique.');">
                    <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                    <input type="hidden" name="matricule" value="<?= htmlspecialchars($a['matricule']) ?>">
                    <button type="submit" class="ico-act rouge" title="Supprimer"
                            aria-label="Supprimer <?= htmlspecialchars($a['nom']) ?>"><?= dren_ico('trash') ?></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$agents): ?>
        <tr><td colspan="9">Aucun personnel ne correspond à ces critères.</td></tr>
        <?php endif; ?>
    </table>

    <div class="pied-liste">
        <span>Affichage de <?= $debut ?> à <?= $fin ?> sur <?= $total ?> entrée<?= $total > 1 ? 's' : '' ?></span>
        <?php if ($nb_pages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
            <?php if ($page > 1): ?>
                <a class="pg" href="<?= htmlspecialchars(url_liste($base, ['p' => $page - 1])) ?>" aria-label="Page précédente">‹</a>
            <?php endif; ?>
            <?php $prec = 0; foreach ($pages as $n):
                if ($n - $prec > 1) { echo '<span class="pg-sep">…</span>'; } $prec = $n; ?>
                <a class="pg <?= $n === $page ? 'actif' : '' ?>" href="<?= htmlspecialchars(url_liste($base, ['p' => $n])) ?>"
                   <?= $n === $page ? 'aria-current="page"' : '' ?>><?= $n ?></a>
            <?php endforeach; ?>
            <?php if ($page < $nb_pages): ?>
                <a class="pg" href="<?= htmlspecialchars(url_liste($base, ['p' => $page + 1])) ?>" aria-label="Page suivante">›</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('filtres');

    // Les filtres s'appliquent dès qu'on change une valeur
    form.querySelectorAll('select').forEach(function (s) {
        s.addEventListener('change', function () { form.submit(); });
    });

    // La recherche s'applique après une courte pause de frappe
    var q = document.getElementById('q'), minuteur;
    q.addEventListener('input', function () {
        clearTimeout(minuteur);
        minuteur = setTimeout(function () { form.submit(); }, 600);
    });
    if (q.value) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); }

    // Sélection des lignes : « Exporter » n'exporte alors que les lignes cochées
    var tout = document.getElementById('tout');
    var cases = document.querySelectorAll('.sel');
    var exp = document.getElementById('btn-export');
    function coches() { return Array.prototype.filter.call(cases, function (c) { return c.checked; }); }
    function maj() {
        var n = coches().length;
        if (tout) { tout.checked = n > 0 && n === cases.length; }
        if (exp) { exp.querySelector('span').textContent = n ? 'Exporter (' + n + ')' : 'Exporter'; }
    }
    if (tout) tout.addEventListener('change', function () {
        cases.forEach(function (c) { c.checked = tout.checked; });
        maj();
    });
    cases.forEach(function (c) { c.addEventListener('change', maj); });
    if (exp) exp.addEventListener('click', function (e) {
        var sel = coches().map(function (c) { return c.value; });
        if (sel.length) {
            e.preventDefault();
            var sep = exp.getAttribute('href').indexOf('?') === -1 ? '?' : '&';
            window.location.href = exp.getAttribute('href') + sep + 'sel=' + encodeURIComponent(sel.join(','));
        }
    });
})();
</script>
<?php require "../../includes/footer.php"; ?>

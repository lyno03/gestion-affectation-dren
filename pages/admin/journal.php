<?php
require "../../config/database.php";
require "../../includes/auth.php";
exiger_role(['ADMIN']);

$titre_page = "Journal des actions";
$par_page = 50;

function date_ok($d) {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

// Filtres
$action = trim($_GET['action'] ?? '');
$login  = trim($_GET['login'] ?? '');
$du     = trim($_GET['du'] ?? '');
$au     = trim($_GET['au'] ?? '');
$page   = max(1, (int)($_GET['p'] ?? 1));

$where  = [];
$params = [];
if ($action !== '') { $where[] = "j.action = ?";            $params[] = $action; }
if ($login !== '')  { $where[] = "u.login LIKE ?";          $params[] = "%$login%"; }
if (date_ok($du))   { $where[] = "j.date_heure >= ?";       $params[] = $du . " 00:00:00"; }
if (date_ok($au))   { $where[] = "j.date_heure <= ?";       $params[] = $au . " 23:59:59"; }
$cond = $where ? "WHERE " . implode(" AND ", $where) : "";

// Nombre total de lignes
$stmt = $pdo->prepare("SELECT COUNT(*) FROM journal j JOIN utilisateur u ON u.id_user = j.id_user $cond");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$nb_pages = max(1, (int)ceil($total / $par_page));
$page = min($page, $nb_pages);
$offset = ($page - 1) * $par_page;

// Lignes de la page demandée (LIMIT et OFFSET sont des entiers vérifiés)
$stmt = $pdo->prepare(
    "SELECT j.date_heure, j.action, j.details, u.login
     FROM journal j JOIN utilisateur u ON u.id_user = j.id_user
     $cond ORDER BY j.date_heure DESC, j.id_journal DESC
     LIMIT " . (int)$par_page . " OFFSET " . (int)$offset
);
$stmt->execute($params);
$lignes = $stmt->fetchAll();

$actions = $pdo->query("SELECT DISTINCT action FROM journal ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

// Adresse de pagination qui conserve les filtres
function lien_page($n) {
    $q = $_GET; $q['p'] = $n;
    return '?' . http_build_query($q);
}

require "../../includes/header.php";
?>
<div class="carte">
    <h2>Journal des actions</h2>
    <form method="get" class="actions">
        <select name="action" aria-label="Action">
            <option value="">Toutes les actions</option>
            <?php foreach ($actions as $a): ?>
                <option <?= $action === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="login" placeholder="Utilisateur" value="<?= htmlspecialchars($login) ?>">
        <label>Du <input type="date" name="du" value="<?= htmlspecialchars($du) ?>"></label>
        <label>au <input type="date" name="au" value="<?= htmlspecialchars($au) ?>"></label>
        <button class="btn" type="submit">Filtrer</button>
        <a class="btn sec" href="journal.php">Réinitialiser</a>
    </form>
    <p><?= $total ?> action(s) trouvée(s).</p>
</div>

<div class="carte tableau">
    <table>
        <tr><th>Date et heure</th><th>Utilisateur</th><th>Action</th><th>Détails</th></tr>
        <?php foreach ($lignes as $l): ?>
        <tr>
            <td><?= date("d/m/Y H:i", strtotime($l['date_heure'])) ?></td>
            <td><?= htmlspecialchars($l['login']) ?></td>
            <td><span class="badge"><?= htmlspecialchars($l['action']) ?></span></td>
            <td><?= htmlspecialchars($l['details'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$lignes): ?>
        <tr><td colspan="4">Aucune action trouvée.</td></tr>
        <?php endif; ?>
    </table>
</div>

<?php if ($nb_pages > 1): ?>
<div class="actions">
    <?php if ($page > 1): ?><a class="btn sec" href="<?= htmlspecialchars(lien_page($page - 1)) ?>">← Précédent</a><?php endif; ?>
    <span>Page <?= $page ?> sur <?= $nb_pages ?></span>
    <?php if ($page < $nb_pages): ?><a class="btn sec" href="<?= htmlspecialchars(lien_page($page + 1)) ?>">Suivant →</a><?php endif; ?>
</div>
<?php endif; ?>
<?php require "../../includes/footer.php"; ?>
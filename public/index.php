<?php
declare(strict_types=1);
// Pagina iniziale provvisoria: riepilogo del contenuto del database.
// L'interfaccia completa (sorgenti, risorse, duplicati) arriva nel passo 2.

require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Database;

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

try {
    $db = Database::connection();
    $counts = $db->query("SELECT
        (SELECT count(*) FROM gwc.sources)   AS sorgenti,
        (SELECT count(*) FROM gwc.resources WHERE status <> 'duplicate') AS risorse,
        (SELECT count(*) FROM gwc.endpoints) AS punti_di_accesso,
        (SELECT count(*) FROM gwc.duplicate_candidates WHERE status = 'pending') AS duplicati_da_verificare
    ")->fetch();
    $sources = $db->query("SELECT name, source_type, url, enabled, last_harvest_at FROM gwc.sources ORDER BY name")->fetchAll();
    $error = null;
} catch (Throwable $e) {
    $counts = [];
    $sources = [];
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GeoWebCrawler</title>
<style>
    body { font-family: system-ui, sans-serif; margin: 2rem; color: #222; }
    .cards { display: flex; gap: 1rem; flex-wrap: wrap; }
    .card { border: 1px solid #ccc; border-radius: 8px; padding: 1rem 1.5rem; min-width: 10rem; }
    .card b { display: block; font-size: 2rem; }
    table { border-collapse: collapse; margin-top: 1.5rem; width: 100%; }
    th, td { border-bottom: 1px solid #ddd; padding: .4rem .6rem; text-align: left; }
    .err { background: #fee; border: 1px solid #c00; padding: 1rem; white-space: pre-wrap; }
</style>
</head>
<body>
<h1>GeoWebCrawler</h1>
<?php if ($error): ?>
    <div class="err">Impossibile collegarsi al database:
<?= h($error) ?></div>
<?php else: ?>
    <div class="cards">
        <?php foreach ($counts as $label => $n): ?>
            <div class="card"><b><?= (int) $n ?></b><?= h(str_replace('_', ' ', $label)) ?></div>
        <?php endforeach; ?>
    </div>
    <h2>Sorgenti</h2>
    <table>
        <tr><th>Nome</th><th>Tipo</th><th>URL</th><th>Attiva</th><th>Ultima raccolta</th></tr>
        <?php foreach ($sources as $s): ?>
            <tr>
                <td><?= h($s['name']) ?></td>
                <td><?= h($s['source_type']) ?></td>
                <td><a href="<?= h($s['url']) ?>"><?= h($s['url']) ?></a></td>
                <td><?= $s['enabled'] ? 'si' : 'no' ?></td>
                <td><?= h($s['last_harvest_at'] ?? '-') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
</body>
</html>

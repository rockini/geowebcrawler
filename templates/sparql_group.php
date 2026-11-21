<?php use GeoWebCrawler\Web\View as V; use GeoWebCrawler\Sparql\QueryBuilder as QB;
$chosen = [];
foreach ($columns as $c) { $chosen[implode(' ', $c['path'])] = $c['name']; }
$geoLabels = ['coordinate' => 'coordinate', 'wkt' => 'geometria', 'indirizzo' => 'indirizzo'];
$st = ['ok' => 'ok', 'partial' => 'parziale', 'error' => 'errore', 'running' => 'in corso'];
$urls = \GeoWebCrawler\Repository\ResourceRepository::parsePgArray($g['urls']);
?>
<p><a href="<?= V::url('sparql', ['ep' => $g['endpoint_id']]) ?>">← <?= V::e($g['endpoint_name']) ?></a></p>
<h1><?= V::e($g['label'] ?: QB::localName($g['item_iri'])) ?></h1>
<dl class="kv">
  <dt><?= $g['kind'] === 'dataset' ? 'Dataset' : 'Classe' ?></dt><dd class="mono"><?= V::e($g['item_iri']) ?></dd>
  <?php if ($g['graph_iri']): ?><dt>Grafo</dt><dd class="mono"><?= V::e($g['graph_iri']) ?></dd><?php endif; ?>
  <dt>Oggetti</dt><dd><?= $g['n_instances'] !== null ? number_format((int) $g['n_instances'], 0, ',', '.') : 'non contati' ?></dd>
  <?php if ($g['description']): ?><dt>Descrizione</dt><dd><?= V::e($g['description']) ?></dd><?php endif; ?>
  <?php if ($g['sample_iri']): ?><dt>Esempio</dt><dd class="mono"><?= V::e($g['sample_iri']) ?><?= $g['sample_text'] ? ' · ' . V::e($g['sample_text']) : '' ?></dd><?php endif; ?>
  <dt>Parole chiave</dt><dd><?= V::e(implode(', ', \GeoWebCrawler\Repository\ResourceRepository::parsePgArray($g['keywords']))) ?></dd>
  <dt>Dati geografici</dt><dd><?= $g['profiled_at'] ? V::e(implode(', ', array_map(fn($x) => $geoLabels[$x] ?? $x, \GeoWebCrawler\Repository\ResourceRepository::parsePgArray($g['geo']))) ?: 'non trovati') : 'da analizzare' ?></dd>
  <?php if ($urls): ?><dt>Indirizzi</dt><dd><?php foreach ($urls as $u): ?><div class="mono"><a href="<?= V::e($u) ?>" rel="noreferrer"><?= V::e($u) ?></a></div><?php endforeach; ?></dd><?php endif; ?>
</dl>

<div class="actions">
  <form method="post" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="sparql_bulk">
    <input type="hidden" name="ids[]" value="<?= (int) $g['id'] ?>"><input type="hidden" name="back" value="<?= V::e(V::url('sparql_group', ['id' => $g['id']])) ?>">
    <button name="op" value="<?= $g['selected'] ? 'unselect' : 'select' ?>" class="secondary"><?= $g['selected'] ? 'Togli dalla selezione' : 'Seleziona' ?></button></form>
  <?php if ($g['kind'] === 'class'): ?>
  <form method="post" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="sparql_profile"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
    <button class="secondary"><?= $g['profiled_at'] ? 'Ricalcola il profilo' : 'Analizza le proprieta\'' ?></button></form>
  <?php endif; ?>
</div>

<?php if ($g['kind'] === 'class' && $g['profile']): ?>
<h2>Proprieta' e colonne da scaricare</h2>
<p class="muted">Proprieta' usate da un campione di oggetti. Spunta quelle da mettere nel file (ogni oggetto e' una riga; piu' valori nella stessa cella
  sono separati da " | "). Le proprieta' con → portano ad altri oggetti: "esplora" mostra le loro proprieta' (es. sede → indirizzo → via e civico).
  Analizzato il <?= V::date($g['profiled_at'], true) ?>.</p>
<form method="post"><?= V::csrfField() ?><input type="hidden" name="action" value="sparql_columns"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
<?php foreach ($g['profile'] as $path => $props): $p = $path === '' ? [] : explode(' ', $path); ?>
  <h3 class="path"><?= $path === '' ? 'Proprieta\' degli oggetti' : 'Seguendo ' . V::e(implode(' → ', array_map([QB::class, 'short'], $p))) ?></h3>
  <table>
    <tr><th>Colonna</th><th>Proprieta'</th><th class="num">Oggetti</th><th>Esempio</th><th>Nome della colonna</th><th></th></tr>
    <?php foreach ($props as $x): $full = trim($path . ' ' . $x['p']); if ($x['p'] === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type') continue; ?>
    <tr class="<?= isset($chosen[$full]) ? 'sel' : '' ?>">
      <td><input type="checkbox" name="col[]" value="<?= V::e($full) ?>" <?= isset($chosen[$full]) ? 'checked' : '' ?>></td>
      <td class="mono" title="<?= V::e($x['p']) ?>"><?= V::e($x['short']) ?><?= $x['iri'] ? ' →' : '' ?>
        <?php if ($x['geo']): ?><span class="tag"><?= V::e($geoLabels[$x['geo']] ?? $x['geo']) ?></span><?php endif; ?></td>
      <td class="num"><?= (int) $x['subjects'] ?></td>
      <td class="small mono"><?= V::e(mb_substr($x['example'], 0, 90)) ?></td>
      <td><input name="name[<?= V::e($full) ?>]" value="<?= V::e($chosen[$full] ?? '') ?>" placeholder="<?= V::e(implode('_', array_map([QB::class, 'localName'], array_merge($p, [$x['p']])))) ?>" size="22"></td>
      <td><?php if ($x['iri'] && count($p) < 4 && !isset($g['profile'][$full])): ?>
        <button form="explore" name="path" value="<?= V::e($full) ?>" class="link">esplora</button><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endforeach; ?>
  <details><summary class="muted">Aggiungi colonne a mano</summary>
    <p class="muted">Una colonna per riga: IRI delle proprieta' separati da spazi, es.<br>
      <code>http://dati.beniculturali.it/cis/hasSite http://dati.beniculturali.it/cis/siteAddress https://w3id.org/italia/onto/CLV/fullAddress</code></p>
    <textarea name="extra" rows="3" class="mono"></textarea></details>
  <div class="actions"><button>Salva le colonne scelte</button></div>
</form>
<form method="post" id="explore"><?= V::csrfField() ?><input type="hidden" name="action" value="sparql_profile"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>"></form>
<?php endif; ?>

<h2>Scarica</h2>
<form method="post" class="filters"><?= V::csrfField() ?><input type="hidden" name="action" value="sparql_download"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
  <?php if ($g['kind'] === 'class'): ?>
  <select name="format"><option value="csv">CSV con le colonne scelte (+ GeoJSON se ci sono coordinate)</option><option value="rdf">RDF completo (tutte le triple)</option></select>
  <label>al massimo <input type="number" name="max" min="0" placeholder="tutti" style="width:7rem"> oggetti</label>
  <?php endif; ?>
  <button onclick="this.textContent = 'Scaricamento in corso…'"><?= $g['kind'] === 'dataset' ? 'Scarica il file del dataset' : 'Scarica' ?></button>
</form>
<?php if ($g['kind'] === 'class' && !$columns): ?><p class="muted">Nessuna colonna scelta: prima di scaricare in CSV viene calcolato il profilo e si usano le colonne proposte.</p><?php endif; ?>
<?php if ($g['kind'] === 'class'): ?><p class="muted">Per gruppi molto grandi usa la riga di comando: <code>php bin/sparql.php download <?= (int) $g['id'] ?></code></p><?php endif; ?>

<?php if ($preview): ?>
<details><summary class="muted">Query usata per ogni pagina</summary>
  <pre class="mono"><?= V::e($preview) ?></pre>
  <a href="<?= V::url('sparql_query', ['ep' => $g['endpoint_id'], 'q' => $preview]) ?>">apri nell'editor</a></details>
<?php endif; ?>

<?php if ($downloads): ?>
<h2>Scaricamenti</h2>
<table>
  <tr><th>Quando</th><th>Formato</th><th>Esito</th><th class="num">Oggetti</th><th class="num">Pagine</th><th>File</th><th>Registro</th></tr>
  <?php foreach ($downloads as $d): ?>
  <tr><td><?= V::date($d['started_at'], true) ?></td><td><?= V::e($d['format']) ?></td>
    <td><span class="st <?= V::e($d['status']) ?>"><?= V::e($st[$d['status']] ?? $d['status']) ?></span>
      <?php if ($d['error']): ?><div class="small err"><?= V::e(mb_substr($d['error'], 0, 300)) ?></div><?php endif; ?></td>
    <td class="num"><?= (int) $d['n_subjects'] ?></td><td class="num"><?= (int) $d['n_pages'] ?></td>
    <td><?php if ($d['file_id']): ?><a href="<?= V::url('file', ['id' => $d['file_id']]) ?>"><?= V::e(basename((string) $d['path'])) ?></a>
          <span class="muted"><?= round(((int) $d['size_bytes']) / 1024, 1) ?> KB</span><?php endif; ?>
        <?php if ($d['geojson_file_id']): ?><div><a href="<?= V::url('file', ['id' => $d['geojson_file_id']]) ?>">GeoJSON</a> <span class="muted"><?= (int) $d['geo_rows'] ?> oggetti</span></div><?php endif; ?></td>
    <td><?php if ($d['log']): ?><details><summary class="muted">mostra</summary><pre class="small"><?= V::e($d['log']) ?></pre></details><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

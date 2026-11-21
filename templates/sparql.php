<?php use GeoWebCrawler\Web\View as V; use GeoWebCrawler\Sparql\QueryBuilder as QB; use GeoWebCrawler\Sparql\SparqlService as S;
$ep = null;
foreach ($endpoints as $e) { if ((int) $e['id'] === $epId) { $ep = $e; } }
$back = '?' . http_build_query(array_filter(['p' => 'sparql', 'ep' => $epId, 'q' => $f['q'], 'only' => $f['only']]));
$geoLabels = ['coordinate' => 'coordinate', 'wkt' => 'geometria', 'indirizzo' => 'indirizzo'];
$st = ['ok' => 'ok', 'partial' => 'parziale', 'error' => 'errore', 'running' => 'in corso'];
?>
<h1>SPARQL</h1>
<p class="muted">Cerca negli endpoint SPARQL (es. i dati del Ministero della Cultura) i gruppi di dati che contengono le parole chiave,
  scegli quelli che ti interessano e scaricali: i dati finiscono in file CSV/GeoJSON o RDF nella cartella dei file,
  il database ne tiene solo i riferimenti. <a href="<?= V::url('sparql_query', ['ep' => $epId]) ?>">Editor delle query</a> ·
  <a href="<?= V::url('files') ?>">File salvati</a></p>

<div class="tabs">
  <?php foreach ($endpoints as $e): ?>
    <a class="chip <?= (int) $e['id'] === $epId ? 'on' : '' ?>" href="<?= V::url('sparql', ['ep' => $e['id']]) ?>"><?= V::e($e['name']) ?> <span class="muted"><?= (int) $e['n_groups'] ?></span></a>
  <?php endforeach; ?>
  <details class="inline-details"><summary class="chip">+ endpoint</summary>
    <form method="post" class="grid"><?= V::csrfField() ?><input type="hidden" name="action" value="sparql_endpoint_save">
      <label>Nome <input name="name"></label>
      <label>Indirizzo <input name="url" placeholder="https://.../sparql" required></label>
      <label>Opzioni (JSON) <input name="options" value='{"virtuoso": false, "page_size": 2000, "max_rows": 10000, "timeout": 120}'></label>
      <div><button>Aggiungi</button></div>
    </form>
  </details>
</div>

<?php if ($ep): ?>
<p class="muted mono"><?= V::e($ep['url']) ?>
  <?php if ($ep['last_ok_at']): ?> · ultima risposta <?= V::date($ep['last_ok_at'], true) ?><?php endif; ?>
  <?php if ($ep['last_error']): ?> · <span class="err">ultimo errore: <?= V::e(mb_substr($ep['last_error'], 0, 200)) ?></span><?php endif; ?></p>
<details><summary class="muted">Modifica endpoint</summary>
  <form method="post" class="grid"><?= V::csrfField() ?><input type="hidden" name="action" value="sparql_endpoint_save"><input type="hidden" name="id" value="<?= (int) $ep['id'] ?>">
    <label>Nome <input name="name" value="<?= V::e($ep['name']) ?>"></label>
    <label>Indirizzo <input name="url" value="<?= V::e($ep['url']) ?>"></label>
    <label>Descrizione <input name="description" value="<?= V::e($ep['description']) ?>"></label>
    <label>Opzioni (JSON) <input name="options" value="<?= V::e(is_string($ep['options']) ? $ep['options'] : json_encode($ep['options'])) ?>"></label>
    <div><button>Salva</button></div>
  </form>
  <p class="muted">Opzioni: <code>virtuoso</code> (true per server OpenLink Virtuoso: ricerca testuale veloce con bif:contains),
    <code>page_size</code> (oggetti per pagina nello scaricamento), <code>max_rows</code> (righe massime che il server restituisce),
    <code>timeout</code> (secondi), <code>paging</code> ("offset" se il server non accetta le pagine "dopo l'ultimo oggetto").</p>
  <form method="post" onsubmit="return confirm('Eliminare l\'endpoint con tutti i suoi gruppi?')"><?= V::csrfField() ?>
    <input type="hidden" name="action" value="sparql_endpoint_delete"><input type="hidden" name="id" value="<?= (int) $ep['id'] ?>">
    <button class="danger">Elimina endpoint</button></form>
</details>

<h2>Cerca gruppi di dati</h2>
<form method="get" class="filters">
  <input type="hidden" name="p" value="sparql"><input type="hidden" name="ep" value="<?= $epId ?>"><input type="hidden" name="prepare" value="1">
  <input name="kw" value="<?= V::e($f['kw']) ?>" placeholder='Parole chiave, es. indirizzi civici, "luoghi della cultura", museo' style="flex:1 1 22rem" required>
  <select name="mode"><?php foreach (S::MODES as $k => $label): ?><option value="<?= $k ?>" <?= $f['mode'] === $k ? 'selected' : '' ?>><?= V::e($label) ?></option><?php endforeach; ?></select>
  <label class="check"><input type="checkbox" name="geo" value="1" <?= $f['geo'] ? 'checked' : '' ?>> solo georeferenziabili</label>
  <button>Prepara la query</button>
</form>
<p class="muted">La query preparata si puo' leggere e modificare prima di spedirla. "Solo georeferenziabili" tiene gli oggetti che hanno coordinate,
  geometrie, indirizzi o una sede. Tutte le parole devono comparire.</p>

<?php if ($prepared !== null): ?>
<form method="post" class="query"><?= V::csrfField() ?>
  <input type="hidden" name="action" value="sparql_search"><input type="hidden" name="endpoint_id" value="<?= $epId ?>">
  <input type="hidden" name="kw" value="<?= V::e($f['kw']) ?>"><input type="hidden" name="mode" value="<?= V::e($f['mode']) ?>">
  <?php if ($f['geo']): ?><input type="hidden" name="geo" value="1"><?php endif; ?>
  <textarea name="query" class="mono" rows="<?= min(24, substr_count($prepared, "\n") + 2) ?>" spellcheck="false"><?= V::e($prepared) ?></textarea>
  <div class="actions"><button>Spedisci al server e salva i gruppi trovati</button>
    <a href="<?= V::url('sparql_query', ['ep' => $epId, 'q' => $prepared]) ?>">apri nell'editor</a></div>
</form>
<?php endif; ?>

<h2>Gruppi trovati <span class="muted">(<?= count($groups) ?>)</span></h2>
<form method="get" class="filters">
  <input type="hidden" name="p" value="sparql"><input type="hidden" name="ep" value="<?= $epId ?>">
  <input name="q" value="<?= V::e($f['q']) ?>" placeholder="Filtra per nome, classe o parola chiave">
  <select name="only">
    <?php foreach (['' => 'tutti', 'geo' => 'con dati geografici', 'selected' => 'selezionati', 'dataset' => 'dataset del catalogo'] as $k => $label): ?>
      <option value="<?= $k ?>" <?= $f['only'] === $k ? 'selected' : '' ?>><?= $label ?></option>
    <?php endforeach; ?></select>
  <button>Filtra</button>
</form>

<?php if ($groups): ?>
<form method="post" id="bulk"><?= V::csrfField() ?><input type="hidden" name="action" value="sparql_bulk"><input type="hidden" name="back" value="<?= V::e($back) ?>">
<table>
  <tr><th><input type="checkbox" onclick="document.querySelectorAll('#bulk input[name=&quot;ids[]&quot;]').forEach(c => c.checked = this.checked)"></th>
      <th>Gruppo</th><th>Grafo</th><th class="num">Oggetti</th><th>Esempio</th><th>Dati geografici</th><th>Parole chiave</th><th>Scaricato</th></tr>
  <?php foreach ($groups as $g): $geo = \GeoWebCrawler\Repository\ResourceRepository::parsePgArray($g['geo']); ?>
  <tr class="<?= $g['selected'] ? 'sel' : '' ?>">
    <td><input type="checkbox" name="ids[]" value="<?= (int) $g['id'] ?>"></td>
    <td><a href="<?= V::url('sparql_group', ['id' => $g['id']]) ?>"><?= V::e($g['label'] ?: QB::localName($g['item_iri'])) ?></a>
      <?php if ($g['selected']): ?><span class="st active">selezionato</span><?php endif; ?>
      <?php if ($g['kind'] === 'dataset'): ?><span class="tag">dataset</span><?php endif; ?>
      <div class="muted mono small"><?= V::e(QB::short($g['item_iri'])) ?></div></td>
    <td class="mono small"><?= V::e($g['graph_iri'] ? QB::localName($g['graph_iri']) : '–') ?></td>
    <td class="num"><?= $g['n_instances'] !== null ? number_format((int) $g['n_instances'], 0, ',', '.') : '–' ?></td>
    <td class="small"><?= V::e(mb_substr((string) ($g['sample_text'] ?? $g['description'] ?? ''), 0, 80)) ?></td>
    <td><?= $g['profiled_at'] ? V::e(implode(', ', array_map(fn($x) => $geoLabels[$x] ?? $x, $geo)) ?: 'nessuno') : '<span class="muted">da analizzare</span>' ?></td>
    <td class="small"><?= V::e(implode(', ', \GeoWebCrawler\Repository\ResourceRepository::parsePgArray($g['keywords']))) ?></td>
    <td><?= $g['last_status'] ? '<span class="st ' . V::e($g['last_status']) . '">' . V::e($st[$g['last_status']] ?? $g['last_status']) . '</span> ' . (int) $g['last_n'] : '–' ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<div class="actions">
  <span class="muted">Con i gruppi spuntati:</span>
  <button name="op" value="select" class="secondary">Seleziona</button>
  <button name="op" value="unselect" class="secondary">Togli dalla selezione</button>
  <button name="op" value="profile" class="secondary">Analizza (proprieta' e dati geografici)</button>
  <select name="format"><option value="csv">CSV (+ GeoJSON)</option><option value="rdf">RDF completo</option></select>
  <button name="op" value="download" onclick="return confirm('Scaricare i gruppi spuntati? Per gruppi grandi puo\' richiedere molto tempo: in quel caso e\' meglio la riga di comando (php bin/sparql.php download-selected).')">Scarica</button>
  <button name="op" value="delete" class="danger" onclick="return confirm('Eliminare i gruppi spuntati dall\'elenco?')">Elimina</button>
</div>
</form>
<?php else: ?><p class="muted">Nessun gruppo<?= $f['q'] !== '' || $f['only'] !== '' ? ' con questi filtri' : ': fai una ricerca' ?>.</p><?php endif; ?>

<?php if ($downloads): ?>
<h2>Ultimi scaricamenti</h2>
<table>
  <tr><th>Quando</th><th>Gruppo</th><th>Formato</th><th>Esito</th><th class="num">Oggetti</th><th>File</th></tr>
  <?php foreach ($downloads as $d): ?>
  <tr><td><?= V::date($d['started_at'], true) ?></td>
      <td><a href="<?= V::url('sparql_group', ['id' => $d['group_id']]) ?>"><?= V::e($d['label'] ?: QB::localName($d['item_iri'])) ?></a></td>
      <td><?= V::e($d['format']) ?></td>
      <td><span class="st <?= V::e($d['status']) ?>"><?= V::e($st[$d['status']] ?? $d['status']) ?></span></td>
      <td class="num"><?= (int) $d['n_subjects'] ?></td>
      <td><?php if ($d['file_id']): ?><a href="<?= V::url('file', ['id' => $d['file_id']]) ?>"><?= V::e(basename((string) $d['path'])) ?></a><?php endif; ?>
          <?php if ($d['geojson_file_id']): ?> · <a href="<?= V::url('file', ['id' => $d['geojson_file_id']]) ?>">GeoJSON</a><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ($searches): ?>
<h2>Ultime ricerche</h2>
<table>
  <tr><th>Quando</th><th>Parole</th><th>Tipo</th><th>Esito</th><th class="num">Risultati</th><th class="num">Nuovi</th><th class="num">Tempo</th><th></th></tr>
  <?php foreach ($searches as $s): ?>
  <tr><td><?= V::date($s['started_at'], true) ?></td><td><?= V::e($s['keywords']) ?><?= $s['geo_only'] ? ' <span class="tag">geo</span>' : '' ?></td>
      <td><?= V::e(S::MODES[$s['mode']] ?? $s['mode']) ?></td>
      <td><span class="st <?= V::e($s['status']) ?>"><?= V::e($st[$s['status']] ?? $s['status']) ?></span>
        <?php if ($s['error']): ?><div class="small err"><?= V::e(mb_substr($s['error'], 0, 200)) ?></div><?php endif; ?></td>
      <td class="num"><?= (int) $s['n_rows'] ?></td><td class="num"><?= (int) $s['n_new'] ?></td>
      <td class="num"><?= $s['ms'] !== null ? round($s['ms'] / 1000, 1) . ' s' : '' ?></td>
      <td><a href="<?= V::url('sparql_query', ['search' => $s['id']]) ?>">query</a></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
<?php endif; ?>

<?php use GeoWebCrawler\Web\View as V; use GeoWebCrawler\Sparql\QueryBuilder as QB;
$shown = 500; ?>
<p><a href="<?= V::url('sparql', ['ep' => $epId]) ?>">← SPARQL</a></p>
<h1>Editor delle query SPARQL</h1>
<p class="muted">Scrivi una query o parti da un modello. I prefissi si aggiungono con un clic. "Esegui" mostra le prime <?= $shown ?> righe;
  "Salva in un file" esegue la query e salva tutto il risultato nella cartella dei file. "Salva come gruppi" aggiunge i risultati all'elenco
  dei gruppi (servono le colonne <code>?class</code> oppure <code>?ds</code>; facoltative <code>?g</code>, <code>?label</code>, <code>?n</code>, <code>?esempio</code>).</p>

<form method="post" class="query" id="qform"><?= V::csrfField() ?>
  <div class="filters">
    <select name="endpoint_id"><?php foreach ($endpoints as $e): ?><option value="<?= (int) $e['id'] ?>" <?= (int) $e['id'] === $epId ? 'selected' : '' ?>><?= V::e($e['name']) ?></option><?php endforeach; ?></select>
    <select id="tpl"><option value="">Modelli…</option><?php foreach ($templates as $t => $q): ?><option value="<?= V::e($q) ?>"><?= V::e($t) ?></option><?php endforeach; ?></select>
    <select id="pfx"><option value="">Aggiungi prefisso…</option><?php foreach ($prefixes as $p => $iri): ?><option value="<?= V::e("PREFIX $p: <$iri>") ?>"><?= V::e("$p: $iri") ?></option><?php endforeach; ?></select>
    <button type="button" class="secondary" id="autopfx">Aggiungi i prefissi usati</button>
  </div>
  <textarea name="query" id="query" class="mono" rows="18" spellcheck="false" placeholder="SELECT ?s ?p ?o WHERE { ?s ?p ?o } LIMIT 10"><?= V::e($query) ?></textarea>
  <div class="actions">
    <button name="action" value="sparql_run">Esegui</button>
    <button name="action" value="sparql_save_csv" class="secondary">Salva il risultato in un file</button>
    <input name="kw" placeholder="parole chiave per i gruppi" size="22">
    <button name="action" value="sparql_save_groups" class="secondary">Salva come gruppi</button>
  </div>
</form>

<?php if ($error): ?><div class="flash err"><?= V::e($error) ?></div><?php endif; ?>

<?php if ($result): ?>
  <?php if (isset($result['rdf'])): ?>
    <h2>Risultato <span class="muted">(<?= substr_count($result['rdf'], "\n") ?> righe, <?= V::e($result['type']) ?>, <?= round($result['ms'] / 1000, 2) ?> s)</span></h2>
    <pre class="mono small"><?= V::e(mb_substr($result['rdf'], 0, 100000)) ?></pre>
  <?php else: ?>
    <h2>Risultato <span class="muted">(<?= count($result['rows']) ?> righe, <?= round($result['ms'] / 1000, 2) ?> s<?= count($result['rows']) > $shown ? ", mostrate le prime $shown" : '' ?>)</span></h2>
    <div class="scroll"><table>
      <tr><?php foreach ($result['vars'] as $v): ?><th><?= V::e($v) ?></th><?php endforeach; ?></tr>
      <?php foreach (array_slice($result['rows'], 0, $shown) as $i => $row): ?>
      <tr><?php foreach ($result['vars'] as $v): $val = $row[$v] ?? ''; $isIri = str_starts_with($result['types'][$i][$v] ?? '', 'uri'); ?>
        <td class="small<?= $isIri ? ' mono' : '' ?>"><?php if ($isIri): ?><a href="#" class="iri" data-iri="<?= V::e($val) ?>" title="<?= V::e($val) ?>"><?= V::e(QB::short($val)) ?></a><?php else: ?><?= V::e(mb_substr($val, 0, 300)) ?><?php endif; ?></td>
      <?php endforeach; ?></tr>
      <?php endforeach; ?>
    </table></div>
    <p class="muted">Clic su un IRI: prepara una query che mostra tutte le sue proprieta'.</p>
  <?php endif; ?>
<?php endif; ?>

<script>
(function () {
  const ta = document.getElementById('query');
  const prefixes = <?= json_encode($prefixes, JSON_UNESCAPED_SLASHES) ?>;
  const addLine = line => { if (!ta.value.includes(line)) ta.value = line + '\n' + ta.value; };
  document.getElementById('tpl').addEventListener('change', e => { if (e.target.value) { ta.value = e.target.value; e.target.value = ''; } });
  document.getElementById('pfx').addEventListener('change', e => { if (e.target.value) { addLine(e.target.value); e.target.value = ''; } });
  document.getElementById('autopfx').addEventListener('click', () => {
    const body = ta.value;
    Object.entries(prefixes).reverse().forEach(([p, iri]) => {
      const used = new RegExp('(^|[^\\w-])' + p.replace('-', '\\-') + ':', 'm').test(body.replace(/PREFIX[^\n]*\n/gi, ''));
      if (used && !new RegExp('PREFIX\\s+' + p.replace('-', '\\-') + ':', 'i').test(body)) addLine('PREFIX ' + p + ': <' + iri + '>');
    });
  });
  document.querySelectorAll('a.iri').forEach(a => a.addEventListener('click', e => {
    e.preventDefault();
    ta.value = 'SELECT ?p ?o WHERE {\n  <' + a.dataset.iri + '> ?p ?o\n} LIMIT 200';
    ta.scrollIntoView({behavior: 'smooth'});
  }));
  // Ctrl+Invio esegue
  ta.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) document.querySelector('button[value=sparql_run]').click(); });
})();
</script>

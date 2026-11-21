<?php use GeoWebCrawler\Web\View as V;
$reasons = ['same_external_id' => 'stesso identificativo', 'same_url' => 'stesso URL di accesso', 'similar_title' => 'titolo simile'];
$qs = array_filter(['p' => 'duplicates', 'reason' => $filter['reason'], 'size' => $filter['size'] ?: '', 'q' => $filter['q']], fn($v) => $v !== '');
$back = '?' . http_build_query($qs + ['n' => $pageNo]);
$filtered = $nGroups !== $nAll;
// dati per i grafi: nodi = risorse, archi = coppie
$graphs = [];
foreach ($groups as $gi => $g) {
    $nodes = [];
    foreach (array_slice($g['ids'], 0, 60) as $id) {
        $d = $details[$id] ?? null;
        if ($d) {
            $nodes[] = ['id' => (int) $id, 'title' => $d['title'], 'source' => (string) $d['source_names'], 'org' => (string) $d['organizations'],
                        'type' => $d['resource_type'], 'n' => (int) $d['n_endpoints'], 'bbox' => $d['bbox_json'] ? json_decode($d['bbox_json']) : null];
        }
    }
    $edges = [];
    foreach ($g['pairs'] as $p) {
        $f = $features[$p['candidate_id']] ?? [];
        $edges[] = ['from' => (int) $p['a_id'], 'to' => (int) $p['b_id'], 'reason' => $p['reason'], 'score' => (float) $p['score'],
                    'sim' => isset($f['title_sim']) ? (float) $f['title_sim'] : null,
                    'org' => $f['same_org'] ?? null, 'overlap' => isset($f['bbox_overlap']) ? (float) $f['bbox_overlap'] : null,
                    'url' => $f['same_url'] ?? null];
    }
    $graphs[$gi] = ['nodes' => $nodes, 'edges' => $edges];
}
?>
<h1>Duplicati da verificare <span class="muted">(<?= $nAll ?> gruppi, <?= $total ?> coppie)</span></h1>
<?php if ($byReason): ?><p class="muted">Coppie per motivo: <?= V::e(implode(' · ', array_map(fn($r, $n) => ($reasons[$r] ?? $r) . " $n", array_keys($byReason), $byReason))) ?></p><?php endif; ?>

<div class="actions">
  <a class="button" href="<?= V::url('dup_rules') ?>">Unione automatica con regole…</a>
  <?php if ($certain): ?>
  <form method="post" class="inline" onsubmit="return confirm('Unire automaticamente <?= (int) $certain ?> gruppi di risorse con lo stesso identificativo? In ogni gruppo si tiene la risorsa con piu\' link e metadati piu\' recenti. Le unioni si possono annullare dalla scheda della risorsa.')"><?= V::csrfField() ?>
    <input type="hidden" name="action" value="auto_merge">
    <button class="secondary">Unisci subito i <?= (int) $certain ?> gruppi con lo stesso identificativo</button></form>
  <?php endif; ?>
  <form method="post" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="detect_all">
    <button class="secondary">Ricontrolla tutto l'archivio</button></form>
</div>

<form method="get" class="filters">
  <input type="hidden" name="p" value="duplicates">
  <input name="q" value="<?= V::e($filter['q']) ?>" placeholder="Parola nel titolo">
  <select name="reason"><option value="">tutti i motivi</option>
    <?php foreach ($reasons as $k => $label): ?><option value="<?= $k ?>" <?= $filter['reason'] === $k ? 'selected' : '' ?>><?= V::e($label) ?></option><?php endforeach; ?></select>
  <select name="size"><option value="">qualsiasi dimensione</option>
    <?php foreach ([3, 5, 10, 20] as $n): ?><option value="<?= $n ?>" <?= $filter['size'] === $n ? 'selected' : '' ?>>almeno <?= $n ?> risorse</option><?php endforeach; ?></select>
  <button>Filtra</button>
  <?php if ($filtered): ?><span class="muted"><?= $nGroups ?> gruppi trovati · <a href="<?= V::url('duplicates') ?>">togli i filtri</a></span><?php endif; ?>
</form>

<p class="muted">Le coppie collegate tra loro formano un gruppo. Scegli la risorsa da tenere, togli la spunta "nel gruppo" alle risorse che
  non c'entrano e premi "Unisci": le altre vengono unite a quella tenuta (link, provenienze ed enti passano alla risorsa tenuta, nulla viene cancellato).
  Le celle diverse dalla risorsa tenuta sono evidenziate. "Grafo e mappa" mostra come sono legate le risorse e dove si trovano.</p>

<?php if ($groups): ?>
<p><button type="button" class="secondary" id="overview-btn">Grafo di tutti i gruppi di questa pagina</button></p>
<div id="overview" class="graph big" hidden></div>
<?php endif; ?>

<?php if (!$groups): ?><p>Nessun duplicato in attesa<?= $filtered ? ' con questi filtri' : '' ?>. 🎉</p><?php endif; ?>

<?php foreach ($groups as $gi => $g): $shown = array_slice($g['ids'], 0, 60); ?>
<section class="group" id="g<?= $gi ?>" data-g="<?= $gi ?>">
  <h3><?= count($g['ids']) ?> risorse
    <span class="muted">· <?= V::e(implode(', ', array_unique(array_map(fn($p) => $reasons[$p['reason']] ?? $p['reason'], $g['pairs'])))) ?>
    · somiglianza massima <?= round($g['score'] * 100) ?>%</span>
    <?php if ($g['certain']): ?><span class="st active">certo</span><?php endif; ?></h3>
  <form method="post"><?= V::csrfField() ?>
    <input type="hidden" name="back" value="<?= V::e($back) ?>">
    <table class="cmp">
      <tr><th>Tieni</th><th>Nel gruppo</th><th>Risorsa</th><th>Tipo</th><th>Identificativo</th><th>Enti</th><th>Revisione</th><th>Accesso</th><th>Formati</th><th>Licenza</th><th>Estensione</th><th>Sorgenti</th></tr>
      <?php foreach ($shown as $k => $id): $d = $details[$id] ?? null; if (!$d) continue; ?>
      <tr data-id="<?= (int) $id ?>">
        <td><input type="radio" name="keep" value="<?= (int) $id ?>" <?= $k === 0 ? 'checked' : '' ?>></td>
        <td><input type="checkbox" name="ids[]" value="<?= (int) $id ?>" checked></td>
        <td><a href="<?= V::url('resource', ['id' => $id]) ?>">#<?= (int) $id ?></a> <span class="c"><?= V::e($d['title']) ?></span></td>
        <td class="c"><?= V::e($d['resource_type']) ?></td>
        <td class="mono url c"><?= V::e($d['external_id'] ?? '–') ?></td>
        <td class="c"><?= V::e($d['organizations'] ?? '–') ?></td>
        <td class="c"><?= V::date($d['date_revised'] ?? $d['date_published']) ?></td>
        <td><?= (int) $d['n_endpoints'] ?> <span class="muted"><?= V::e($d['endpoint_types']) ?></span></td>
        <td class="c"><?= V::e($d['formats']) ?></td>
        <td class="c"><?= V::e($d['license'] ?? '–') ?></td>
        <td class="mono c"><?= V::e($d['bbox_txt'] ?? '–') ?></td>
        <td class="c"><?= V::e($d['source_names']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if (count($g['ids']) > count($shown)): ?><p class="muted">Mostrate le prime <?= count($shown) ?> risorse: le azioni valgono solo per queste.</p><?php endif; ?>
    <div class="actions">
      <button name="action" value="group_merge">Unisci alla risorsa scelta</button>
      <button name="action" value="group_reject" class="secondary">Sono tutte diverse</button>
      <button type="button" class="secondary graph-btn">Grafo e mappa</button>
    </div>
    <div class="viz" hidden><div class="graph"></div><div class="gmap"></div></div>
  </form>
</section>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<p class="pager">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <?php if ($i === $pageNo): ?><b><?= $i ?></b><?php elseif ($i <= 3 || $i > $pages - 3 || abs($i - $pageNo) <= 2): ?><a href="<?= V::e('?' . http_build_query($qs + ['n' => $i])) ?>"><?= $i ?></a><?php elseif ($i === 4 || $i === $pages - 3): ?>…<?php endif; ?>
  <?php endfor; ?>
</p>
<?php endif; ?>

<p class="muted legend">Nel grafo: ogni cerchio e' una risorsa (piu' grande = piu' punti di accesso, colore = sorgente), ogni linea una coppia
  <span class="sw" style="background:#2e7d32"></span> stesso identificativo <span class="sw" style="background:#1d6fa5"></span> stesso URL
  <span class="sw" style="background:#e08a00"></span> titolo simile. Linea spessa = molto simili. Clic su un cerchio = tieni quella risorsa, doppio clic = apri la scheda.</p>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/vis-network@9.1.9/standalone/umd/vis-network.min.js"></script>
<script>
(function () {
  const graphs = <?= json_encode($graphs, JSON_UNESCAPED_UNICODE) ?>;
  const reasonColor = {same_external_id: '#2e7d32', same_url: '#1d6fa5', similar_title: '#e08a00'};
  const reasonLabel = {same_external_id: 'stesso identificativo', same_url: 'stesso URL', similar_title: 'titolo simile'};
  const palette = ['#5470c6', '#91cc75', '#fac858', '#ee6666', '#73c0de', '#3ba272', '#fc8452', '#9a60b4', '#ea7ccc', '#8d6e63'];
  const srcColor = {};
  const colorOf = s => srcColor[s] ??= palette[Object.keys(srcColor).length % palette.length];
  const pct = x => x === null || x === undefined ? '–' : Math.round(x * 100) + '%';
  const short = (t, n) => t.length > n ? t.slice(0, n - 1) + '…' : t;
  const yesNo = v => v === null || v === undefined ? 'non noto' : (v ? 'si' : 'no');

  function nodeTip(n) {
    const el = document.createElement('div');
    el.innerText = '#' + n.id + ' ' + n.title + '\n' + n.type + ' · ' + n.n + ' punti di accesso\nEnti: ' + (n.org || '–') + '\nSorgenti: ' + (n.source || '–');
    return el;
  }
  function edgeTip(e) {
    const el = document.createElement('div');
    el.innerText = reasonLabel[e.reason] + ' (' + pct(e.score) + ')\ntitolo ' + pct(e.sim) + ' · aree ' + pct(e.overlap)
      + '\nstesso ente: ' + yesNo(e.org) + ' · stesso URL: ' + yesNo(e.url);
    return el;
  }
  function draw(container, gs, onPick) {
    if (!window.vis) { container.innerText = 'Libreria del grafo non disponibile (serve la connessione a internet).'; return null; }
    const nodes = [], edges = [];
    gs.forEach(([gi, g]) => {
      g.nodes.forEach(n => nodes.push({id: n.id, gi, label: '#' + n.id + '\n' + short(n.title, 28), title: nodeTip(n),
        value: n.n + 1, color: colorOf(n.source), shape: n.type === 'service' ? 'square' : 'dot', font: {size: 11}}));
      g.edges.forEach(e => edges.push({from: e.from, to: e.to, title: edgeTip(e), color: reasonColor[e.reason] || '#999',
        width: 1 + 5 * Math.max(0, (e.score - 0.8) / 0.2), dashes: e.reason === 'similar_title' && e.org === false}));
    });
    const net = new vis.Network(container, {nodes: new vis.DataSet(nodes), edges: new vis.DataSet(edges)}, {
      physics: {stabilization: {iterations: 200}, barnesHut: {springLength: 120}},
      edges: {smooth: {type: 'continuous'}}, nodes: {scaling: {min: 8, max: 28}}, layout: {improvedLayout: nodes.length < 80}, interaction: {hover: true, tooltipDelay: 150}});
    net.on('click', p => p.nodes.length && onPick(p.nodes[0], nodes.find(n => n.id === p.nodes[0]).gi));
    net.on('doubleClick', p => p.nodes.length && (location.href = '?p=resource&id=' + p.nodes[0]));
    return net;
  }
  function drawMap(el, g) {
    if (!window.L) { el.hidden = true; return; }
    const withBox = g.nodes.filter(n => n.bbox);
    if (!withBox.length) { el.innerText = 'Nessuna risorsa del gruppo ha un\'estensione geografica.'; return; }
    const map = L.map(el);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 18, attribution: '© OpenStreetMap'}).addTo(map);
    const fg = L.featureGroup().addTo(map);
    withBox.forEach(n => L.geoJSON(n.bbox, {style: {color: colorOf(n.source), weight: 2, fillOpacity: .08}})
      .bindTooltip('#' + n.id + ' ' + n.title).addTo(fg));
    map.fitBounds(fg.getBounds(), {padding: [10, 10]});
  }
  function pick(section, id) {
    const r = section.querySelector('input[name=keep][value="' + id + '"]');
    if (r) { r.checked = true; mark(section); }
  }
  // evidenzia le celle diverse da quelle della risorsa tenuta
  function mark(section) {
    const rows = [...section.querySelectorAll('table.cmp tr[data-id]')];
    const keep = rows.find(r => r.querySelector('input[name=keep]').checked);
    rows.forEach(r => {
      r.classList.toggle('keep', r === keep);
      r.classList.toggle('out', !r.querySelector('input[name="ids[]"]').checked);
      r.querySelectorAll('.c').forEach((c, i) => c.classList.toggle('diff', !!keep && r !== keep
        && c.innerText.trim() !== keep.querySelectorAll('.c')[i].innerText.trim()));
    });
  }
  document.querySelectorAll('section.group').forEach(section => {
    const gi = section.dataset.g;
    mark(section);
    section.addEventListener('change', () => mark(section));
    let done = false;
    section.querySelector('.graph-btn').addEventListener('click', () => {
      const viz = section.querySelector('.viz');
      viz.hidden = !viz.hidden;
      if (!viz.hidden && !done) {
        done = true;
        draw(viz.querySelector('.graph'), [[gi, graphs[gi]]], id => pick(section, id));
        drawMap(viz.querySelector('.gmap'), graphs[gi]);
      }
    });
  });
  const ob = document.getElementById('overview-btn');
  if (ob) {
    let done = false;
    ob.addEventListener('click', () => {
      const el = document.getElementById('overview');
      el.hidden = !el.hidden;
      if (!el.hidden && !done) {
        done = true;
        draw(el, Object.entries(graphs), (id, gi) => {
          const s = document.getElementById('g' + gi);
          pick(s, id);
          s.scrollIntoView({behavior: 'smooth'});
        });
      }
    });
  }
})();
</script>

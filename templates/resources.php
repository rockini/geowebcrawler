<?php use GeoWebCrawler\Web\View as V;
/** @var \GeoWebCrawler\Web\ResourceSearch $search */
$f = $search->f;
$link = fn(array $o) => V::e('?' . http_build_query(['p' => 'resources'] + $search->params($o)));
$names = ['type' => 'Tipo', 'theme' => 'Tema INSPIRE', 'org' => 'Ente', 'format' => 'Formato', 'ep' => 'Accesso',
          'license' => 'Licenza', 'source' => 'Sorgente', 'year' => 'Anno', 'chk' => 'Verifica dei servizi', 'status' => 'Stato'];
$typeLabels = ['dataset' => 'dataset', 'series' => 'serie', 'service' => 'servizio', 'other' => 'altro'];
$statusLabels = ['active' => 'attive', 'pending' => 'da revisionare', 'rejected' => 'scartate',
                 'duplicate' => 'unite ad altre', 'removed' => 'rimosse dalla sorgente'];
// etichetta del valore scelto per ogni filtro attivo
$chosen = [];
foreach ($names as $k => $label) {
    if ($f[$k] !== '' && !($k === 'status' && $f[$k] === 'active')) {
        $txt = $f[$k];
        foreach ($facets[$k] ?? [] as $row) {
            if ((string) $row['v'] === $f[$k]) { $txt = $row['label']; }
        }
        $chosen[$k] = $label . ': ' . ($k === 'type' ? ($typeLabels[$txt] ?? $txt) : ($k === 'status' ? ($statusLabels[$txt] ?? $txt) : $txt));
    }
}
$bbox = $search->bbox();
?>
<h1>Risorse <span class="muted">(<?= $total ?>)</span></h1>

<form method="get" class="filters">
  <input type="hidden" name="p" value="resources">
  <?php foreach ($search->params() as $k => $v): if ($k !== 'q'): ?><input type="hidden" name="<?= V::e($k) ?>" value="<?= V::e($v) ?>"><?php endif; endforeach; ?>
  <input name="q" value="<?= V::e($f['q']) ?>" placeholder="Cerca nel titolo e nella descrizione">
  <button>Cerca</button>
</form>

<?php if ($chosen || $f['q'] !== '' || $bbox): ?>
<p class="chips">
  <?php if ($f['q'] !== ''): ?><a class="chip on" href="<?= $link(['q' => '', 'n' => '']) ?>">“<?= V::e($f['q']) ?>” ✕</a><?php endif; ?>
  <?php foreach ($chosen as $k => $txt): ?><a class="chip on" href="<?= $link([$k => '']) ?>"><?= V::e($txt) ?> ✕</a><?php endforeach; ?>
  <?php if ($bbox): ?><a class="chip on" href="<?= $link(['bbox' => '']) ?>">Area: <?= V::e(implode(', ', array_map(fn($x) => round($x, 2), $bbox))) ?> ✕</a><?php endif; ?>
  <a href="<?= V::url('resources') ?>">togli tutti i filtri</a>
</p>
<?php endif; ?>

<div class="search">
<aside class="facets">
  <?php foreach ($names as $k => $label): if (empty($facets[$k])) continue; ?>
    <details <?= in_array($k, ['type', 'theme', 'org', 'ep'], true) || $f[$k] !== '' ? 'open' : '' ?>>
      <summary><?= $label ?></summary>
      <ul>
      <?php foreach ($facets[$k] as $row): $v = (string) $row['v'];
            $txt = $k === 'type' ? ($typeLabels[$v] ?? $v) : ($k === 'status' ? ($statusLabels[$v] ?? $v) : $row['label']); ?>
        <li class="<?= $f[$k] === $v ? 'on' : '' ?>">
          <a href="<?= $link([$k => $f[$k] === $v ? '' : $v]) ?>"><?= V::e($txt) ?></a>
          <span class="muted"><?= (int) $row['n'] ?></span></li>
      <?php endforeach; ?>
      </ul>
    </details>
  <?php endforeach; ?>
</aside>

<div>
  <div id="map" class="searchmap"></div>
  <p class="actions">
    <button type="button" class="secondary" id="bbox-btn">Cerca nell'area visibile della mappa</button>
    <span class="muted">Le risorse mostrate in questa pagina sono disegnate sulla mappa.</span>
  </p>

  <table>
    <tr><th>Titolo</th><th>Tipo</th><th>Temi</th><th>Enti</th><th>Accesso</th><th>Formati</th><th>Licenza</th><th>Aggiornato</th></tr>
    <?php foreach ($rows as $r): $themes = \GeoWebCrawler\Repository\ResourceRepository::parsePgArray($r['themes']); ?>
    <tr class="<?= $r['status'] === 'active' ? '' : 'off' ?>" data-id="<?= (int) $r['id'] ?>">
      <td><a href="<?= V::url('resource', ['id' => $r['id']]) ?>"><?= V::e($r['title']) ?></a>
        <?php if ($r['status'] !== 'active'): ?><span class="tag"><?= V::e($statusLabels[$r['status']] ?? $r['status']) ?></span><?php endif; ?></td>
      <td><?= V::e($typeLabels[$r['resource_type']] ?? $r['resource_type']) ?></td>
      <td class="mono"><?= V::e(implode(' ', $themes)) ?></td>
      <td><?= V::e($r['organizations']) ?></td>
      <td><?= V::e($r['endpoint_types']) ?></td>
      <td><?= V::e($r['formats']) ?></td>
      <td><?= V::e($r['license_code'] ?? ($r['license'] ? 'altro' : '')) ?></td>
      <td><?= V::date($r['date_revised'] ?? $r['date_published']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <?php if ($pages > 1): ?>
  <p class="pager">
    <?php if ($pageNo > 1): ?><a href="<?= $link(['n' => $pageNo - 1]) ?>">← precedenti</a><?php endif; ?>
    <?php foreach (array_unique([1, max(1, $pageNo - 2), max(1, $pageNo - 1), $pageNo, min($pages, $pageNo + 1), min($pages, $pageNo + 2), $pages]) as $i): ?>
      <?php if ($i === $pageNo): ?><b><?= $i ?></b><?php else: ?><a href="<?= $link(['n' => $i]) ?>"><?= $i ?></a><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($pageNo < $pages): ?><a href="<?= $link(['n' => $pageNo + 1]) ?>">successivi →</a><?php endif; ?>
  </p>
  <?php endif; ?>
</div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
  const mapEl = document.getElementById('map'), btn = document.getElementById('bbox-btn');
  if (!window.L) { mapEl.style.display = 'none'; btn.parentNode.style.display = 'none'; return; }
  const map = L.map(mapEl).setView([42, 12.5], 5);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 18, attribution: '© OpenStreetMap'}).addTo(map);
  const shapes = <?= json_encode(array_values(array_filter(array_map(fn($r) => $r['bbox_json'] ? ['id' => (int) $r['id'], 'title' => $r['title'], 'g' => json_decode($r['bbox_json'])] : null, $rows)))) ?>;
  const group = L.featureGroup().addTo(map);
  shapes.forEach(s => L.geoJSON(s.g, {style: {color: '#1d6fa5', weight: 1, fillOpacity: .05}})
      .bindTooltip(s.title).on('click', () => location.href = '?p=resource&id=' + s.id).addTo(group));
  const bbox = <?= json_encode($bbox) ?>;
  if (bbox) {
    L.rectangle([[bbox[1], bbox[0]], [bbox[3], bbox[2]]], {color: '#c0392b', weight: 2, fill: false, dashArray: '6 4'}).addTo(map);
    map.fitBounds([[bbox[1], bbox[0]], [bbox[3], bbox[2]]]);
  } else if (group.getLayers().length) {
    map.fitBounds(group.getBounds(), {padding: [10, 10], maxZoom: 10});
  }
  btn.addEventListener('click', () => {
    const b = map.getBounds(), r = x => Math.round(x * 10000) / 10000;
    const u = new URL(location.href);
    u.searchParams.set('bbox', [r(b.getWest()), r(b.getSouth()), r(b.getEast()), r(b.getNorth())].join(','));
    u.searchParams.delete('n');
    location.href = u.toString();
  });
})();
</script>

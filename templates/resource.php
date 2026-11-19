<?php use GeoWebCrawler\Web\View as V; use GeoWebCrawler\Repository\ResourceRepository as RR; ?>
<p><a href="<?= V::url('resources') ?>">← Risorse</a></p>
<h1><?= V::e($r['title']) ?> <span class="st <?= V::e($r['status']) ?>"><?= V::e($r['status']) ?></span></h1>

<?php if ($r['status'] === 'duplicate'): ?>
  <div class="flash warn">Questa risorsa e' stata unita a <a href="<?= V::url('resource', ['id' => $r['canonical_id']]) ?>">#<?= (int) $r['canonical_id'] ?></a>.
    <form method="post" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="unmerge"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="link">Annulla l'unione</button></form>
  </div>
<?php endif; ?>
<?php foreach ($candidates as $c): ?>
  <div class="flash warn">Possibile duplicato di <a href="<?= V::url('resource', ['id' => $c['other_id']]) ?>"><?= V::e($c['other_title']) ?></a>
    (<?= V::e($c['reason']) ?>). <a href="<?= V::url('duplicates') ?>">Decidi nella pagina Duplicati</a>.</div>
<?php endforeach; ?>

<div class="two">
<div>
<table class="kv">
  <tr><th>Tipo</th><td><?= V::e($r['resource_type']) ?></td></tr>
  <tr><th>Identificativo</th><td class="mono"><?= V::e($r['external_id'] ?? '–') ?></td></tr>
  <tr><th>Descrizione</th><td><?= nl2br(V::e($r['abstract'] ?? '–')) ?></td></tr>
  <tr><th>Parole chiave</th><td><?= V::e(implode(', ', RR::parsePgArray($r['keywords']))) ?: '–' ?></td></tr>
  <tr><th>Categorie</th><td><?= V::e(implode(', ', RR::parsePgArray($r['topic_categories']))) ?: '–' ?></td></tr>
  <tr><th>Enti</th><td><?php foreach ($parties as $p): ?><?= V::e($p['name']) ?> <span class="muted">(<?= V::e($p['role']) ?>)</span><?= $p['email'] ? ' · ' . V::e($p['email']) : '' ?><br><?php endforeach; ?><?= $parties ? '' : '–' ?></td></tr>
  <tr><th>Date</th><td>creazione <?= V::date($r['date_created']) ?> · pubblicazione <?= V::date($r['date_published']) ?> · revisione <?= V::date($r['date_revised']) ?></td></tr>
  <tr><th>Aggiornamento</th><td><?= V::e($r['update_frequency'] ?? '–') ?></td></tr>
  <tr><th>Licenza</th><td>
    <?php if ($r['license_code']): ?><b><?= $r['license_url'] ? '<a href="' . V::e($r['license_url']) . '" target="_blank" rel="noopener">' . V::e($r['license_label']) . '</a>' : V::e($r['license_label']) ?></b><br><?php endif; ?>
    <span class="muted"><?= V::e($r['license_text'] ?? ($r['license_code'] ? '' : 'non indicata')) ?></span></td></tr>
  <tr><th>Vincoli d'accesso</th><td><?= V::e($r['access_constraints'] ?? '–') ?></td></tr>
  <tr><th>Sistemi di riferimento</th><td><?php $crs = RR::parsePgArray($r['crs']); echo $crs ? V::e('EPSG:' . implode(', EPSG:', $crs)) : '–'; ?></td></tr>
  <tr><th>Estensione</th><td><?= $r['bbox_json'] ? sprintf('O %.3f · S %.3f · E %.3f · N %.3f', $r['w'], $r['s'], $r['e'], $r['n']) : '–' ?></td></tr>
  <tr><th>Metadati</th><td><?= V::e($r['metadata_standard'] ?? '–') ?>
    <?php if ($r['metadata_url']): ?> · <a href="<?= V::e($r['metadata_url']) ?>" target="_blank" rel="noopener">originale</a><?php endif; ?></td></tr>
  <tr><th>Visto</th><td>la prima volta <?= V::date($r['first_seen_at'], true) ?>, l'ultima <?= V::date($r['last_seen_at'], true) ?></td></tr>
</table>
</div>
<div>
  <?php if ($r['bbox_json']): ?>
    <div id="map"></div>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
      if (window.L) {
      const map = L.map('map');
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 18, attribution: '© OpenStreetMap'}).addTo(map);
      const layer = L.geoJSON(<?= $r['bbox_json'] ?>, {style: {color: '#c0392b', weight: 2, fillOpacity: .1}}).addTo(map);
      map.fitBounds(layer.getBounds(), {padding: [20, 20]});
      } else { document.getElementById('map').style.display = 'none'; }
    </script>
  <?php endif; ?>
  <?php if ($r['status'] !== 'duplicate'): ?>
  <form method="post" class="grid"><?= V::csrfField() ?>
    <input type="hidden" name="action" value="resource_status"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <label>Stato
      <select name="status">
        <?php foreach (['active' => 'attiva', 'pending' => 'da revisionare', 'rejected' => 'scartata'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select></label>
    <label>Nota <input name="note" value="<?= V::e($r['notes']) ?>"></label>
    <div><button>Aggiorna</button></div>
  </form>
  <?php endif; ?>
</div>
</div>

<h2>Punti di accesso</h2>
<table>
  <tr><th>Tipo</th><th>URL</th><th>Layer</th><th>Formato</th><th>Versione</th><th>Ultimo test</th></tr>
  <?php foreach ($endpoints as $e): ?>
  <tr>
    <td><?= V::e($e['type_label']) ?></td>
    <td class="url"><a href="<?= V::e($e['url']) ?>" target="_blank" rel="noopener"><?= V::e($e['url']) ?></a>
      <?php if ($e['label']): ?><br><small class="muted"><?= V::e($e['label']) ?></small><?php endif; ?></td>
    <td class="mono"><?= V::e($e['layer_name']) ?></td>
    <td><?= V::e($e['format_label']) ?></td>
    <td><?= V::e($e['service_version']) ?></td>
    <td><?= $e['last_check_at'] ? ($e['last_check_ok'] ? '✔ ' : '✘ ') . V::date($e['last_check_at'], true) : '<span class="muted">mai (fase 2)</span>' ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h2>Provenienza</h2>
<table>
  <tr><th>Sorgente</th><th>Riferimento nel record</th><th>Prima volta</th><th>Ultima volta</th></tr>
  <?php foreach ($origins as $o): ?>
  <tr><td><a href="<?= V::url('source', ['id' => $o['id']]) ?>"><?= V::e($o['name']) ?></a></td>
      <td class="url mono"><?= V::e($o['record_ref']) ?></td>
      <td><?= V::date($o['first_seen_at'], true) ?></td><td><?= V::date($o['last_seen_at'], true) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php if ($merged): ?>
  <h2>Risorse unite a questa</h2>
  <ul><?php foreach ($merged as $m): ?><li><a href="<?= V::url('resource', ['id' => $m['id']]) ?>"><?= V::e($m['title']) ?></a></li><?php endforeach; ?></ul>
<?php endif; ?>

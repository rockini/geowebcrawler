<?php use GeoWebCrawler\Web\View as V; ?>
<h1>Risorse <span class="muted">(<?= $total ?>)</span></h1>
<form method="get" class="filters">
  <input type="hidden" name="p" value="resources">
  <input name="q" value="<?= V::e($f['q']) ?>" placeholder="Cerca nel titolo e nella descrizione">
  <select name="type">
    <option value="">tutti i tipi</option>
    <?php foreach (['dataset' => 'dataset', 'series' => 'serie', 'service' => 'servizio', 'other' => 'altro'] as $k => $l): ?>
      <option value="<?= $k ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?>
  </select>
  <select name="ep">
    <option value="">qualsiasi accesso</option>
    <?php foreach ($epTypes as $t): ?>
      <option value="<?= V::e($t['code']) ?>" <?= $f['ep'] === $t['code'] ? 'selected' : '' ?>><?= V::e($t['label']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="license">
    <option value="">qualsiasi licenza</option>
    <option value="-" <?= $f['license'] === '-' ? 'selected' : '' ?>>non riconosciuta</option>
    <?php foreach ($licenses as $l): ?>
      <option value="<?= V::e($l['code']) ?>" <?= $f['license'] === $l['code'] ? 'selected' : '' ?>><?= V::e($l['label']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="source">
    <option value="0">tutte le sorgenti</option>
    <?php foreach ($sources as $s): ?>
      <option value="<?= (int) $s['id'] ?>" <?= $f['source'] === (int) $s['id'] ? 'selected' : '' ?>><?= V::e($s['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status">
    <?php foreach (['active' => 'attive e da revisionare', 'pending' => 'da revisionare', 'rejected' => 'scartate', 'duplicate' => 'unite ad altre', 'all' => 'tutte'] as $k => $l): ?>
      <option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?>
  </select>
  <button>Filtra</button>
</form>

<table>
  <tr><th>Titolo</th><th>Tipo</th><th>Enti</th><th>Accesso</th><th>Formati</th><th>Licenza</th><th>Aggiornato</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr class="<?= $r['status'] === 'active' ? '' : 'off' ?>">
    <td><a href="<?= V::url('resource', ['id' => $r['id']]) ?>"><?= V::e($r['title']) ?></a>
      <?php if ($r['status'] !== 'active'): ?><span class="tag"><?= V::e($r['status']) ?></span><?php endif; ?></td>
    <td><?= V::e($r['resource_type']) ?></td>
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
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <?php if ($i === $pageNo): ?><b><?= $i ?></b><?php else: ?><a href="<?= V::e('?' . http_build_query(['p' => 'resources', 'n' => $i] + $f)) ?>"><?= $i ?></a><?php endif; ?>
  <?php endfor; ?>
</p>
<?php endif; ?>

<?php use GeoWebCrawler\Web\View as V; $hl = (int) ($_GET['hl'] ?? 0); ?>
<p><a href="<?= V::url('sparql') ?>">← SPARQL</a></p>
<h1>File salvati <span class="muted">(<?= count($files) ?>, <?= round($total / 1048576, 1) ?> MB)</span></h1>
<p class="muted">I dati grandi stanno in file nella cartella <code class="mono"><?= V::e($root) ?></code>; il database conserva solo i riferimenti
  (percorso, dimensione, impronta sha256). Se sposti la cartella, aggiorna <code>storage_dir</code> in config.php.</p>
<table>
  <tr><th>File</th><th>Tipo</th><th class="num">Righe</th><th class="num">Dimensione</th><th>Creato</th><th>sha256</th><th></th></tr>
  <?php foreach ($files as $f): $meta = json_decode((string) $f['meta'], true) ?: []; ?>
  <tr class="<?= $hl === (int) $f['id'] ? 'sel' : '' ?>">
    <td><a href="<?= V::url('file', ['id' => $f['id']]) ?>" class="mono"><?= V::e($f['path']) ?></a>
      <?php if (!empty($meta['group_id'])): ?><div class="small"><a href="<?= V::url('sparql_group', ['id' => $meta['group_id']]) ?>">gruppo #<?= (int) $meta['group_id'] ?></a></div><?php endif; ?></td>
    <td class="small"><?= V::e($f['media_type']) ?></td>
    <td class="num"><?= $f['n_rows'] !== null ? number_format((int) $f['n_rows'], 0, ',', '.') : '' ?></td>
    <td class="num"><?= $f['size_bytes'] >= 1048576 ? round($f['size_bytes'] / 1048576, 1) . ' MB' : round($f['size_bytes'] / 1024, 1) . ' KB' ?></td>
    <td><?= V::date($f['created_at'], true) ?></td>
    <td class="mono small" title="<?= V::e($f['sha256']) ?>"><?= V::e(substr((string) $f['sha256'], 0, 12)) ?>…</td>
    <td><form method="post" class="inline" onsubmit="return confirm('Cancellare il file dal disco?')"><?= V::csrfField() ?>
      <input type="hidden" name="action" value="file_delete"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><button class="link">cancella</button></form></td>
  </tr>
  <?php endforeach; ?>
</table>

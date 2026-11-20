<?php use GeoWebCrawler\Web\View as V;
$reasons = ['same_external_id' => 'stesso identificativo', 'same_url' => 'stesso URL di accesso', 'similar_title' => 'titolo simile'];
$back = '?' . http_build_query(['p' => 'duplicates', 'n' => $pageNo]); ?>
<h1>Duplicati da verificare <span class="muted">(<?= $nGroups ?> gruppi, <?= $total ?> coppie)</span></h1>
<?php if ($byReason): ?><p class="muted">Coppie per motivo: <?= V::e(implode(' · ', array_map(fn($r, $n) => ($reasons[$r] ?? $r) . " $n", array_keys($byReason), $byReason))) ?></p><?php endif; ?>

<div class="actions">
  <form method="post" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="detect_all">
    <button class="secondary">Ricontrolla tutto l'archivio</button></form>
  <?php if ($certain): ?>
  <form method="post" class="inline" onsubmit="return confirm('Unire automaticamente <?= (int) $certain ?> gruppi di risorse con lo stesso identificativo? In ogni gruppo si tiene la risorsa con piu\' link e metadati piu\' recenti. Le unioni si possono annullare dalla scheda della risorsa.')"><?= V::csrfField() ?>
    <input type="hidden" name="action" value="auto_merge">
    <button>Unisci automaticamente i <?= (int) $certain ?> gruppi con lo stesso identificativo</button></form>
  <?php endif; ?>
</div>
<p class="muted">Le coppie collegate tra loro formano un gruppo. Scegli la risorsa da tenere e premi "Unisci": le altre vengono unite a quella
  (link, provenienze ed enti passano alla risorsa tenuta, nulla viene cancellato). Se non sono la stessa cosa, premi "Sono tutte diverse".</p>

<?php if (!$groups): ?><p>Nessun duplicato in attesa. 🎉</p><?php endif; ?>

<?php foreach ($groups as $gi => $g): $shown = array_slice($g['ids'], 0, 60); ?>
<section class="group">
  <h3><?= count($g['ids']) ?> risorse
    <span class="muted">· <?= V::e(implode(', ', array_unique(array_map(fn($p) => $reasons[$p['reason']] ?? $p['reason'], $g['pairs'])))) ?>
    · somiglianza massima <?= round($g['score'] * 100) ?>%</span>
    <?php if ($g['certain']): ?><span class="st active">certo</span><?php endif; ?></h3>
  <form method="post"><?= V::csrfField() ?>
    <input type="hidden" name="back" value="<?= V::e($back) ?>">
    <?php foreach ($shown as $id): ?><input type="hidden" name="ids[]" value="<?= (int) $id ?>"><?php endforeach; ?>
    <table>
      <tr><th>Tieni</th><th>Risorsa</th><th>Tipo</th><th>Identificativo</th><th>Enti</th><th>Revisione</th><th>Accesso</th><th>Formati</th><th>Licenza</th><th>Estensione</th><th>Sorgenti</th></tr>
      <?php foreach ($shown as $k => $id): $d = $details[$id] ?? null; if (!$d) continue; ?>
      <tr>
        <td><input type="radio" name="keep" value="<?= (int) $id ?>" <?= $k === 0 ? 'checked' : '' ?>></td>
        <td><a href="<?= V::url('resource', ['id' => $id]) ?>">#<?= (int) $id ?></a> <?= V::e($d['title']) ?></td>
        <td><?= V::e($d['resource_type']) ?></td>
        <td class="mono url"><?= V::e($d['external_id'] ?? '–') ?></td>
        <td><?= V::e($d['organizations'] ?? '–') ?></td>
        <td><?= V::date($d['date_revised'] ?? $d['date_published']) ?></td>
        <td><?= (int) $d['n_endpoints'] ?> <span class="muted"><?= V::e($d['endpoint_types']) ?></span></td>
        <td><?= V::e($d['formats']) ?></td>
        <td><?= V::e($d['license'] ?? '–') ?></td>
        <td class="mono"><?= V::e($d['bbox_txt'] ?? '–') ?></td>
        <td><?= V::e($d['source_names']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if (count($g['ids']) > count($shown)): ?><p class="muted">Mostrate le prime <?= count($shown) ?> risorse: le azioni valgono solo per queste.</p><?php endif; ?>
    <div class="actions">
      <button name="action" value="group_merge">Unisci alla risorsa scelta</button>
      <button name="action" value="group_reject" class="secondary">Sono tutte diverse</button>
    </div>
  </form>
</section>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<p class="pager">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <?php if ($i === $pageNo): ?><b><?= $i ?></b><?php elseif ($i <= 3 || $i > $pages - 3 || abs($i - $pageNo) <= 2): ?><a href="<?= V::e('?' . http_build_query(['p' => 'duplicates', 'n' => $i])) ?>"><?= $i ?></a><?php elseif ($i === 4 || $i === $pages - 3): ?>…<?php endif; ?>
  <?php endfor; ?>
</p>
<?php endif; ?>

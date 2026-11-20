<?php use GeoWebCrawler\Web\View as V;
$reasons = ['same_external_id' => 'stesso identificativo', 'same_url' => 'stesso URL di accesso', 'similar_title' => 'titolo simile'];
$fields = ['title' => 'Titolo', 'resource_type' => 'Tipo', 'external_id' => 'Identificativo', 'organizations' => 'Enti',
           'date_published' => 'Pubblicazione', 'date_revised' => 'Revisione', 'license' => 'Licenza', 'formats' => 'Formati',
           'endpoint_types' => 'Accesso', 'n_endpoints' => 'N. link', 'bbox_txt' => 'Estensione', 'source_names' => 'Sorgenti']; ?>
<h1>Duplicati da verificare <span class="muted">(<?= $total ?>)</span></h1>
<?php if ($byReason): ?><p class="muted">Coppie per motivo: <?= V::e(implode(' · ', array_map(fn($r, $n) => ($reasons[$r] ?? $r) . " $n", array_keys($byReason), $byReason))) ?></p><?php endif; ?>
<form method="post" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="detect_all">
  <button class="secondary">Ricontrolla tutto l'archivio</button></form>
<p class="muted">Per ogni coppia scegli quale risorsa tenere: l'altra viene unita (link, provenienze ed enti passano a quella tenuta, nulla viene cancellato). Se non sono la stessa cosa, premi "Sono diverse".</p>

<?php if (!$pairs): ?><p>Nessuna coppia in attesa. 🎉</p><?php endif; ?>

<?php foreach ($pairs as $p): $a = $details[$p['a_id']] ?? null; $b = $details[$p['b_id']] ?? null; if (!$a || !$b) continue; ?>
<section class="pair">
  <h3><?= V::e($reasons[$p['reason']] ?? $p['reason']) ?> <span class="muted">· somiglianza <?= round($p['score'] * 100) ?>%</span></h3>
  <table class="cmp">
    <tr><th></th>
      <th><a href="<?= V::url('resource', ['id' => $a['id']]) ?>">#<?= (int) $a['id'] ?></a></th>
      <th><a href="<?= V::url('resource', ['id' => $b['id']]) ?>">#<?= (int) $b['id'] ?></a></th></tr>
    <?php foreach ($fields as $k => $label): $diff = (string) $a[$k] !== (string) $b[$k]; ?>
      <tr class="<?= $diff ? 'diff' : '' ?>"><th><?= $label ?></th><td><?= V::e($a[$k] ?? '–') ?></td><td><?= V::e($b[$k] ?? '–') ?></td></tr>
    <?php endforeach; ?>
    <tr><th></th>
      <td><form method="post" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="merge">
        <input type="hidden" name="keep" value="<?= (int) $a['id'] ?>"><input type="hidden" name="drop" value="<?= (int) $b['id'] ?>">
        <button>Tieni questa</button></form></td>
      <td><form method="post" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="merge">
        <input type="hidden" name="keep" value="<?= (int) $b['id'] ?>"><input type="hidden" name="drop" value="<?= (int) $a['id'] ?>">
        <button>Tieni questa</button></form></td></tr>
  </table>
  <form method="post" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="not_duplicate">
    <input type="hidden" name="id" value="<?= (int) $p['candidate_id'] ?>"><button class="secondary">Sono diverse</button></form>
</section>
<?php endforeach; ?>

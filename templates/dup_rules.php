<?php use GeoWebCrawler\Web\View as V; use GeoWebCrawler\Repository\DuplicateRules as R;
$pct = fn($x) => (int) round($x * 100); ?>
<h1>Unione automatica dei duplicati</h1>
<p class="muted">Scegli le regole e le soglie, guarda l'anteprima e poi applica. In ogni gruppo si tiene la risorsa con piu' punti
  di accesso e metadati piu' recenti; le altre vengono unite a quella (nulla viene cancellato, ogni unione si annulla dalla scheda della risorsa).
  <a href="<?= V::url('duplicates') ?>">← torna ai duplicati</a></p>

<form method="get" class="rules">
  <input type="hidden" name="p" value="dup_rules"><input type="hidden" name="preview" value="1">
  <fieldset>
    <legend>Unisci le coppie che rispettano almeno una di queste regole</legend>
    <?php foreach (R::MERGE_RULES as $k => $label): ?>
      <label><input type="checkbox" name="rules[]" value="<?= $k ?>" <?= in_array($k, $o['rules'], true) ? 'checked' : '' ?>> <?= V::e($label) ?></label>
    <?php endforeach; ?>
    <div class="row">
      <label>Somiglianza titolo con stesso URL <input type="number" name="url_sim" min="0" max="1" step="0.01" value="<?= $o['url_sim'] ?>"></label>
      <label>Somiglianza titolo con stesso ente <input type="number" name="title_sim" min="0" max="1" step="0.01" value="<?= $o['title_sim'] ?>"></label>
      <label>Sovrapposizione minima delle aree <input type="number" name="overlap" min="0" max="1" step="0.01" value="<?= $o['overlap'] ?>"></label>
      <label>Gruppo al massimo di <input type="number" name="max_group" min="2" max="500" value="<?= $o['max_group'] ?>"> risorse</label>
    </div>
    <p class="muted">Somiglianza e sovrapposizione vanno da 0 a 1 (1 = identici). Se una delle due risorse non ha l'area, la condizione sull'area non si applica.</p>
  </fieldset>
  <fieldset>
    <legend>Scarta (segna come diverse) le coppie con titolo solo simile, enti diversi e aree lontane</legend>
    <div class="row">
      <label>Somiglianza titolo sotto <input type="number" name="reject_sim" min="0" max="1" step="0.01" value="<?= $o['reject_sim'] ?>"></label>
      <label>Sovrapposizione aree sotto <input type="number" name="reject_overlap" min="0" max="1" step="0.01" value="<?= $o['reject_overlap'] ?>"></label>
    </div>
  </fieldset>
  <button>Calcola l'anteprima</button>
</form>

<?php if ($preview): $t = $preview['titles']; ?>
<h2>Anteprima</h2>
<div class="cards">
  <div class="card"><b><?= $preview['groups'] ?></b> gruppi da unire</div>
  <div class="card"><b><?= $preview['resources'] ?></b> risorse unite ad altre</div>
  <div class="card"><b><?= $preview['skipped'] ?></b> gruppi saltati perche' troppo grandi</div>
  <div class="card"><b><?= $preview['reject'] ?></b> coppie da scartare</div>
</div>
<?php if ($preview['by_rule']): ?><p class="muted">Gruppi per regola: <?= V::e(implode(' · ', array_map(fn($r, $n) => (R::MERGE_RULES[$r] ?? $r) . " $n", array_keys($preview['by_rule']), $preview['by_rule']))) ?></p><?php endif; ?>

<div class="actions">
  <?php if ($preview['groups']): ?>
  <form method="post" class="inline" onsubmit="return confirm('Unire <?= $preview['resources'] ?> risorse in <?= $preview['groups'] ?> gruppi?')"><?= V::csrfField() ?>
    <input type="hidden" name="action" value="dup_rules_merge"><input type="hidden" name="options" value="<?= V::e(json_encode($o)) ?>">
    <button>Unisci i <?= $preview['groups'] ?> gruppi</button></form>
  <?php endif; ?>
  <?php if ($preview['reject']): ?>
  <form method="post" class="inline" onsubmit="return confirm('Segnare <?= $preview['reject'] ?> coppie come risorse diverse?')"><?= V::csrfField() ?>
    <input type="hidden" name="action" value="dup_rules_reject"><input type="hidden" name="options" value="<?= V::e(json_encode($o)) ?>">
    <button class="secondary">Scarta le <?= $preview['reject'] ?> coppie</button></form>
  <?php endif; ?>
</div>

<?php if ($preview['sample']): ?>
<h3>Esempi di gruppi da unire</h3>
<table>
  <tr><th>Risorse</th><th>Regole</th></tr>
  <?php foreach ($preview['sample'] as $g): ?>
  <tr><td><?php foreach ($g['ids'] as $id): ?><div><a href="<?= V::url('resource', ['id' => $id]) ?>">#<?= $id ?></a> <?= V::e($t[$id] ?? '') ?></div><?php endforeach; ?></td>
      <td><?= V::e(implode(', ', array_map(fn($r) => R::MERGE_RULES[$r] ?? $r, $g['rules']))) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ($preview['reject_sample']): ?>
<h3>Esempi di coppie da scartare</h3>
<table>
  <tr><th>Risorsa A</th><th>Risorsa B</th><th class="num">Titolo</th><th class="num">Aree</th></tr>
  <?php foreach ($preview['reject_sample'] as $p): ?>
  <tr><td><a href="<?= V::url('resource', ['id' => $p['a_id']]) ?>">#<?= (int) $p['a_id'] ?></a> <?= V::e($t[$p['a_id']] ?? '') ?></td>
      <td><a href="<?= V::url('resource', ['id' => $p['b_id']]) ?>">#<?= (int) $p['b_id'] ?></a> <?= V::e($t[$p['b_id']] ?? '') ?></td>
      <td class="num"><?= $pct($p['sim']) ?>%</td><td class="num"><?= $pct($p['overlap']) ?>%</td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
<?php endif; ?>

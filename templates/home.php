<?php use GeoWebCrawler\Web\View as V; ?>
<h1>Riepilogo</h1>
<div class="cards">
  <a class="card" href="<?= V::url('sources') ?>"><b><?= (int) $counts['sources'] ?></b>sorgenti</a>
  <a class="card" href="<?= V::url('resources') ?>"><b><?= (int) $counts['resources'] ?></b>risorse</a>
  <div class="card"><b><?= (int) $counts['endpoints'] ?></b>punti di accesso</div>
  <a class="card <?= $counts['duplicates'] ? 'alert' : '' ?>" href="<?= V::url('duplicates') ?>"><b><?= (int) $counts['duplicates'] ?></b>duplicati da verificare</a>
</div>

<?php if ($byType): ?>
<h2>Punti di accesso per tipo</h2>
<p class="chips">
  <?php foreach ($byType as $t): ?>
    <a class="chip" href="<?= V::url('resources', ['ep' => $t['endpoint_type']]) ?>"><?= V::e($t['endpoint_type']) ?> <b><?= (int) $t['n'] ?></b></a>
  <?php endforeach; ?>
</p>
<?php endif; ?>

<h2>Ultime raccolte</h2>
<?php if (!$runs): ?>
  <p class="muted">Nessuna raccolta eseguita. Vai su <a href="<?= V::url('sources') ?>">Sorgenti</a> e premi "Raccogli".</p>
<?php else: ?>
<table>
  <tr><th>Quando</th><th>Sorgente</th><th>Esito</th><th class="num">Trovati</th><th class="num">Nuovi</th><th class="num">Aggiornati</th><th></th></tr>
  <?php foreach ($runs as $r): ?>
  <tr>
    <td><?= V::date($r['started_at'], true) ?></td>
    <td><a href="<?= V::url('source', ['id' => $r['source_id']]) ?>"><?= V::e($r['name']) ?></a></td>
    <td><span class="st <?= V::e($r['status']) ?>"><?= V::e($r['status']) ?></span></td>
    <td class="num"><?= (int) $r['records_found'] ?></td>
    <td class="num"><?= (int) $r['records_new'] ?></td>
    <td class="num"><?= (int) $r['records_updated'] ?></td>
    <td><a href="<?= V::url('run', ['id' => $r['id']]) ?>">log</a></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

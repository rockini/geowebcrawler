<?php use GeoWebCrawler\Web\View as V; $back = '?' . http_build_query(['p' => 'orgs', 'q' => $q, 'n' => $pageNo]); ?>
<h1>Enti <span class="muted">(<?= $total ?>)</span></h1>
<p class="muted">Le varianti dello stesso ente ("REGIONE PIEMONTE - Settore…", "Regione Piemonte (Direzione…)") si possono riunire sotto un
  ente principale: nella ricerca delle risorse conta l'ente principale, nelle schede restano i nomi originali.</p>

<?php if ($suggestions): ?>
<h2>Suggerimenti <span class="muted">(<?= (int) $nSuggestions ?> gruppi con lo stesso nome di base)</span></h2>
<form method="post" class="inline" onsubmit="return confirm('Riunire tutti i <?= (int) $nSuggestions ?> gruppi suggeriti? In ogni gruppo l\'ente principale sara\' quello col nome piu\' semplice o con piu\' risorse. Si puo\' annullare ente per ente.')"><?= V::csrfField() ?>
  <input type="hidden" name="action" value="org_auto"><button>Applica tutti i suggerimenti</button></form>
<?php foreach ($suggestions as $g): ?>
<section class="group">
  <form method="post"><?= V::csrfField() ?><input type="hidden" name="action" value="org_merge"><input type="hidden" name="back" value="<?= V::e($back) ?>">
    <table>
      <tr><th>Principale</th><th>Insieme</th><th>Ente</th><th class="num">Risorse</th></tr>
      <?php foreach ($g['orgs'] as $i => $o): ?>
      <tr><td><input type="radio" name="main" value="<?= (int) $o['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>></td>
          <td><input type="checkbox" name="ids[]" value="<?= (int) $o['id'] ?>" checked></td>
          <td><?= V::e($o['name']) ?></td>
          <td class="num"><a href="<?= V::url('resources', ['org' => $o['id']]) ?>"><?= (int) $o['n'] ?></a></td></tr>
      <?php endforeach; ?>
    </table>
    <div class="actions"><button>Riunisci</button></div>
  </form>
</section>
<?php endforeach; ?>
<?php endif; ?>

<h2>Elenco</h2>
<form method="get" class="filters"><input type="hidden" name="p" value="orgs">
  <input name="q" value="<?= V::e($q) ?>" placeholder="Cerca un ente"><button>Cerca</button></form>
<form method="post"><?= V::csrfField() ?><input type="hidden" name="action" value="org_merge"><input type="hidden" name="back" value="<?= V::e($back) ?>">
<p class="muted">Per riunire enti a mano: spunta gli enti nella colonna "Insieme", scegli il principale e premi il pulsante in fondo.</p>
<table>
  <tr><th>Principale</th><th>Insieme</th><th>Ente</th><th class="num">Risorse</th><th>Varianti riunite</th></tr>
  <?php foreach ($list as $o): ?>
  <tr>
    <td><input type="radio" name="main" value="<?= (int) $o['id'] ?>"></td>
    <td><input type="checkbox" name="ids[]" value="<?= (int) $o['id'] ?>"></td>
    <td><?= V::e($o['name']) ?><?= $o['email'] ? ' <span class="muted">· ' . V::e($o['email']) . '</span>' : '' ?></td>
    <td class="num"><a href="<?= V::url('resources', ['org' => $o['id']]) ?>"><?= (int) $o['n'] ?></a></td>
    <td><?php foreach ($o['variants'] as $v): ?>
      <?= V::e($v['name']) ?> <button class="link" form="unmerge-<?= (int) $v['id'] ?>" title="Separa">separa</button><br>
    <?php endforeach; ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<div class="actions"><button>Riunisci gli enti spuntati</button></div>
</form>
<?php foreach ($list as $o): foreach ($o['variants'] as $v): ?>
<form method="post" id="unmerge-<?= (int) $v['id'] ?>" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="org_unmerge">
  <input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><input type="hidden" name="back" value="<?= V::e($back) ?>"></form>
<?php endforeach; endforeach; ?>

<?php if ($pages > 1): ?>
<p class="pager"><?php for ($i = 1; $i <= $pages; $i++): ?>
  <?php if ($i === $pageNo): ?><b><?= $i ?></b><?php else: ?><a href="<?= V::e('?' . http_build_query(['p' => 'orgs', 'q' => $q, 'n' => $i])) ?>"><?= $i ?></a><?php endif; ?>
<?php endfor; ?></p>
<?php endif; ?>

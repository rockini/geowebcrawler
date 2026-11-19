<?php use GeoWebCrawler\Web\View as V; ?>
<p><a href="<?= V::url('sources') ?>">← Sorgenti</a></p>
<h1><?= V::e($source['name']) ?></h1>

<form method="post" class="grid"><?= V::csrfField() ?>
  <input type="hidden" name="action" value="source_save"><input type="hidden" name="id" value="<?= (int) $source['id'] ?>">
  <label>Nome <input name="name" value="<?= V::e($source['name']) ?>" required></label>
  <label>Tipo
    <select name="source_type">
      <?php foreach ($types as $t): ?>
        <option value="<?= V::e($t['code']) ?>" <?= $t['code'] === $source['source_type'] ? 'selected' : '' ?>><?= V::e($t['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="wide">URL <input name="url" type="url" value="<?= V::e($source['url']) ?>" required></label>
  <label class="wide">Descrizione <input name="description" value="<?= V::e($source['description']) ?>"></label>
  <label class="wide">Parametri (JSON) <input name="params" class="mono" value="<?= V::e($source['params']) ?>"></label>
  <label>Raccogli ogni (giorni) <input name="harvest_days" type="number" min="1" value="<?= (int) (preg_match('/(\d+) day/', (string) $source['harvest_every'], $m) ? $m[1] : 7) ?>"></label>
  <label class="check"><input type="checkbox" name="enabled" <?= $source['enabled'] ? 'checked' : '' ?>> attiva</label>
  <div><button>Salva</button></div>
</form>

<div class="actions">
  <form method="post" class="inline" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Raccolta in corso...'"><?= V::csrfField() ?>
    <input type="hidden" name="action" value="harvest"><input type="hidden" name="id" value="<?= (int) $source['id'] ?>">
    <button>Raccogli ora</button>
  </form>
  <a class="button secondary" href="<?= V::url('resources', ['source' => $source['id']]) ?>">Vedi le risorse</a>
  <form method="post" class="inline" onsubmit="return confirm('Eliminare la sorgente? Le risorse raccolte restano in archivio.')"><?= V::csrfField() ?>
    <input type="hidden" name="action" value="source_delete"><input type="hidden" name="id" value="<?= (int) $source['id'] ?>">
    <button class="danger">Elimina</button>
  </form>
</div>

<h2>Raccolte</h2>
<table>
  <tr><th>Inizio</th><th>Esito</th><th class="num">Trovati</th><th class="num">Nuovi</th><th class="num">Aggiornati</th><th>Messaggio</th><th></th></tr>
  <?php foreach ($runs as $r): ?>
  <tr>
    <td><?= V::date($r['started_at'], true) ?></td>
    <td><span class="st <?= V::e($r['status']) ?>"><?= V::e($r['status']) ?></span></td>
    <td class="num"><?= (int) $r['records_found'] ?></td>
    <td class="num"><?= (int) $r['records_new'] ?></td>
    <td class="num"><?= (int) $r['records_updated'] ?></td>
    <td><?= V::e($r['message']) ?></td>
    <td><a href="<?= V::url('run', ['id' => $r['id']]) ?>">log</a></td>
  </tr>
  <?php endforeach; ?>
</table>

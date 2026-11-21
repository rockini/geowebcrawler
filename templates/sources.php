<?php use GeoWebCrawler\Web\View as V; ?>
<h1>Sorgenti</h1>
<?php if ($proposed): ?>
  <div class="flash warn">Ci sono <b><?= (int) $proposed ?></b> nuove sorgenti trovate nei link delle risorse raccolte.
    <a href="<?= V::url('proposals') ?>">Vedi le proposte</a></div>
<?php else: ?>
  <p><a href="<?= V::url('proposals') ?>">Sorgenti proposte</a> <span class="muted">(servizi e cataloghi trovati nei link delle risorse)</span></p>
<?php endif; ?>
<table>
  <tr><th>Nome</th><th>Tipo</th><th>URL</th><th class="num">Risorse</th><th>Ultima raccolta</th><th>Attiva</th><th></th></tr>
  <?php foreach ($sources as $s): ?>
  <tr class="<?= $s['enabled'] ? '' : 'off' ?>">
    <td><a href="<?= V::url('source', ['id' => $s['id']]) ?>"><?= V::e($s['name']) ?></a>
        <?php if ($s['origin'] === 'config'): ?><span class="tag" title="Caricata da config/sources.json">config</span><?php endif; ?></td>
    <td><?= V::e($s['source_type']) ?></td>
    <td class="url"><a href="<?= V::e($s['url']) ?>" target="_blank" rel="noopener"><?= V::e($s['url']) ?></a></td>
    <td class="num"><a href="<?= V::url('resources', ['source' => $s['id']]) ?>"><?= (int) $s['n_resources'] ?></a></td>
    <td><?= V::date($s['last_harvest_at'], true) ?></td>
    <td>
      <form method="post" class="inline"><?= V::csrfField() ?>
        <input type="hidden" name="action" value="source_toggle"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        <button class="link"><?= $s['enabled'] ? 'si' : 'no' ?></button>
      </form>
    </td>
    <td>
      <?php if (in_array($s['source_type'], \GeoWebCrawler\Harvest\HarvestRunner::SUPPORTED, true)): ?>
      <form method="post" class="inline" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='In corso...'"><?= V::csrfField() ?>
        <input type="hidden" name="action" value="harvest"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        <button>Raccogli</button>
      </form>
      <?php else: ?><span class="muted">non ancora supportato</span><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>

<h2>Aggiungi una sorgente</h2>
<form method="post" class="grid"><?= V::csrfField() ?>
  <input type="hidden" name="action" value="source_save">
  <label>Nome <input name="name" required></label>
  <label>Tipo
    <select name="source_type">
      <?php foreach ($types as $t): ?>
        <option value="<?= V::e($t['code']) ?>"><?= V::e($t['label']) ?><?= $t['phase'] > 1 ? ' (versione estesa)' : '' ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="wide">URL <input name="url" type="url" required placeholder="https://.../csw oppure l'indirizzo di una pagina web"></label>
  <label class="wide">Descrizione <input name="description"></label>
  <label class="wide">Parametri (JSON) <input name="params" value="{}" class="mono">
    <small>Facoltativi. CSW: <code>{"max_records": 500, "constraint": "AnyText like '%idro%'", "schema": "dcat"}</code> ·
      Pagina web: <code>{"depth": 1}</code> · Servizio OGC: <code>{"services": ["WMS","WFS"]}</code> ·
      CKAN: <code>{"q": "idrografia", "fq": "organization:regione-toscana"}</code> · DCAT/STAC: <code>{"max_records": 500}</code>.
      Tutti i parametri sono descritti nel README.</small></label>
  <div><button>Aggiungi</button></div>
</form>

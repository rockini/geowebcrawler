<?php use GeoWebCrawler\Web\View as V; ?>
<p><a href="<?= V::url('sources') ?>">← Sorgenti</a></p>
<h1>Sorgenti proposte <span class="muted">(<?= (int) ($counts['proposed'] ?? 0) ?>)</span></h1>
<p class="muted">Servizi WMS, WFS, WCS, WMTS e cataloghi CSW citati nei link delle risorse raccolte che non sono ancora sorgenti.
  La ricerca si ripete da sola dopo ogni raccolta. Se approvi una proposta diventa una sorgente attiva; se la ignori non viene piu' proposta.
  <?php if (!empty($counts['ignored'])): ?>(Proposte ignorate finora: <?= (int) $counts['ignored'] ?>.)<?php endif; ?></p>
<form method="post" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="discover">
  <button class="secondary">Cerca ora nuove sorgenti</button></form>

<?php if ($rows): ?>
<form method="post"><?= V::csrfField() ?><input type="hidden" name="action" value="proposals">
<table>
  <tr><th><input type="checkbox" onclick="document.querySelectorAll('input[name=\'ids[]\']').forEach(c => c.checked = this.checked)" title="Seleziona tutte"></th>
      <th>Nome</th><th>Tipo</th><th>Indirizzo</th><th class="num">Risorse che lo citano</th></tr>
  <?php foreach ($rows as $s): ?>
  <tr>
    <td><input type="checkbox" name="ids[]" value="<?= (int) $s['id'] ?>"></td>
    <td><?= V::e($s['name']) ?></td>
    <td><?= V::e($s['source_type']) ?></td>
    <td class="url"><a href="<?= V::e($s['url']) ?>" target="_blank" rel="noopener"><?= V::e($s['url']) ?></a></td>
    <td class="num"><?= (int) $s['discovered_count'] ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<div class="actions">
  <button name="decision" value="accept">Aggiungi come sorgenti</button>
  <button name="decision" value="ignore" class="secondary">Ignora</button>
</div>
</form>
<?php else: ?><p>Nessuna proposta in attesa.</p><?php endif; ?>

<?php use GeoWebCrawler\Web\View as V;
$stLabel = ['ok' => 'funziona', 'warn' => 'avviso', 'error' => 'non funziona']; ?>
<p><a href="<?= V::url('checks') ?>">← Verifica dei servizi</a></p>
<h1><?= V::e($t['service_title'] ?? ($kind === 'link' ? 'Link' : 'Servizio ' . strtoupper($kind))) ?></h1>

<dl class="kv">
  <dt>Tipo</dt><dd><?= V::e($kind === 'link' ? 'link (' . $endpoints[0]['endpoint_type'] . ')' : strtoupper($kind)) ?></dd>
  <dt>Indirizzo chiamato</dt><dd class="mono"><a href="<?= V::e($requestUrl) ?>" target="_blank" rel="noopener"><?= V::e($requestUrl) ?></a></dd>
  <?php if ($t): ?>
  <dt>Ultimo esito</dt><dd><span class="st <?= V::e($t['last_status']) ?>"><?= $stLabel[$t['last_status']] ?? '' ?></span>
    <?= V::date($t['last_check_at'], true) ?><?= $t['note'] ? ' · ' . V::e($t['note']) : '' ?></dd>
  <dt>Risposta</dt><dd><?= $t['http_status'] ? 'HTTP ' . (int) $t['http_status'] : '–' ?> · <?= number_format((int) $t['response_ms'], 0, ',', '.') ?> ms
    <?= $t['content_type'] ? ' · ' . V::e($t['content_type']) : '' ?><?= $t['content_length'] ? ' · ' . number_format((int) $t['content_length'], 0, ',', '.') . ' byte' : '' ?></dd>
  <?php if ($t['final_url'] && $t['final_url'] !== $requestUrl): ?><dt>Dopo i reindirizzamenti</dt><dd class="mono"><?= V::e($t['final_url']) ?></dd><?php endif; ?>
  <?php if ($t['service_version']): ?><dt>Versione</dt><dd><?= V::e($t['service_version']) ?></dd><?php endif; ?>
  <?php if ($t['n_layers'] !== null): ?><dt>Layer nel servizio</dt><dd><?= (int) $t['n_layers'] ?></dd><?php endif; ?>
  <dt>Verifiche</dt><dd><?= (int) $t['n_checks'] ?><?= (int) $t['n_failures'] ? ' · non funziona da ' . (int) $t['n_failures'] . ' verifiche di seguito' : '' ?>
    <?= $t['last_ok_at'] ? ' · ultima volta funzionante: ' . V::date($t['last_ok_at'], true) : ' · mai funzionante' ?></dd>
  <?php else: ?>
  <dt>Ultimo esito</dt><dd class="muted">mai verificato</dd>
  <?php endif; ?>
</dl>

<form method="post" class="actions"><?= V::csrfField() ?><input type="hidden" name="action" value="check_start">
  <input type="hidden" name="scope" value="selected"><input type="hidden" name="keys[]" value="<?= V::e($key) ?>">
  <input type="hidden" name="back" value="<?= V::e(V::url('check', ['key' => $key])) ?>">
  <button>Verifica ora</button></form>

<h2>Punti di accesso <span class="muted">(<?= count($endpoints) ?>)</span></h2>
<table>
  <tr><th>Risorsa</th><th>Indirizzo</th><th>Layer</th><th>Esito</th></tr>
  <?php foreach ($endpoints as $e): ?>
  <tr class="<?= $e['resource_status'] === 'active' ? '' : 'off' ?>">
    <td><a href="<?= V::url('resource', ['id' => $e['resource_id']]) ?>"><?= V::e($e['title']) ?></a></td>
    <td class="url small"><?= V::e($e['url']) ?></td>
    <td class="mono"><?= V::e($e['layer_name']) ?></td>
    <td><?php if ($e['last_check_status']): ?><span class="st <?= V::e($e['last_check_status']) ?>"><?= $stLabel[$e['last_check_status']] ?></span>
        <?php if ($e['last_check_note']): ?><div class="small"><?= V::e($e['last_check_note']) ?></div><?php endif; ?><?php else: ?><span class="muted">mai</span><?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<?php if ($history): ?>
<h2>Storico</h2>
<table>
  <tr><th>Quando</th><th>Esito</th><th>HTTP</th><th class="num">Tempo</th><th class="num">Layer mancanti</th><th>Note</th><th>Verifica</th></tr>
  <?php foreach ($history as $h): ?>
  <tr><td><?= V::date($h['at'], true) ?></td><td><span class="st <?= V::e($h['worst']) ?>"><?= $stLabel[$h['worst']] ?? $h['worst'] ?></span></td>
    <td><?= $h['http_status'] ? (int) $h['http_status'] : '–' ?></td><td class="num"><?= number_format((int) $h['response_ms'], 0, ',', '.') ?> ms</td>
    <td class="num"><?= (int) $h['missing'] ?: '' ?></td><td class="small"><?= V::e(mb_substr((string) $h['notes'], 0, 300)) ?></td>
    <td><?= $h['batch_id'] ? '#' . (int) $h['batch_id'] : '' ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

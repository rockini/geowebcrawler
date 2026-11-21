<?php use GeoWebCrawler\Web\View as V;
$stLabel = ['ok' => 'funziona', 'warn' => 'avviso', 'error' => 'non funziona'];
$bLabel = ['queued' => 'in attesa', 'running' => 'in corso', 'stopping' => 'in arresto', 'stopped' => 'fermata', 'done' => 'finita', 'error' => 'errore'];
$statusOpts = ['' => 'qualsiasi esito', 'never' => 'mai verificati', 'problems' => 'con problemi (avvisi o errori)', 'error' => 'non funzionano',
               'warn' => 'con avvisi', 'ok' => 'funzionano'];
$params = array_filter($f, fn($v) => $v !== '' && $v !== []);
unset($params['keys']);
$link = fn(array $o) => V::e('?' . http_build_query(array_filter(['p' => 'checks'] + $o + $params + ['order' => $order === 'priority' ? '' : $order], fn($v) => $v !== '' && $v !== null)));
$back = '?' . http_build_query(['p' => 'checks'] + $params + ['n' => $pageNo]);
$kindName = fn($k) => $k === null ? 'Tutti' : strtoupper($k);
?>
<?php if ($running): ?><meta http-equiv="refresh" content="4"><?php endif; ?>
<h1>Verifica dei servizi</h1>
<p class="muted">Controlla se i servizi e i link trovati funzionano davvero. Per i servizi OGC si chiede il GetCapabilities una volta per
  servizio e si controlla che ogni layer citato ci sia ancora; per gli altri link (file da scaricare, pagine) si controlla la risposta.
  Ogni esito resta salvato: qui sotto vedi l'ultimo, nella scheda di ciascuno lo storico.</p>

<?php foreach ($running as $b): $pct = $b['n_targets'] ? (int) round(100 * $b['n_done'] / $b['n_targets']) : 0; ?>
<div class="group">
  <b>Verifica #<?= (int) $b['id'] ?> <?= V::e($bLabel[$b['status']] ?? $b['status']) ?></b>
  <span class="muted">· <?= V::e($b['description']) ?></span>
  <div class="bar"><span style="width: <?= $pct ?>%"></span></div>
  <?= (int) $b['n_done'] ?> di <?= (int) $b['n_targets'] ?> ·
  <span class="st ok"><?= (int) $b['n_ok'] ?> funzionano</span> <span class="st warn"><?= (int) $b['n_warn'] ?> avvisi</span>
  <span class="st error"><?= (int) $b['n_error'] ?> non funzionano</span>
  <form method="post" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="check_stop"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
    <button class="link">ferma</button></form>
  <?php if ($b['status'] === 'queued' && strtotime($b['created_at']) < time() - 20): ?>
    <p class="small err">Non e' ancora partita: forse il PHP da riga di comando non e' stato trovato. Indica il percorso di php.exe in config.php
      (<code>check.php_cli</code>) oppure eseguila con <code>php bin\check.php --batch=<?= (int) $b['id'] ?></code>.</p>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<table class="summary">
  <tr><th>Tipo</th><th class="num">Servizi o link</th><th class="num">Funzionano</th><th class="num">Avvisi</th><th class="num">Non funzionano</th><th class="num">Mai verificati</th></tr>
  <?php foreach ($summary as $s): $t = $s['kind'] ?? ''; ?>
  <tr class="<?= $t === '' ? 'total' : '' ?>">
    <td><a href="<?= $link(['type' => $t, 'status' => '', 'n' => '']) ?>"><?= V::e($t === '' ? 'Tutti' : ($types[$t] ?? $t)) ?></a></td>
    <td class="num"><?= (int) $s['total'] ?></td>
    <td class="num"><a href="<?= $link(['type' => $t, 'status' => 'ok', 'n' => '']) ?>"><?= (int) $s['ok'] ?></a></td>
    <td class="num"><a href="<?= $link(['type' => $t, 'status' => 'warn', 'n' => '']) ?>"><?= (int) $s['warn'] ?></a></td>
    <td class="num"><a href="<?= $link(['type' => $t, 'status' => 'error', 'n' => '']) ?>"><?= (int) $s['error'] ?></a></td>
    <td class="num"><a href="<?= $link(['type' => $t, 'status' => 'never', 'n' => '']) ?>"><?= (int) $s['never'] ?></a></td>
  </tr>
  <?php endforeach; ?>
</table>

<h2>Scegli cosa verificare</h2>
<form method="get" class="filters">
  <input type="hidden" name="p" value="checks">
  <select name="type"><option value="">tutti i tipi</option>
    <?php foreach ($types as $code => $label): ?><option value="<?= V::e($code) ?>" <?= $f['type'] === $code ? 'selected' : '' ?>><?= V::e($label) ?></option><?php endforeach; ?></select>
  <select name="status"><?php foreach ($statusOpts as $k => $label): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
  <select name="source"><option value="">tutte le sorgenti</option>
    <?php foreach ($sources as $id => $name): ?><option value="<?= (int) $id ?>" <?= $f['source'] === (string) $id ? 'selected' : '' ?>><?= V::e($name) ?></option><?php endforeach; ?></select>
  <input name="host" value="<?= V::e($f['host']) ?>" placeholder="parte dell'indirizzo (es. regione.lazio)" size="22">
  <input name="q" value="<?= V::e($f['q']) ?>" placeholder="parola nel titolo della risorsa" size="22">
  <label>verificati da piu' di <input type="number" name="older" min="0" value="<?= V::e($f['older']) ?>" style="width:4.5rem"> giorni</label>
  <?php foreach (['org', 'theme', 'resource'] as $k): if ($f[$k] !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= V::e($f[$k]) ?>"><?php endif; endforeach; ?>
  <button>Filtra</button>
  <?php if ($params): ?><a href="<?= V::url('checks') ?>">togli i filtri</a><?php endif; ?>
</form>

<form method="post" id="chk"><?= V::csrfField() ?><input type="hidden" name="action" value="check_start"><input type="hidden" name="back" value="<?= V::e($back) ?>">
  <?php foreach ($params as $k => $v): ?><input type="hidden" name="<?= V::e($k) ?>" value="<?= V::e($v) ?>"><?php endforeach; ?>
  <div class="actions">
    <b><?= $counts['targets'] ?></b>&nbsp;servizi o link scelti <span class="muted">(<?= $counts['endpoints'] ?> punti di accesso di <?= $counts['resources'] ?> risorse)</span>
    <button name="scope" value="selected" class="secondary">Verifica quelli spuntati</button>
    <button name="scope" value="all" onclick="return confirm('Verificare tutti i servizi o link scelti dal filtro (al massimo il numero indicato)?')">Verifica tutti quelli del filtro</button>
    <label>al massimo <input type="number" name="limit" value="<?= min(1000, max(1, $counts['targets'])) ?>" min="1" style="width:6rem"></label>
  </div>
  <p class="muted small">Si comincia da quelli mai verificati e da quelli verificati da piu' tempo. Le verifiche grandi vanno avanti in background
    (puoi chiudere la pagina); dalla riga di comando: <code>php bin\check.php --status=never --limit=5000 -v</code>.</p>

<table>
  <tr><th><input type="checkbox" onclick="document.querySelectorAll('#chk input[name=&quot;keys[]&quot;]').forEach(c => c.checked = this.checked)"></th>
      <th>Tipo</th><th>Servizio o link</th><th class="num">Risorse</th><th>Esito</th><th>Dettagli</th>
      <th><a href="<?= $link(['order' => $order === 'recent' ? '' : 'recent', 'n' => '']) ?>">Ultima verifica <?= $order === 'recent' ? '↓' : '' ?></a></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><input type="checkbox" name="keys[]" value="<?= V::e($r['check_key']) ?>"></td>
    <td><?= V::e(strtoupper($r['endpoint_type'])) ?></td>
    <td class="url"><a href="<?= V::url('check', ['key' => $r['check_key']]) ?>"><?= V::e($r['service_title'] ?: preg_replace('~^https?://~', '', $r['url'])) ?></a>
      <?php if ($r['service_title']): ?><div class="muted small mono"><?= V::e(preg_replace('~^https?://~', '', $r['url'])) ?></div><?php endif; ?>
      <?php if ((int) $r['n_resources'] === 1): ?><div class="muted small"><?= V::e(mb_substr((string) $r['sample_title'], 0, 90)) ?></div><?php endif; ?></td>
    <td class="num"><?= (int) $r['n_resources'] ?></td>
    <td><?php if ($r['last_status']): ?><span class="st <?= V::e($r['last_status']) ?>"><?= $stLabel[$r['last_status']] ?></span>
          <?php if ($r['last_status'] === 'ok' && (int) $r['n_warn_endpoints'] > 0): ?><div class="small warn-text"><?= (int) $r['n_warn_endpoints'] ?> accessi con avviso (layer mancanti)</div><?php endif; ?>
          <?php if ((int) $r['n_failures'] > 1): ?><div class="small err"><?= (int) $r['n_failures'] ?> volte di seguito</div><?php endif; ?>
        <?php else: ?><span class="muted">mai</span><?php endif; ?></td>
    <td class="small"><?php if ($r['last_status']): ?>
        <?= $r['http_status'] ? 'HTTP ' . (int) $r['http_status'] . ' · ' : '' ?><?= $r['response_ms'] !== null ? number_format((int) $r['response_ms'], 0, ',', '.') . ' ms' : '' ?>
        <?= $r['service_version'] ? ' · v' . V::e($r['service_version']) : '' ?><?= $r['n_layers'] !== null ? ' · ' . (int) $r['n_layers'] . ' layer' : '' ?>
        <?php if ($r['note']): ?><div><?= V::e(mb_substr($r['note'], 0, 200)) ?></div><?php endif; ?><?php endif; ?></td>
    <td class="small"><?= $r['last_check_at'] ? V::date($r['last_check_at'], true) : '–' ?></td>
  </tr>
  <?php endforeach; ?>
</table>
</form>

<?php if ($pages > 1): ?>
<p class="pager">
  <?php if ($pageNo > 1): ?><a href="<?= $link(['n' => $pageNo - 1]) ?>">← precedenti</a><?php endif; ?>
  pagina <?= $pageNo ?> di <?= $pages ?>
  <?php if ($pageNo < $pages): ?><a href="<?= $link(['n' => $pageNo + 1]) ?>">successivi →</a><?php endif; ?>
</p>
<?php endif; ?>

<?php if ($batches): ?>
<h2>Ultime verifiche lanciate</h2>
<table>
  <tr><th>#</th><th>Quando</th><th>Cosa</th><th>Stato</th><th class="num">Fatte</th><th class="num">Funzionano</th><th class="num">Avvisi</th><th class="num">Non funzionano</th><th></th></tr>
  <?php foreach ($batches as $b): ?>
  <tr><td><?= (int) $b['id'] ?></td><td><?= V::date($b['created_at'], true) ?> <span class="muted small"><?= $b['origin'] === 'cli' ? 'riga di comando' : '' ?></span></td>
    <td class="small"><?= V::e($b['description']) ?></td>
    <td><span class="st <?= V::e($b['status']) ?>"><?= V::e($bLabel[$b['status']] ?? $b['status']) ?></span>
      <?php if ($b['message']): ?><div class="small"><?= V::e($b['message']) ?></div><?php endif; ?></td>
    <td class="num"><?= (int) $b['n_done'] ?> / <?= (int) $b['n_targets'] ?></td>
    <td class="num"><?= (int) $b['n_ok'] ?></td><td class="num"><?= (int) $b['n_warn'] ?></td><td class="num"><?= (int) $b['n_error'] ?></td>
    <td><?php if (in_array($b['status'], ['stopped', 'error'], true) && (int) $b['n_done'] < (int) $b['n_targets']): ?>
      <form method="post" class="inline"><?= V::csrfField() ?><input type="hidden" name="action" value="check_resume"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
        <button class="link">continua</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

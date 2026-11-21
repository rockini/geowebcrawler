<?php use GeoWebCrawler\Web\View as V; ?>
<p><a href="<?= V::url('source', ['id' => $run['source_id']]) ?>">← <?= V::e($run['name']) ?></a></p>
<h1>Raccolta #<?= (int) $run['id'] ?> <span class="st <?= V::e($run['status']) ?>"><?= V::e($run['status']) ?></span></h1>
<p><?= V::e($run['message']) ?></p>
<p class="muted">Inizio <?= V::date($run['started_at'], true) ?> · fine <?= V::date($run['finished_at'], true) ?></p>
<pre class="log"><?= V::e($run['log']) ?></pre>

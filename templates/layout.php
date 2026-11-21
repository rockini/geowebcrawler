<?php use GeoWebCrawler\Web\View as V; $cur = $_GET['p'] ?? 'home'; ?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= V::e($title) ?> · GeoWebCrawler</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header>
  <a class="brand" href="?">GeoWebCrawler</a>
  <nav>
    <?php foreach (['home' => 'Riepilogo', 'sources' => 'Sorgenti', 'resources' => 'Risorse', 'duplicates' => 'Duplicati', 'orgs' => 'Enti'] as $p => $label): ?>
      <a href="<?= V::url($p) ?>" class="<?= $cur === $p || (in_array($cur, ['source', 'proposals'], true) && $p === 'sources') || ($cur === 'resource' && $p === 'resources') || ($cur === 'dup_rules' && $p === 'duplicates') ? 'on' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main>
<?php foreach ($flash as [$type, $msg]): ?>
  <div class="flash <?= V::e($type) ?>"><?= V::e($msg) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
</body>
</html>

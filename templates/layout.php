<?php use App\View\View; ?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::escape($title) ?> · <?= View::escape($appName) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
<?php if (in_array($path, ['/uren', '/factuur-preview','/concept-bevestigen', '/projecten'], true)): ?><script src="/assets/daily.js" defer></script><?php endif; ?>
<?php if (in_array($path, ['/uren', '/factuur-preview'], true)): ?><script src="/assets/selection.js" defer></script><?php endif; ?>
</head>
<body>
<header><div class="container"><a class="brand" href="/"> <?= View::escape($appName) ?></a><span class="badge">Lokale facturatie</span></div></header>
<nav class="container" aria-label="Hoofdnavigatie">
<?php foreach (['/' => 'Start', '/toggl' => 'Toggl-status', '/wefact' => 'WeFact-status', '/uren' => 'Uren', '/projecten' => 'Projectkoppelingen', '/factuur-preview' => 'Factuur-preview', '/facturen' => 'Facturen'] as $url => $label): ?>
    <a href="<?= View::escape($url) ?>" <?= $path === $url ? 'aria-current="page"' : '' ?>><?= View::escape($label) ?></a>
<?php endforeach; ?>
</nav>
<main class="container"><section class="panel"><h1><?= View::escape($title) ?></h1><p><?= View::escape($message) ?></p>
<?php if ($path === '/'): ?><p><a href="/uren">Uren ophalen</a> · <a href="/projecten">Projectkoppelingen en tarieven</a> · <a href="/facturen">Factuurhistorie</a></p><p>Facturen worden alleen als concept aangemaakt. Verzenden doe je in WeFact.</p><?php endif; ?>
<?php if ($path === '/toggl'): extract($data, EXTR_SKIP); require __DIR__ . '/toggl.php'; endif; ?>
<?php if (in_array($path, ['/uren', '/factuur-preview'], true)): extract($data, EXTR_SKIP); require __DIR__ . '/hours.php'; endif; ?>
<?php if ($path === '/wefact'): extract($data, EXTR_SKIP); require __DIR__ . '/wefact.php'; endif; ?>
<?php if ($path === '/projecten'): extract($data, EXTR_SKIP); require __DIR__ . '/projects.php'; endif; ?>
<?php if ($path === '/concept-bevestigen'): extract($data, EXTR_SKIP); require __DIR__ . '/confirmation.php'; endif; ?>
<?php if ($path === '/facturen/urenspecificatie'): extract($data, EXTR_SKIP); require __DIR__ . '/specification.php'; endif; ?>
<?php if ($path === '/facturen'): extract($data, EXTR_SKIP); require __DIR__ . '/invoices.php'; endif; ?>
</section></main>
<footer class="container">Toggl → WeFact · lokale facturatie-app</footer>
</body>
</html>

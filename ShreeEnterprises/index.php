<?php
require_once __DIR__ . '/includes/functions.php';
$cat = (int)($_GET['cat'] ?? 0);
$s = trim($_GET['s'] ?? '');
$sql = 'SELECT p.name, p.sales_rate, p.unit, p.stock_qty, c.name AS category, sc.name AS subcategory
        FROM products p JOIN categories c ON c.id = p.category_id JOIN subcategories sc ON sc.id = p.subcategory_id WHERE 1=1';
$params = [];
if ($cat) { $sql .= ' AND p.category_id = ?'; $params[] = $cat; }
if ($s !== '') { $sql .= ' AND (p.name LIKE ? OR sc.name LIKE ? OR c.name LIKE ?)'; array_push($params, "%$s%", "%$s%", "%$s%"); }
$products = rows($sql . ' ORDER BY c.name, p.name', $params);
$cats = rows('SELECT id, name FROM categories ORDER BY name');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Shree Enterprises · Your Trusted Partner in Tiles</title><link rel="icon" href="assets/img/favicon.png"><link rel="stylesheet" href="assets/css/style.css"></head><body>
<div class="pub-head"><h1><?= shop_logo(56) ?> <?= e(SHOP_NAME) ?></h1><a class="btn" href="<?= user() ? 'dashboard.php' : 'login.php' ?>"><?= user() ? 'Dashboard' : 'Staff / Admin Login' ?></a></div>
<div class="hero"><h2>Tiles for every floor, wall and ceiling</h2>
<p>Serving Karad since <?= SHOP_SINCE ?> · Ceramic, Marble, Porcelain, Metal, Mosaic &amp; Ceiling tiles from leading brands</p></div>
<div class="pub-wrap">
<form class="bar" method="get">
  <div><label>Category</label><select name="cat" onchange="this.form.submit()"><option value="">All categories</option>
    <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= $cat == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>Search</label><input name="s" value="<?= e($s) ?>" placeholder="Brand / size / type"></div>
  <button class="btn">Search</button>
</form>
<div class="grid g4">
<?php foreach ($products as $p): ?>
  <div class="prod"><div class="sw"></div>
    <h4><?= e($p['name']) ?></h4>
    <small><?= e($p['category']) ?> · <?= e($p['subcategory']) ?></small>
    <p class="pr"><?= money($p['sales_rate']) ?> / <?= e($p['unit']) ?></p>
    <span class="badge <?= $p['stock_qty'] > 0 ? 'paid' : 'low' ?>"><?= $p['stock_qty'] > 0 ? 'In stock' : 'Out of stock' ?></span>
  </div>
<?php endforeach; if (!$products): ?><p>No tiles found.</p><?php endif; ?>
</div></div>
<footer class="pub"><b><?= SHOP_NAME ?></b> · <?= e(SHOP_ADDRESS) ?><br>Owner: <?= e(SHOP_OWNER) ?> · Mob: <?= e(SHOP_PHONE) ?></footer>
</body></html>

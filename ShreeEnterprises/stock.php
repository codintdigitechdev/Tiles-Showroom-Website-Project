<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$cat = (int)($_GET['cat'] ?? 0); $sub = (int)($_GET['sub'] ?? 0); $prod = (int)($_GET['prod'] ?? 0); $low = isset($_GET['low']);
$sql = 'SELECT p.*, c.name AS category, s.name AS subcategory, sp.name AS supplier FROM products p
        JOIN categories c ON c.id = p.category_id JOIN subcategories s ON s.id = p.subcategory_id JOIN suppliers sp ON sp.id = p.supplier_id WHERE 1=1';
$pr = [];
if ($cat) { $sql .= ' AND p.category_id = ?'; $pr[] = $cat; }
if ($sub) { $sql .= ' AND p.subcategory_id = ?'; $pr[] = $sub; }
if ($prod) { $sql .= ' AND p.id = ?'; $pr[] = $prod; }
if ($low) { $sql .= ' AND p.stock_qty <= ?'; $pr[] = LOW_STOCK_LIMIT; }
$list = rows($sql . ' ORDER BY c.name, s.name, p.name', $pr);
$cats = rows('SELECT id, name FROM categories ORDER BY name');
$subs = rows('SELECT id, name FROM subcategories' . ($cat ? ' WHERE category_id = ' . $cat : '') . ' ORDER BY name');
$prods = rows('SELECT id, name FROM products' . ($sub ? ' WHERE subcategory_id = ' . $sub : '') . ' ORDER BY name');
$page_title = 'Remaining Stock';
require __DIR__ . '/includes/header.php';
$value = 0;
?>
<form class="bar" method="get">
  <div><label>Category</label><select name="cat" onchange="this.form.sub.value='';this.form.prod.value='';this.form.submit()"><option value="">All</option>
    <?php foreach ($cats as $x): ?><option value="<?= $x['id'] ?>" <?= $cat == $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>Subcategory</label><select name="sub" onchange="this.form.prod.value='';this.form.submit()"><option value="">All</option>
    <?php foreach ($subs as $x): ?><option value="<?= $x['id'] ?>" <?= $sub == $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>Product</label><select name="prod"><option value="">All</option>
    <?php foreach ($prods as $x): ?><option value="<?= $x['id'] ?>" <?= $prod == $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div><label><input type="checkbox" name="low" value="1" style="width:auto" <?= $low ? 'checked' : '' ?>> Low stock only (≤ <?= LOW_STOCK_LIMIT ?>)</label></div>
  <button class="btn">Refresh</button>
</form>
<div class="tw"><table>
<tr><th>Category</th><th>Subcategory</th><th>Product</th><?php if (is_admin()): ?><th>Supplier</th><?php endif; ?><th class="r">Sales Rate</th><th class="r">Stock</th><th>Unit</th><?php if (is_admin()): ?><th class="r">Stock Value (cost)</th><?php endif; ?></tr>
<?php foreach ($list as $p): $value += $p['stock_qty'] * $p['purchase_rate']; ?>
<tr><td><?= e($p['category']) ?></td><td><?= e($p['subcategory']) ?></td><td><?= e($p['name']) ?></td>
<?php if (is_admin()): ?><td><?= e($p['supplier']) ?></td><?php endif; ?>
<td class="r"><?= money($p['sales_rate']) ?></td>
<td class="r"><span class="badge <?= $p['stock_qty'] <= LOW_STOCK_LIMIT ? 'low' : '' ?>"><?= $p['stock_qty'] ?></span></td><td><?= e($p['unit']) ?></td>
<?php if (is_admin()): ?><td class="r"><?= money($p['stock_qty'] * $p['purchase_rate']) ?></td><?php endif; ?></tr>
<?php endforeach; if (!$list): ?><tr><td colspan="8">No products match.</td></tr><?php endif; ?>
<?php if ($list && is_admin()): ?><tr><th colspan="7" class="r">Total stock value at cost</th><th class="r"><?= money($value) ?></th></tr><?php endif; ?>
</table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>

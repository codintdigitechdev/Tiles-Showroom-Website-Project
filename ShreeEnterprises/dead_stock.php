<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pid = (int)($_POST['product_id'] ?? 0); $qty = (int)($_POST['quantity'] ?? 0);
    $date = $_POST['dead_date'] ?? ''; $reason = trim($_POST['reason'] ?? '');
    try { $qty = (int)v_num($_POST['quantity'] ?? '', 'Quantity', 1, 100000, true); $date = v_date($date, 'Date', false); $reason = v_text($reason, 'Reason', 150); }
    catch (RuntimeException $ex) { flash($ex->getMessage(), 'err'); redirect('dead_stock.php'); }
    $pdo = db(); $pdo->beginTransaction();
    $p = row('SELECT * FROM products WHERE id = ? FOR UPDATE', [$pid]);
    if (!$p || $qty <= 0 || !strtotime($date)) { $pdo->rollBack(); flash('Select a product, valid quantity and date.', 'err'); }
    elseif ($qty > $p['stock_qty']) { $pdo->rollBack(); flash("Only {$p['stock_qty']} {$p['unit']} in stock.", 'err'); }
    else {
        q('INSERT INTO dead_stock (dead_date, product_id, quantity, purchase_price, total_amount, reason) VALUES (?,?,?,?,?,?)',
          [$date, $pid, $qty, $p['purchase_rate'], round($qty * $p['purchase_rate'], 2), $reason ?: null]);
        q('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?', [$qty, $pid]);
        $pdo->commit(); flash('Dead stock recorded and stock reduced.');
    }
    redirect('dead_stock.php');
}
$prods = rows('SELECT p.id, p.name, p.unit, p.stock_qty, p.purchase_rate, c.name AS cat FROM products p JOIN categories c ON c.id = p.category_id WHERE p.stock_qty > 0 ORDER BY c.name, p.name');
$list = rows('SELECT d.*, p.name, p.unit, c.name AS cat FROM dead_stock d JOIN products p ON p.id = d.product_id JOIN categories c ON c.id = p.category_id ORDER BY d.dead_date DESC, d.id DESC LIMIT 300');
$page_title = 'Dead Stock';
require __DIR__ . '/includes/header.php';
?>
<form method="post" class="card"><?= csrf_field() ?>
<h3>Write off damaged / unsellable stock</h3>
<div class="form">
  <div><label>Date</label><input type="date" name="dead_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
  <div><label>Product</label><select name="product_id" required><option value="">-- select --</option>
    <?php foreach ($prods as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['cat'] . ' · ' . $p['name']) ?> (stock <?= $p['stock_qty'] ?> <?= e($p['unit']) ?>)</option><?php endforeach; ?></select></div>
  <div><label>Quantity</label><input type="number" name="quantity" min="1" step="1" required></div>
  <div><label>Reason</label><input name="reason" maxlength="150" placeholder="Broken in transit, damaged..."></div>
  <div style="align-self:end"><button class="btn">Save</button></div>
</div></form>
<div class="tw"><table>
<tr><th>Dead No</th><th>Date</th><th>Category</th><th>Product</th><th class="r">Qty</th><th class="r">Purchase Price</th><th class="r">Total Loss</th><th>Reason</th></tr>
<?php $t = 0; foreach ($list as $d): $t += $d['total_amount']; ?>
<tr><td><?= $d['id'] ?></td><td><?= dmy($d['dead_date']) ?></td><td><?= e($d['cat']) ?></td><td><?= e($d['name']) ?></td>
<td class="r"><?= $d['quantity'] ?> <?= e($d['unit']) ?></td><td class="r"><?= money($d['purchase_price']) ?></td><td class="r"><?= money($d['total_amount']) ?></td><td><?= e($d['reason']) ?></td></tr>
<?php endforeach; if (!$list): ?><tr><td colspan="8">No dead stock recorded.</td></tr><?php endif; ?>
<?php if ($list): ?><tr><th colspan="6" class="r">Total loss</th><th class="r"><?= money($t) ?></th><th></th></tr><?php endif; ?>
</table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>

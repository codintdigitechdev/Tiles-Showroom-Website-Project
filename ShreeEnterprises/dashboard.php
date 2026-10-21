<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$today = date('Y-m-d'); $m1 = date('Y-m-01');
$s = [
  'Today\'s Sales (bills + cash)' => money(val('SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE invoice_date = ?', [$today]) + val('SELECT COALESCE(SUM(total_amount),0) FROM cash_sales WHERE sale_date = ?', [$today])),
  'This Month Sales (bills + cash)' => money(val('SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE invoice_date >= ?', [$m1]) + val('SELECT COALESCE(SUM(total_amount),0) FROM cash_sales WHERE sale_date >= ?', [$m1])),
  'Customers' => val('SELECT COUNT(*) FROM customers'),
  'Products' => val('SELECT COUNT(*) FROM products'),
  'To Receive (customers)' => money(val('SELECT COALESCE(SUM(total_amount - paid_amount),0) FROM invoices')),
];
if (is_admin()) {
  $s['This Month Purchases'] = money(val('SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE purchase_date >= ?', [$m1]));
  $s['To Pay (suppliers)'] = money(val('SELECT COALESCE(SUM(total_amount - paid_amount),0) FROM purchases'));
  $s['Vendor Commission Pending'] = money(val('SELECT COALESCE(SUM(commission_amount - paid_amount),0) FROM vendor_commissions'));
  $s['Stock Value (cost)'] = money(val('SELECT COALESCE(SUM(stock_qty * purchase_rate),0) FROM products'));
}
$low = rows('SELECT p.name, p.stock_qty, p.unit, c.name AS cat FROM products p JOIN categories c ON c.id = p.category_id WHERE p.stock_qty <= ? ORDER BY p.stock_qty LIMIT 10', [LOW_STOCK_LIMIT]);
$recent = rows('SELECT i.id, i.invoice_date, c.name, i.total_amount, i.paid_amount FROM invoices i JOIN customers c ON c.id = i.customer_id ORDER BY i.id DESC LIMIT 8');
$page_title = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="grid g4" style="margin-bottom:18px">
<?php foreach ($s as $l => $v): ?><div class="stat"><?= e($l) ?><b><?= e($v) ?></b></div><?php endforeach; ?>
</div>
<p><a class="btn" href="sale_new.php">+ New Bill</a> <a class="btn alt" href="cash_sale_new.php">+ Cash Sale</a> <a class="btn alt" href="quotation_new.php">+ Quotation</a> <a class="btn alt" href="receive_payment.php">Receive Payment</a>
<?php if (is_admin()): ?> <a class="btn alt" href="purchase_new.php">+ New Purchase</a><?php endif; ?></p>
<div class="grid g2">
<div class="card"><h3>Low stock (≤ <?= LOW_STOCK_LIMIT ?>)</h3><table>
<tr><th>Product</th><th>Category</th><th class="r">Left</th></tr>
<?php foreach ($low as $p): ?><tr><td><?= e($p['name']) ?></td><td><?= e($p['cat']) ?></td><td class="r"><span class="badge low"><?= $p['stock_qty'] ?> <?= e($p['unit']) ?></span></td></tr>
<?php endforeach; if (!$low): ?><tr><td colspan="3">All products are well stocked.</td></tr><?php endif; ?></table></div>
<div class="card"><h3>Recent bills</h3><table>
<tr><th>Bill No</th><th>Date</th><th>Customer</th><th class="r">Total</th><th class="r">Due</th></tr>
<?php foreach ($recent as $i): ?><tr><td><a href="print_invoice.php?id=<?= $i['id'] ?>"><?= $i['id'] ?></a></td><td><?= dmy($i['invoice_date']) ?></td><td><?= e($i['name']) ?></td><td class="r"><?= money($i['total_amount']) ?></td><td class="r"><?= money($i['total_amount'] - $i['paid_amount']) ?></td></tr>
<?php endforeach; if (!$recent): ?><tr><td colspan="5">No bills yet.</td></tr><?php endif; ?></table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();
$r = $_GET['r'] ?? 'cust_ledger';
$from = $_GET['from'] ?? date('Y-m-01'); $to = $_GET['to'] ?? date('Y-m-d');
$cid = (int)($_GET['cid'] ?? 0); $sid = (int)($_GET['sid'] ?? 0); $vid = (int)($_GET['vid'] ?? 0);
foreach (['from', 'to'] as $dk) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$dk)) $$dk = $dk === 'from' ? date('Y-m-01') : date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$reports = [
  'cust_ledger' => 'Customer Ledger', 'cust_pending' => 'Bill Pending (Receivable)',
  'sup_ledger' => 'Supplier Purchase Entry', 'sup_pending' => 'Purchase Pending (Payable)',
  'cash' => 'Cash Sales (no bill)', 'returns' => 'Goods Returns', 'vendor' => 'Vendor Commission', 'quotes' => 'Quotations',
  'sales' => 'Sales Summary', 'purchases' => 'Purchase Summary', 'itemsales' => 'Item-wise Sales',
];
if (!isset($reports[$r])) $r = 'cust_ledger';
$customers = rows('SELECT id, name FROM customers ORDER BY name');
$suppliers = rows('SELECT id, name FROM suppliers ORDER BY name');
$page_title = 'Reports';
require __DIR__ . '/includes/header.php';
?>
<form class="bar no-print" method="get">
  <div><label>Report</label><select name="r" onchange="this.form.submit()"><?php foreach ($reports as $k => $l): ?><option value="<?= $k ?>" <?= $r === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <?php if (in_array($r, ['cust_ledger', 'cust_pending'])): ?><div><label>Customer</label><select name="cid"><option value="">All</option><?php foreach ($customers as $x): ?><option value="<?= $x['id'] ?>" <?= $cid == $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <?php if (in_array($r, ['sup_ledger', 'sup_pending'])): ?><div><label>Supplier</label><select name="sid"><option value="">All</option><?php foreach ($suppliers as $x): ?><option value="<?= $x['id'] ?>" <?= $sid == $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <?php if ($r === 'vendor'): ?><div><label>Vendor</label><select name="vid"><option value="">All</option><?php foreach (rows('SELECT id, name FROM vendors ORDER BY name') as $x): ?><option value="<?= $x['id'] ?>" <?= $vid == $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn">Show</button><button type="button" class="btn alt" onclick="window.print()">🖨 Print</button>
</form>
<div class="print-sheet" style="padding:0">
<?= shop_head() ?>
<div class="pt"><?= e($reports[$r]) ?> <small style="font-weight:400;color:#333">(<?= dmy($from) ?> to <?= dmy($to) ?>)</small></div>
<?php
function tbl(array $head, array $rowsOut, array $foot = []) {
    echo '<table><tr>'; foreach ($head as $h) echo '<th' . (str_starts_with($h, '>') ? ' class="r"' : '') . '>' . e(ltrim($h, '>')) . '</th>'; echo '</tr>';
    foreach ($rowsOut as $row) { echo '<tr>'; foreach ($row as $i => $c) echo '<td' . (str_starts_with($head[$i], '>') ? ' class="r"' : '') . '>' . e($c) . '</td>'; echo '</tr>'; }
    if (!$rowsOut) echo '<tr><td colspan="' . count($head) . '">No data for this selection.</td></tr>';
    if ($foot) { echo '<tr>'; foreach ($foot as $i => $c) echo '<th' . (str_starts_with($head[$i], '>') ? ' class="r"' : '') . '>' . e($c) . '</th>'; echo '</tr>'; }
    echo '</table>';
}
switch ($r) {
  case 'cust_ledger':
    $sql = 'SELECT i.*, c.name FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.invoice_date BETWEEN ? AND ?' . ($cid ? ' AND i.customer_id = ' . $cid : '') . ' ORDER BY i.customer_id, i.invoice_date';
    $d = rows($sql, [$from, $to]); $o = []; $t = [0, 0, 0];
    foreach ($d as $x) { $b = $x['total_amount'] - $x['paid_amount']; $t[0] += $x['total_amount']; $t[1] += $x['paid_amount']; $t[2] += $b;
      $o[] = [$x['id'], dmy($x['invoice_date']), $x['customer_id'], $x['name'], $x['discount_pct'] + 0 . '%', $x['gst_pct'] + 0 . '%', money($x['paid_amount']), money($b), money($x['total_amount'])]; }
    tbl(['Bill No', 'Date', 'Cust ID', 'Customer', '>Disc', '>GST', '>Paid Bill', '>Balance', '>Total Bill'], $o, ['', '', '', 'Total', '', '', money($t[1]), money($t[2]), money($t[0])]); break;
  case 'cust_pending':
    $d = rows('SELECT i.*, c.name, c.mobile FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.total_amount - i.paid_amount > 0.004 AND i.invoice_date BETWEEN ? AND ?' . ($cid ? ' AND i.customer_id = ' . $cid : '') . ' ORDER BY c.name, i.invoice_date', [$from, $to]);
    $o = []; $t = 0; foreach ($d as $x) { $b = $x['total_amount'] - $x['paid_amount']; $t += $b; $o[] = [$x['id'], dmy($x['invoice_date']), $x['name'], $x['mobile'], money($x['total_amount']), money($x['paid_amount']), money($b)]; }
    tbl(['Bill No', 'Date', 'Customer', 'Mobile', '>Total', '>Paid', '>Balance'], $o, ['', '', '', '', '', 'Total due', money($t)]); break;
  case 'sup_ledger':
    $d = rows('SELECT p.*, s.name FROM purchases p JOIN suppliers s ON s.id = p.supplier_id WHERE p.purchase_date BETWEEN ? AND ?' . ($sid ? ' AND p.supplier_id = ' . $sid : '') . ' ORDER BY s.name, p.purchase_date', [$from, $to]);
    $o = []; $t = [0, 0, 0]; foreach ($d as $x) { $b = $x['total_amount'] - $x['paid_amount']; $t[0] += $x['total_amount']; $t[1] += $x['paid_amount']; $t[2] += $b;
      $o[] = [$x['id'], dmy($x['purchase_date']), $x['name'], $x['discount_pct'] + 0 . '%', $x['gst_pct'] + 0 . '%', money($x['paid_amount']), money($b), money($x['total_amount'])]; }
    tbl(['Purchase ID', 'Date', 'Supplier', '>Disc', '>GST', '>Paid', '>Balance', '>Total'], $o, ['', '', 'Total', '', '', money($t[1]), money($t[2]), money($t[0])]); break;
  case 'sup_pending':
    $d = rows('SELECT p.*, s.name, s.mobile FROM purchases p JOIN suppliers s ON s.id = p.supplier_id WHERE p.total_amount - p.paid_amount > 0.004 AND p.purchase_date BETWEEN ? AND ?' . ($sid ? ' AND p.supplier_id = ' . $sid : '') . ' ORDER BY s.name, p.purchase_date', [$from, $to]);
    $o = []; $t = 0; foreach ($d as $x) { $b = $x['total_amount'] - $x['paid_amount']; $t += $b; $o[] = [$x['id'], dmy($x['purchase_date']), $x['name'], $x['mobile'], money($x['total_amount']), money($x['paid_amount']), money($b)]; }
    tbl(['Purchase', 'Date', 'Supplier', 'Mobile', '>Total', '>Paid', '>Balance'], $o, ['', '', '', '', '', 'Total payable', money($t)]); break;
  case 'cash':
    $d = rows('SELECT * FROM cash_sales WHERE sale_date BETWEEN ? AND ? ORDER BY sale_date, id', [$from, $to]); $o = []; $t = 0;
    foreach ($d as $x) { $t += $x['total_amount']; $o[] = [$x['id'], dmy($x['sale_date']), $x['customer_name'] ?: 'Walk-in', $x['mobile'], $x['pay_mode'], money($x['total_amount'])]; }
    tbl(['Cash Sale No', 'Date', 'Customer', 'Mobile', 'Mode', '>Total'], $o, ['', '', '', '', 'Total', money($t)]); break;
  case 'returns':
    $d = rows('SELECT r.*, c.name FROM sales_returns r JOIN customers c ON c.id = r.customer_id WHERE r.return_date BETWEEN ? AND ? ORDER BY r.return_date, r.id', [$from, $to]); $o = []; $t = [0, 0, 0];
    foreach ($d as $x) { $t[0] += $x['total_amount']; $t[1] += $x['adjusted_amount']; $t[2] += $x['refund_amount']; $o[] = [$x['id'], dmy($x['return_date']), $x['name'], $x['invoice_id'], money($x['total_amount']), money($x['adjusted_amount']), money($x['refund_amount'])]; }
    tbl(['Return No', 'Date', 'Customer', 'Bill No', '>Value', '>Adjusted', '>Refund'], $o, ['', '', '', 'Total', money($t[0]), money($t[1]), money($t[2])]); break;
  case 'vendor':
    $d = rows('SELECT vc.*, v.name AS vendor, i.invoice_date, c.name AS customer FROM vendor_commissions vc JOIN vendors v ON v.id = vc.vendor_id JOIN invoices i ON i.id = vc.invoice_id JOIN customers c ON c.id = i.customer_id WHERE i.invoice_date BETWEEN ? AND ?' . ($vid ? ' AND vc.vendor_id = ' . $vid : '') . ' ORDER BY v.name, i.invoice_date', [$from, $to]);
    $o = []; $t = [0, 0]; foreach ($d as $x) { $t[0] += $x['commission_amount']; $t[1] += $x['paid_amount']; $o[] = [$x['vendor'], $x['invoice_id'], dmy($x['invoice_date']), $x['customer'], ($x['commission_pct'] + 0) . '%', money($x['commission_amount']), money($x['paid_amount']), money($x['commission_amount'] - $x['paid_amount'])]; }
    tbl(['Vendor', 'Bill No', 'Date', 'Customer', '>%', '>Commission', '>Paid', '>Pending'], $o, ['', '', '', '', 'Total', money($t[0]), money($t[1]), money($t[0] - $t[1])]); break;
  case 'quotes':
    $d = rows('SELECT * FROM quotations WHERE quote_date BETWEEN ? AND ? ORDER BY quote_date, id', [$from, $to]); $o = []; $t = 0;
    foreach ($d as $x) { $t += $x['total_amount']; $o[] = [$x['id'], dmy($x['quote_date']), $x['customer_name'], $x['mobile'], dmy($x['valid_till']), money($x['total_amount'])]; }
    tbl(['Quotation No', 'Date', 'Customer', 'Mobile', 'Valid Till', '>Total'], $o, ['', '', '', '', 'Total (not sales)', money($t)]); break;
  case 'sales':
    $d = rows('SELECT invoice_date AS d, COUNT(*) n, SUM(total_amount) t, SUM(paid_amount) p FROM invoices WHERE invoice_date BETWEEN ? AND ? GROUP BY invoice_date ORDER BY invoice_date', [$from, $to]);
    $o = []; $t = [0, 0, 0]; foreach ($d as $x) { $t[0] += $x['n']; $t[1] += $x['t']; $t[2] += $x['p']; $o[] = [dmy($x['d']), $x['n'], money($x['t']), money($x['p']), money($x['t'] - $x['p'])]; }
    tbl(['Date', '>Bills', '>Sales', '>Collected', '>Pending'], $o, ['Total', $t[0], money($t[1]), money($t[2]), money($t[1] - $t[2])]); break;
  case 'purchases':
    $d = rows('SELECT purchase_date AS d, COUNT(*) n, SUM(total_amount) t, SUM(paid_amount) p FROM purchases WHERE purchase_date BETWEEN ? AND ? GROUP BY purchase_date ORDER BY purchase_date', [$from, $to]);
    $o = []; $t = [0, 0, 0]; foreach ($d as $x) { $t[0] += $x['n']; $t[1] += $x['t']; $t[2] += $x['p']; $o[] = [dmy($x['d']), $x['n'], money($x['t']), money($x['p']), money($x['t'] - $x['p'])]; }
    tbl(['Date', '>Bills', '>Purchases', '>Paid', '>Payable'], $o, ['Total', $t[0], money($t[1]), money($t[2]), money($t[1] - $t[2])]); break;
  case 'itemsales':
    $d = rows('SELECT pr.name, c.name AS cat, SUM(ii.quantity) q, SUM(ii.line_total) t FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id JOIN products pr ON pr.id = ii.product_id JOIN categories c ON c.id = pr.category_id WHERE i.invoice_date BETWEEN ? AND ? GROUP BY pr.id, pr.name, c.name ORDER BY t DESC', [$from, $to]);
    $o = []; $t = 0; foreach ($d as $x) { $t += $x['t']; $o[] = [$x['cat'], $x['name'], $x['q'], money($x['t'])]; }
    tbl(['Category', 'Product', '>Qty Sold', '>Sales (before disc/GST)'], $o, ['', '', 'Total', money($t)]); break;
}
?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

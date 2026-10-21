<?php
// Vendor commissions: summary per vendor + pending commission list.
require_once __DIR__ . '/includes/functions.php';
require_admin();
$vid = (int)($_GET['vid'] ?? 0);
$status = $_GET['status'] ?? 'pending';
$page_title = 'Vendor Commissions';
require __DIR__ . '/includes/header.php';

$sum = rows('SELECT v.id, v.name, v.mobile, v.commission_pct, COUNT(vc.id) AS bills, COALESCE(SUM(vc.commission_amount),0) AS comm, COALESCE(SUM(vc.paid_amount),0) AS paid
             FROM vendors v LEFT JOIN vendor_commissions vc ON vc.vendor_id = v.id GROUP BY v.id, v.name, v.mobile, v.commission_pct ORDER BY (COALESCE(SUM(vc.commission_amount),0) - COALESCE(SUM(vc.paid_amount),0)) DESC, v.name');
$sql = 'SELECT vc.*, v.name AS vendor, i.invoice_date, i.total_amount, c.name AS customer FROM vendor_commissions vc
        JOIN vendors v ON v.id = vc.vendor_id JOIN invoices i ON i.id = vc.invoice_id JOIN customers c ON c.id = i.customer_id WHERE 1=1';
$pr = [];
if ($vid) { $sql .= ' AND vc.vendor_id = ?'; $pr[] = $vid; }
if ($status === 'pending') $sql .= ' AND vc.commission_amount - vc.paid_amount > 0.004';
if ($status === 'paid') $sql .= ' AND vc.commission_amount - vc.paid_amount <= 0.004';
$list = rows($sql . ' ORDER BY i.invoice_date, vc.id', $pr);
$tC = $tP = 0;
?>
<p><a class="btn alt" href="master.php?m=vendors">Manage Vendors</a></p>
<h3>Vendor summary</h3>
<div class="tw"><table>
<tr><th>Vendor</th><th>Mobile</th><th class="r">Default %</th><th class="r">Bills</th><th class="r">Total Commission</th><th class="r">Paid</th><th class="r">Pending</th><th></th></tr>
<?php foreach ($sum as $s): $tC += $s['comm']; $tP += $s['paid']; ?>
<tr><td><?= e($s['name']) ?></td><td><?= e($s['mobile']) ?></td><td class="r"><?= $s['commission_pct'] + 0 ?>%</td><td class="r"><?= $s['bills'] ?></td>
<td class="r"><?= money($s['comm']) ?></td><td class="r"><?= money($s['paid']) ?></td><td class="r"><b><?= money($s['comm'] - $s['paid']) ?></b></td>
<td style="white-space:nowrap"><a class="btn sm" href="vendor_detail.php?id=<?= $s['id'] ?>">Details / Pay</a></td></tr>
<?php endforeach; if (!$sum): ?><tr><td colspan="8">No vendors yet. Add one in Vendors.</td></tr><?php endif; ?>
<?php if ($sum): ?><tr><th colspan="4" class="r">Total</th><th class="r"><?= money($tC) ?></th><th class="r"><?= money($tP) ?></th><th class="r"><?= money($tC - $tP) ?></th><th></th></tr><?php endif; ?>
</table></div>

<h3 style="margin-top:22px">Commission per bill</h3>
<form class="bar" method="get">
  <div><label>Vendor</label><select name="vid"><option value="">All</option><?php foreach ($sum as $s): ?><option value="<?= $s['id'] ?>" <?= $vid == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>Status</label><select name="status"><option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option><option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Fully paid</option><option value="" <?= $status === '' ? 'selected' : '' ?>>All</option></select></div>
  <button class="btn alt">Refresh</button>
</form>
<div class="tw"><table>
<tr><th>Bill No</th><th>Date</th><th>Vendor</th><th>Customer</th><th class="r">Bill Total</th><th class="r">%</th><th class="r">Commission</th><th class="r">Paid</th><th class="r">Pending</th></tr>
<?php $a = $b = 0; foreach ($list as $x): $pend = $x['commission_amount'] - $x['paid_amount']; $a += $x['commission_amount']; $b += $pend; ?>
<tr><td><a href="print_invoice.php?id=<?= $x['invoice_id'] ?>" target="_blank">#<?= $x['invoice_id'] ?></a></td><td><?= dmy($x['invoice_date']) ?></td><td><?= e($x['vendor']) ?></td><td><?= e($x['customer']) ?></td>
<td class="r"><?= money($x['total_amount']) ?></td><td class="r"><?= $x['commission_pct'] + 0 ?>%</td><td class="r"><?= money($x['commission_amount']) ?></td><td class="r"><?= money($x['paid_amount']) ?></td><td class="r"><?= money($pend) ?></td></tr>
<?php endforeach; if (!$list): ?><tr><td colspan="9">No commission entries for this selection.</td></tr><?php endif; ?>
<?php if ($list): ?><tr><th colspan="6" class="r">Total</th><th class="r"><?= money($a) ?></th><th></th><th class="r"><?= money($b) ?></th></tr><?php endif; ?>
</table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>

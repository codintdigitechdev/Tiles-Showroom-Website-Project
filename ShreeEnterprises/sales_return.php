<?php
// Goods return from regular customers (against one of their bills).
require_once __DIR__ . '/includes/functions.php';
require_login();
$cid = (int)($_GET['cid'] ?? $_POST['cid'] ?? 0);
$inv = (int)($_GET['inv'] ?? $_POST['inv'] ?? 0);

/** quantity of each bill line already returned */
function returned_map(int $invoiceId): array {
    $m = [];
    foreach (rows('SELECT product_id, SUM(quantity) q FROM sales_return_items ri JOIN sales_returns r ON r.id = ri.return_id WHERE r.invoice_id = ? GROUP BY product_id', [$invoiceId]) as $r) $m[$r['product_id']] = (int)$r['q'];
    return $m;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $back = "sales_return.php?cid=$cid&inv=$inv";
    $pdo = db(); $inTx = false;
    try {
        $date = v_date($_POST['return_date'] ?? '', 'Return date', false);
        $reason = v_text($_POST['reason'] ?? '', 'Reason', 150);
        $settle = $_POST['settle'] ?? 'adjust';
        if (!in_array($settle, ['adjust', 'refund'], true)) throw new RuntimeException('Select how the return is settled.');
        $refundMode = $_POST['refund_mode'] ?? 'Cash';
        if (!in_array($refundMode, PAY_MODES, true)) throw new RuntimeException('Select a valid refund mode.');
        $pdo->beginTransaction(); $inTx = true;
        $bill = row('SELECT * FROM invoices WHERE id = ? AND customer_id = ? FOR UPDATE', [$inv, $cid]);
        if (!$bill) throw new RuntimeException('Select a customer and one of their bills.');
        $sold = []; // product_id => [qty, rate]
        foreach (rows('SELECT product_id, SUM(quantity) q, MAX(sales_rate) rate FROM invoice_items WHERE invoice_id = ? GROUP BY product_id', [$inv]) as $r) $sold[$r['product_id']] = $r;
        $done = returned_map($inv);
        $lines = []; $net = 0.0;
        foreach (($_POST['qty'] ?? []) as $pid => $qraw) {
            $pid = (int)$pid;
            if (trim((string)$qraw) === '' || trim((string)$qraw) === '0') continue;
            $name = val('SELECT name FROM products WHERE id = ?', [$pid]) ?? "#$pid";
            $qty = (int)v_num($qraw, "Return quantity of $name", 1, 100000, true);
            if (!isset($sold[$pid])) throw new RuntimeException("$name is not on this bill.");
            $avail = (int)$sold[$pid]['q'] - ($done[$pid] ?? 0);
            if ($qty > $avail) throw new RuntimeException("$name: only $avail can be returned (sold {$sold[$pid]['q']}, already returned " . ($done[$pid] ?? 0) . ').');
            $rate = (float)$sold[$pid]['rate'];
            $lines[] = ['pid' => $pid, 'qty' => $qty, 'rate' => $rate, 'line' => round($qty * $rate, 2), 'restock' => empty($_POST['dmg'][$pid]) ? 1 : 0];
            $net += $qty * $rate;
        }
        if (!$lines) throw new RuntimeException('Enter the quantity returned for at least one product.');
        $base = round($net * (1 - $bill['discount_pct'] / 100), 2);
        $total = round($base * (1 + $bill['gst_pct'] / 100), 2);
        $balance = round($bill['total_amount'] - $bill['paid_amount'], 2);
        $applied = $settle === 'adjust' ? round(min($total, max($balance, 0)), 2) : 0.0;
        $refund = round($total - $applied, 2);

        q('INSERT INTO sales_returns (return_date, customer_id, invoice_id, total_amount, adjusted_amount, refund_amount, refund_mode, reason, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
          [$date, $cid, $inv, $total, $applied, $refund, $refund > 0 ? $refundMode : null, $reason, user()['id']]);
        $rid = db()->lastInsertId();
        foreach ($lines as $l) {
            q('INSERT INTO sales_return_items (return_id, product_id, quantity, rate, line_total, restock) VALUES (?,?,?,?,?,?)', [$rid, $l['pid'], $l['qty'], $l['rate'], $l['line'], $l['restock']]);
            if ($l['restock']) q('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?', [$l['qty'], $l['pid']]);
        }
        if ($applied > 0) {
            q('INSERT INTO invoice_payments (invoice_id, pay_date, amount, mode) VALUES (?,?,?,?)', [$inv, $date, $applied, 'Return Credit']);
            q('UPDATE invoices SET paid_amount = paid_amount + ? WHERE id = ?', [$applied, $inv]);
        }
        // vendor commission shrinks with the returned goods (never below what is already paid)
        $vc = row('SELECT * FROM vendor_commissions WHERE invoice_id = ? FOR UPDATE', [$inv]);
        if ($vc) {
            $cut = round($base * $vc['commission_pct'] / 100, 2);
            $newC = max(round($vc['commission_amount'] - $cut, 2), (float)$vc['paid_amount']);
            q('UPDATE vendor_commissions SET commission_amount = ?, base_amount = GREATEST(base_amount - ?, 0) WHERE id = ?', [$newC, $base, $vc['id']]);
        }
        $pdo->commit();
        flash("Return #$rid saved. Credit ₹ " . number_format($applied, 2) . ' adjusted on the bill' . ($refund > 0 ? ', refund due ₹ ' . number_format($refund, 2) : '') . '.');
        redirect("sales_return.php?print=$rid");
    } catch (Throwable $ex) {
        if ($inTx) $pdo->rollBack();
        flash($ex instanceof RuntimeException ? $ex->getMessage() : 'Could not save the return.', 'err');
        redirect($back);
    }
}

$page_title = 'Goods Return (Regular Customers)';
require __DIR__ . '/includes/header.php';

// ---- printable return slip
if ($pid_ = (int)($_GET['print'] ?? 0)):
    $r = row('SELECT r.*, c.name, c.mobile, c.address FROM sales_returns r JOIN customers c ON c.id = r.customer_id WHERE r.id = ?', [$pid_]);
    if (!$r) { echo '<div class="card">Return not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; }
    $its = rows('SELECT p.name, p.unit, i.* FROM sales_return_items i JOIN products p ON p.id = i.product_id WHERE i.return_id = ?', [$pid_]);
?>
<p class="no-print"><button class="btn" onclick="window.print()">🖨 Print</button> <a class="btn alt" href="sales_return.php">Back</a></p>
<div class="print-sheet">
  <?= shop_head() ?>
  <div class="pt">Goods Return Note</div>
  <div class="pm"><div><b>Return No:</b> <?= $r['id'] ?><br><b>Against Bill No:</b> <?= $r['invoice_id'] ?><br><b>Customer:</b> <span style="color:#8a0000;font-weight:700"><?= e($r['name']) ?></span></div>
    <div style="text-align:right"><b>Mobile:</b> <?= e($r['mobile']) ?><br><b>Date:</b> <?= dmy($r['return_date']) ?></div></div>
  <table><tr><th>Product</th><th class="r">Qty</th><th>Unit</th><th class="r">Rate</th><th class="r">Amount</th><th>Condition</th></tr>
  <?php foreach ($its as $i): ?><tr><td><?= e($i['name']) ?></td><td class="r"><?= $i['quantity'] ?></td><td><?= e($i['unit']) ?></td><td class="r"><?= money($i['rate']) ?></td><td class="r"><?= money($i['line_total']) ?></td><td><?= $i['restock'] ? 'Good (restocked)' : 'Damaged' ?></td></tr><?php endforeach; ?></table>
  <div style="padding:10px 14px;border-top:1px solid #333"><div class="totals" style="margin:0 0 0 auto">
    <div class="grand"><span>Return Value (after disc + GST)</span><span><?= money($r['total_amount']) ?></span></div>
    <div><span>Adjusted on bill</span><span><?= money($r['adjusted_amount']) ?></span></div>
    <div><span>Refund<?= $r['refund_mode'] ? ' (' . e($r['refund_mode']) . ')' : '' ?></span><span><?= money($r['refund_amount']) ?></span></div></div>
    <?php if ($r['reason']): ?><div><b>Reason:</b> <?= e($r['reason']) ?></div><?php endif; ?></div>
  <div class="sig"><div>Customer Signatory</div><div>Authorised Signature</div></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; exit; endif;

$customers = rows('SELECT id, name, mobile FROM customers ORDER BY name');
$bills = $cid ? rows('SELECT id, invoice_date, total_amount FROM invoices WHERE customer_id = ? ORDER BY invoice_date DESC, id DESC', [$cid]) : [];
?>
<form class="bar" method="get">
  <div style="min-width:260px"><label>Customer *</label><select name="cid" onchange="this.form.inv.value='';this.form.submit()"><option value="">-- select --</option>
    <?php foreach ($customers as $x): ?><option value="<?= $x['id'] ?>" <?= $cid == $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?> · <?= e($x['mobile']) ?></option><?php endforeach; ?></select></div>
  <?php if ($cid): ?><div style="min-width:260px"><label>Bill *</label><select name="inv" onchange="this.form.submit()"><option value="">-- select bill --</option>
    <?php foreach ($bills as $b): ?><option value="<?= $b['id'] ?>" <?= $inv == $b['id'] ? 'selected' : '' ?>>Bill No <?= $b['id'] ?> · <?= dmy($b['invoice_date']) ?> · <?= number_format($b['total_amount'], 2) ?></option><?php endforeach; ?></select></div><?php endif; ?>
</form>
<?php
$bill = ($cid && $inv) ? row('SELECT * FROM invoices WHERE id = ? AND customer_id = ?', [$inv, $cid]) : null;
if ($bill):
    $items = rows('SELECT ii.product_id, p.name, p.unit, SUM(ii.quantity) q, MAX(ii.sales_rate) rate FROM invoice_items ii JOIN products p ON p.id = ii.product_id WHERE ii.invoice_id = ? GROUP BY ii.product_id, p.name, p.unit', [$inv]);
    $done = returned_map($inv);
    $balance = round($bill['total_amount'] - $bill['paid_amount'], 2);
?>
<form method="post" class="card" id="retform" novalidate><?= csrf_field() ?><input type="hidden" name="cid" value="<?= $cid ?>"><input type="hidden" name="inv" value="<?= $inv ?>">
  <h3>Return against Bill No <?= $inv ?> <small style="font-weight:400">(discount <?= $bill['discount_pct'] + 0 ?>%, GST <?= $bill['gst_pct'] + 0 ?>%, balance due <?= money($balance) ?>)</small></h3>
  <div class="tw"><table>
  <tr><th>Product</th><th class="r">Sold</th><th class="r">Already Returned</th><th class="r">Rate</th><th style="width:120px">Return Qty</th><th>Damaged? (no restock)</th></tr>
  <?php foreach ($items as $i): $avail = $i['q'] - ($done[$i['product_id']] ?? 0); ?>
  <tr><td><?= e($i['name']) ?></td><td class="r"><?= $i['q'] ?> <?= e($i['unit']) ?></td><td class="r"><?= $done[$i['product_id']] ?? 0 ?></td><td class="r"><?= money($i['rate']) ?></td>
    <td><input type="number" name="qty[<?= $i['product_id'] ?>]" min="0" max="<?= $avail ?>" step="1" value="0" data-rate="<?= $i['rate'] ?>" <?= $avail <= 0 ? 'disabled' : '' ?>></td>
    <td><label style="font-weight:400"><input type="checkbox" name="dmg[<?= $i['product_id'] ?>]" value="1" style="width:auto"> Damaged</label></td></tr>
  <?php endforeach; ?></table></div>
  <div class="form" style="margin-top:14px">
    <div><label>Return Date *</label><input type="date" name="return_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
    <div><label>Settlement *</label><select name="settle" id="settle"><option value="adjust">Adjust against bill balance (refund any excess)</option><option value="refund">Refund full amount</option></select></div>
    <div><label>Refund Mode</label><select name="refund_mode"><?php foreach (PAY_MODES as $m): ?><option><?= $m ?></option><?php endforeach; ?></select></div>
    <div><label>Reason</label><input name="reason" maxlength="150" placeholder="Wrong size, broken, colour mismatch..."></div>
  </div>
  <p>Return value (after discount + GST): <b id="rv">₹ 0.00</b></p>
  <button class="btn">Save Return</button> <a class="btn alt" href="sales_return.php">Cancel</a>
</form>
<script>
(function () {
  var d = <?= (float)$bill['discount_pct'] ?>, g = <?= (float)$bill['gst_pct'] ?>;
  var ins = document.querySelectorAll('input[name^="qty["]');
  function calc() { var s = 0; ins.forEach(function (i) { s += (parseInt(i.value, 10) || 0) * parseFloat(i.dataset.rate); }); document.getElementById('rv').textContent = '₹ ' + (s * (1 - d / 100) * (1 + g / 100)).toFixed(2); }
  ins.forEach(function (i) { i.oninput = calc; });
  document.getElementById('retform').onsubmit = function (ev) {
    var any = false;
    for (var k = 0; k < ins.length; k++) {
      var i = ins[k], v = i.value.trim();
      if (v === '') continue;
      if (!/^\d+$/.test(v)) { ev.preventDefault(); return alert('Return quantity must be a whole number.'); }
      if (parseInt(v, 10) > parseInt(i.max, 10)) { ev.preventDefault(); return alert('Return quantity cannot be more than ' + i.max + '.'); }
      if (parseInt(v, 10) > 0) any = true;
    }
    if (!any) { ev.preventDefault(); alert('Enter the quantity returned for at least one product.'); }
  };
})();
</script>
<?php elseif ($cid): ?><div class="card">Select a bill to return goods against.</div><?php endif; ?>

<h3 style="margin-top:22px">Recent returns</h3>
<div class="tw"><table>
<tr><th>Return No</th><th>Date</th><th>Customer</th><th>Bill No</th><th class="r">Value</th><th class="r">Adjusted</th><th class="r">Refund</th><th>Reason</th><th></th></tr>
<?php foreach (rows('SELECT r.*, c.name FROM sales_returns r JOIN customers c ON c.id = r.customer_id ORDER BY r.id DESC LIMIT 100') as $r): ?>
<tr><td>#<?= $r['id'] ?></td><td><?= dmy($r['return_date']) ?></td><td><?= e($r['name']) ?></td><td>#<?= $r['invoice_id'] ?></td><td class="r"><?= money($r['total_amount']) ?></td><td class="r"><?= money($r['adjusted_amount']) ?></td><td class="r"><?= money($r['refund_amount']) ?></td><td><?= e($r['reason']) ?></td><td><a class="btn sm alt" href="sales_return.php?print=<?= $r['id'] ?>">Print</a></td></tr>
<?php endforeach; ?></table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>

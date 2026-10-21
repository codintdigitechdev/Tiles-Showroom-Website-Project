<?php
// Remaining payment from regular customers: accept a part / full payment against a customer's pending bills.
require_once __DIR__ . '/includes/functions.php';
require_login();
$cid = (int)($_GET['cid'] ?? $_POST['cid'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $back = 'receive_payment.php?cid=' . $cid;
    $pdo = db(); $inTx = false;
    try {
        if (!val('SELECT COUNT(*) FROM customers WHERE id = ?', [$cid])) throw new RuntimeException('Select a customer.');
        $amt = v_num($_POST['amount'] ?? '', 'Amount', 0.01, 100000000);
        $date = v_date($_POST['pay_date'] ?? '', 'Payment date', false);
        $mode = $_POST['pay_mode'] ?? '';
        if (!in_array($mode, PAY_MODES, true)) throw new RuntimeException('Select a valid payment mode.');
        $only = (int)($_POST['invoice_id'] ?? 0); // 0 = oldest bill first

        $pdo->beginTransaction(); $inTx = true;
        $sql = 'SELECT id, total_amount, paid_amount FROM invoices WHERE customer_id = ? AND total_amount - paid_amount > 0.004' . ($only ? ' AND id = ?' : '') . ' ORDER BY invoice_date, id FOR UPDATE';
        $bills = rows($sql, $only ? [$cid, $only] : [$cid]);
        if (!$bills) throw new RuntimeException('No pending bill found for this selection.');
        $due = 0; foreach ($bills as $b) $due += round($b['total_amount'] - $b['paid_amount'], 2);
        if ($amt > $due + 0.005) throw new RuntimeException('Amount cannot be more than the pending balance (' . number_format($due, 2) . ').');
        $left = $amt; $touched = [];
        foreach ($bills as $b) {
            if ($left <= 0.004) break;
            $bal = round($b['total_amount'] - $b['paid_amount'], 2);
            $take = round(min($bal, $left), 2);
            q('INSERT INTO invoice_payments (invoice_id, pay_date, amount, mode) VALUES (?,?,?,?)', [$b['id'], $date, $take, $mode]);
            q('UPDATE invoices SET paid_amount = paid_amount + ? WHERE id = ?', [$take, $b['id']]);
            $touched[] = '#' . $b['id'] . ' (' . number_format($take, 2) . ')';
            $left = round($left - $take, 2);
        }
        $pdo->commit();
        flash('Received ₹ ' . number_format($amt, 2) . ' against bill ' . implode(', ', $touched) . '.');
    } catch (Throwable $ex) {
        if ($inTx) $pdo->rollBack();
        flash($ex instanceof RuntimeException ? $ex->getMessage() : 'Could not save the payment.', 'err');
    }
    redirect($back);
}

$page_title = 'Receive Remaining Payment';
require __DIR__ . '/includes/header.php';

$summary = rows('SELECT c.id, c.name, c.mobile, COUNT(*) AS n, SUM(i.total_amount - i.paid_amount) AS due
                 FROM invoices i JOIN customers c ON c.id = i.customer_id
                 WHERE i.total_amount - i.paid_amount > 0.004 GROUP BY c.id, c.name, c.mobile ORDER BY due DESC');
$cust = $cid ? row('SELECT * FROM customers WHERE id = ?', [$cid]) : null;
?>
<form class="bar" method="get">
  <div style="min-width:280px"><label>Customer</label><select name="cid" onchange="this.form.submit()"><option value="">-- select customer --</option>
  <?php foreach (rows('SELECT id, name, mobile FROM customers ORDER BY name') as $x): ?><option value="<?= $x['id'] ?>" <?= $cid == $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?> · <?= e($x['mobile']) ?></option><?php endforeach; ?></select></div>
</form>

<?php if ($cust):
    $bills = rows('SELECT i.*, (i.total_amount - i.paid_amount) AS bal FROM invoices i WHERE i.customer_id = ? ORDER BY i.invoice_date DESC, i.id DESC', [$cid]);
    $pending = array_values(array_filter($bills, fn($b) => $b['bal'] > 0.004));
    $totDue = array_sum(array_column($pending, 'bal'));
    $hist = rows('SELECT p.*, i.invoice_date FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id WHERE i.customer_id = ? ORDER BY p.pay_date DESC, p.id DESC LIMIT 30', [$cid]);
?>
<div class="grid g4" style="margin-bottom:14px">
  <div class="stat"><?= e($cust['name']) ?><b><?= e($cust['mobile']) ?></b></div>
  <div class="stat warn">Total Remaining<b><?= money($totDue) ?></b></div>
  <div class="stat">Pending Bills<b><?= count($pending) ?></b></div>
</div>
<?php if ($pending): ?>
<form method="post" class="card" id="payform" novalidate><?= csrf_field() ?><input type="hidden" name="cid" value="<?= $cid ?>">
  <h3>Accept Payment</h3>
  <div class="form">
    <div><label>Apply to</label><select name="invoice_id" id="inv"><option value="" data-bal="<?= $totDue ?>">All pending bills (oldest first)</option>
      <?php foreach ($pending as $b): ?><option value="<?= $b['id'] ?>" data-bal="<?= round($b['bal'], 2) ?>">Bill No <?= $b['id'] ?> · <?= dmy($b['invoice_date']) ?> · due <?= number_format($b['bal'], 2) ?></option><?php endforeach; ?></select></div>
    <div><label>Amount (₹) * <small id="maxhint"></small></label><input type="number" name="amount" id="amt" step="0.01" min="0.01" required></div>
    <div><label>Date *</label><input type="date" name="pay_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
    <div><label>Mode *</label><select name="pay_mode"><?php foreach (PAY_MODES as $m): ?><option><?= $m ?></option><?php endforeach; ?></select></div>
    <div style="align-self:end"><button class="btn">Receive Payment</button></div>
  </div>
</form>
<script>
(function () {
  var inv = document.getElementById('inv'), amt = document.getElementById('amt'), hint = document.getElementById('maxhint');
  function max() { return parseFloat(inv.options[inv.selectedIndex].dataset.bal) || 0; }
  function sync() { amt.max = max(); amt.value = max().toFixed(2); hint.textContent = '(max ' + max().toFixed(2) + ')'; }
  inv.onchange = sync; sync();
  document.getElementById('payform').onsubmit = function (ev) {
    var v = amt.value.trim();
    if (!/^\d+(\.\d{1,2})?$/.test(v) || parseFloat(v) <= 0) { ev.preventDefault(); return alert('Enter a valid amount greater than zero (max 2 decimals).'); }
    if (parseFloat(v) > max() + 0.005) { ev.preventDefault(); return alert('Amount cannot be more than ' + max().toFixed(2) + '.'); }
  };
})();
</script>
<?php else: ?><div class="card">This customer has no pending balance. 🎉</div><?php endif; ?>

<h3>Bills of <?= e($cust['name']) ?></h3>
<div class="tw"><table>
<tr><th>Bill No</th><th>Date</th><th class="r">Total</th><th class="r">Paid</th><th class="r">Balance</th><th>Status</th><th></th></tr>
<?php foreach ($bills as $b): ?>
<tr><td>#<?= $b['id'] ?></td><td><?= dmy($b['invoice_date']) ?></td><td class="r"><?= money($b['total_amount']) ?></td><td class="r"><?= money($b['paid_amount']) ?></td><td class="r"><?= money($b['bal']) ?></td>
<td><span class="badge <?= $b['bal'] > 0.004 ? 'due' : 'paid' ?>"><?= $b['bal'] > 0.004 ? 'Pending' : 'Paid' ?></span></td><td><a class="btn sm alt" href="print_invoice.php?id=<?= $b['id'] ?>" target="_blank">Print</a></td></tr>
<?php endforeach; if (!$bills): ?><tr><td colspan="7">No bills yet.</td></tr><?php endif; ?></table></div>

<h3 style="margin-top:22px">Payment history (latest 30)</h3>
<div class="tw"><table>
<tr><th>Date</th><th>Bill No</th><th>Mode</th><th class="r">Amount</th></tr>
<?php foreach ($hist as $h): ?><tr><td><?= dmy($h['pay_date']) ?></td><td>#<?= $h['invoice_id'] ?></td><td><?= e($h['mode']) ?></td><td class="r"><?= money($h['amount']) ?></td></tr>
<?php endforeach; if (!$hist): ?><tr><td colspan="4">No payments yet.</td></tr><?php endif; ?></table></div>

<?php else: ?>
<h3>Customers with remaining payment</h3>
<div class="tw"><table>
<tr><th>Customer</th><th>Mobile</th><th class="r">Pending Bills</th><th class="r">Remaining</th><th></th></tr>
<?php $t = 0; foreach ($summary as $s): $t += $s['due']; ?>
<tr><td><?= e($s['name']) ?></td><td><?= e($s['mobile']) ?></td><td class="r"><?= $s['n'] ?></td><td class="r"><?= money($s['due']) ?></td><td><a class="btn sm" href="receive_payment.php?cid=<?= $s['id'] ?>">Receive</a></td></tr>
<?php endforeach; if (!$summary): ?><tr><td colspan="5">No customer has a pending balance.</td></tr><?php endif; ?>
<?php if ($summary): ?><tr><th colspan="3" class="r">Total remaining</th><th class="r"><?= money($t) ?></th><th></th></tr><?php endif; ?>
</table></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

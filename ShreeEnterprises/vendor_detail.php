<?php
// One vendor: all referred billing details, commission, payments made, and the pay-commission form.
require_once __DIR__ . '/includes/functions.php';
require_admin();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$vendor = row('SELECT * FROM vendors WHERE id = ?', [$id]);
if (!$vendor) { flash('Vendor not found.', 'err'); redirect('vendor_commissions.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pdo = db(); $inTx = false;
    try {
        $amt = v_num($_POST['amount'] ?? '', 'Amount', 0.01, 100000000);
        $date = v_date($_POST['pay_date'] ?? '', 'Payment date', false);
        $mode = $_POST['pay_mode'] ?? '';
        if (!in_array($mode, PAY_MODES, true)) throw new RuntimeException('Select a valid payment mode.');
        $note = v_text($_POST['note'] ?? '', 'Note', 150);
        $only = (int)($_POST['commission_id'] ?? 0);
        $pdo->beginTransaction(); $inTx = true;
        $sql = 'SELECT vc.id, vc.commission_amount, vc.paid_amount FROM vendor_commissions vc JOIN invoices i ON i.id = vc.invoice_id
                WHERE vc.vendor_id = ? AND vc.commission_amount - vc.paid_amount > 0.004' . ($only ? ' AND vc.id = ?' : '') . ' ORDER BY i.invoice_date, vc.id FOR UPDATE';
        $coms = rows($sql, $only ? [$id, $only] : [$id]);
        if (!$coms) throw new RuntimeException('No pending commission found for this selection.');
        $due = 0; foreach ($coms as $x) $due += round($x['commission_amount'] - $x['paid_amount'], 2);
        if ($amt > $due + 0.005) throw new RuntimeException('Amount cannot be more than the pending commission (' . number_format($due, 2) . ').');
        $left = $amt;
        foreach ($coms as $x) {
            if ($left <= 0.004) break;
            $take = round(min(round($x['commission_amount'] - $x['paid_amount'], 2), $left), 2);
            q('INSERT INTO vendor_payments (vendor_id, commission_id, pay_date, amount, mode, note) VALUES (?,?,?,?,?,?)', [$id, $x['id'], $date, $take, $mode, $note]);
            q('UPDATE vendor_commissions SET paid_amount = paid_amount + ? WHERE id = ?', [$take, $x['id']]);
            $left = round($left - $take, 2);
        }
        $pdo->commit();
        flash('Commission payment of ₹ ' . number_format($amt, 2) . ' recorded.');
    } catch (Throwable $ex) {
        if ($inTx) $pdo->rollBack();
        flash($ex instanceof RuntimeException ? $ex->getMessage() : 'Could not save the payment.', 'err');
    }
    redirect('vendor_detail.php?id=' . $id);
}

$bills = rows('SELECT i.id, i.invoice_date, i.total_amount, i.paid_amount, c.name AS customer, vc.id AS cid, vc.commission_pct, vc.commission_amount, vc.paid_amount AS cpaid
               FROM invoices i JOIN customers c ON c.id = i.customer_id LEFT JOIN vendor_commissions vc ON vc.invoice_id = i.id
               WHERE i.vendor_id = ? ORDER BY i.invoice_date DESC, i.id DESC', [$id]);
$pays = rows('SELECT p.*, vc.invoice_id FROM vendor_payments p JOIN vendor_commissions vc ON vc.id = p.commission_id WHERE p.vendor_id = ? ORDER BY p.pay_date DESC, p.id DESC', [$id]);
$tC = array_sum(array_column($bills, 'commission_amount')); $tP = array_sum(array_column($bills, 'cpaid'));
$pending = array_values(array_filter($bills, fn($b) => $b['cid'] && $b['commission_amount'] - $b['cpaid'] > 0.004));
$page_title = 'Vendor: ' . $vendor['name'];
require __DIR__ . '/includes/header.php';
?>
<p class="no-print"><a class="btn alt" href="vendor_commissions.php">← Vendor Commissions</a> <a class="btn alt" href="master.php?m=vendors&id=<?= $id ?>">Edit Vendor</a> <button class="btn" onclick="window.print()">🖨 Print</button></p>
<div class="grid g4" style="margin-bottom:14px">
  <div class="stat"><?= e($vendor['name']) ?><b><?= e($vendor['mobile']) ?></b></div>
  <div class="stat">Total Commission<b><?= money($tC) ?></b></div>
  <div class="stat">Paid<b><?= money($tP) ?></b></div>
  <div class="stat warn">Pending<b><?= money($tC - $tP) ?></b></div>
</div>
<?php if ($pending): ?>
<form method="post" class="card no-print" id="vpay" novalidate><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <h3>Pay commission</h3>
  <div class="form">
    <div><label>Apply to</label><select name="commission_id" id="vc"><option value="" data-bal="<?= round($tC - $tP, 2) ?>">All pending (oldest first)</option>
      <?php foreach ($pending as $b): ?><option value="<?= $b['cid'] ?>" data-bal="<?= round($b['commission_amount'] - $b['cpaid'], 2) ?>">Bill No <?= $b['id'] ?> · pending <?= number_format($b['commission_amount'] - $b['cpaid'], 2) ?></option><?php endforeach; ?></select></div>
    <div><label>Amount (₹) * <small id="vh"></small></label><input type="number" name="amount" id="vamt" step="0.01" min="0.01" required></div>
    <div><label>Date *</label><input type="date" name="pay_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
    <div><label>Mode *</label><select name="pay_mode"><?php foreach (PAY_MODES as $m): ?><option><?= $m ?></option><?php endforeach; ?></select></div>
    <div><label>Note</label><input name="note" maxlength="150"></div>
    <div style="align-self:end"><button class="btn">Pay Commission</button></div>
  </div>
</form>
<script>
(function () {
  var s = document.getElementById('vc'), a = document.getElementById('vamt'), h = document.getElementById('vh');
  function mx() { return parseFloat(s.options[s.selectedIndex].dataset.bal) || 0; }
  function sync() { a.max = mx(); a.value = mx().toFixed(2); h.textContent = '(max ' + mx().toFixed(2) + ')'; }
  s.onchange = sync; sync();
  document.getElementById('vpay').onsubmit = function (ev) {
    var v = a.value.trim();
    if (!/^\d+(\.\d{1,2})?$/.test(v) || parseFloat(v) <= 0) { ev.preventDefault(); return alert('Enter a valid amount greater than zero.'); }
    if (parseFloat(v) > mx() + 0.005) { ev.preventDefault(); return alert('Amount cannot be more than ' + mx().toFixed(2) + '.'); }
  };
})();
</script>
<?php endif; ?>
<h3>Billing details (customers referred)</h3>
<div class="tw"><table>
<tr><th>Bill No</th><th>Date</th><th>Customer</th><th class="r">Bill Total</th><th class="r">Customer Paid</th><th class="r">%</th><th class="r">Commission</th><th class="r">Comm. Paid</th><th class="r">Comm. Pending</th></tr>
<?php foreach ($bills as $b): ?>
<tr><td><a href="print_invoice.php?id=<?= $b['id'] ?>" target="_blank">#<?= $b['id'] ?></a></td><td><?= dmy($b['invoice_date']) ?></td><td><?= e($b['customer']) ?></td>
<td class="r"><?= money($b['total_amount']) ?></td><td class="r"><?= money($b['paid_amount']) ?></td><td class="r"><?= $b['cid'] ? ($b['commission_pct'] + 0) . '%' : '—' ?></td>
<td class="r"><?= money($b['commission_amount'] ?? 0) ?></td><td class="r"><?= money($b['cpaid'] ?? 0) ?></td><td class="r"><?= money(($b['commission_amount'] ?? 0) - ($b['cpaid'] ?? 0)) ?></td></tr>
<?php endforeach; if (!$bills): ?><tr><td colspan="9">No bills referred by this vendor yet.</td></tr><?php endif; ?></table></div>
<h3 style="margin-top:22px">Commission payments made</h3>
<div class="tw"><table>
<tr><th>Date</th><th>Bill No</th><th>Mode</th><th>Note</th><th class="r">Amount</th></tr>
<?php foreach ($pays as $p): ?><tr><td><?= dmy($p['pay_date']) ?></td><td>#<?= $p['invoice_id'] ?></td><td><?= e($p['mode']) ?></td><td><?= e($p['note']) ?></td><td class="r"><?= money($p['amount']) ?></td></tr>
<?php endforeach; if (!$pays): ?><tr><td colspan="5">No payments made yet.</td></tr><?php endif; ?></table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
require_once __DIR__ . '/bill.php';

function handle_list_post(string $mode): void {
    $c = bill_cfg($mode);
    check_csrf();
    $act = $_POST['act'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $back = $c['list'] . '?' . http_build_query(array_filter(['party' => $_POST['f_party'] ?? '', 'status' => $_POST['f_status'] ?? '', 'from' => $_POST['f_from'] ?? '', 'to' => $_POST['f_to'] ?? '']));
    $bill = row("SELECT * FROM {$c['head']} WHERE id = ?", [$id]);
    if (!$bill) { flash('Bill not found.', 'err'); redirect($back); }

    if ($act === 'pay') {
        $balance = round($bill['total_amount'] - $bill['paid_amount'], 2);
        $modeP = in_array($_POST['pay_mode'] ?? '', PAY_MODES, true) ? $_POST['pay_mode'] : 'Cash';
        try { $amt = v_num($_POST['amount'] ?? '', 'Amount', 0.01, 100000000); }
        catch (RuntimeException $ex) { flash($ex->getMessage(), 'err'); redirect($back); }
        if ($amt > $balance + 0.005) { flash('Enter an amount between 0.01 and the balance (' . number_format($balance, 2) . ').', 'err'); redirect($back); }
        $pdo = db(); $pdo->beginTransaction();
        q("INSERT INTO {$c['pay']} ({$c['fk']}, pay_date, amount, mode) VALUES (?,?,?,?)", [$id, date('Y-m-d'), $amt, $modeP]);
        q("UPDATE {$c['head']} SET paid_amount = paid_amount + ? WHERE id = ?", [$amt, $id]);
        $pdo->commit();
        flash($mode === 'sale' ? 'Payment received successfully.' : 'Payment paid successfully.');
    } elseif ($act === 'delete' && is_admin()) {
        $pdo = db(); $pdo->beginTransaction();
        try {
            $tbl = $c['items'];
            if ($mode === 'sale') {
                if (val('SELECT COALESCE(SUM(paid_amount),0) FROM vendor_commissions WHERE invoice_id = ?', [$id]) > 0) throw new RuntimeException('Cannot delete: vendor commission has already been paid on this bill.');
                if (val('SELECT COUNT(*) FROM sales_returns WHERE invoice_id = ?', [$id])) throw new RuntimeException('Cannot delete: a goods return is recorded against this bill.');
            }
            foreach (rows("SELECT product_id, quantity FROM $tbl WHERE {$c['fk']} = ?", [$id]) as $it) {
                if ($mode === 'sale') q('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?', [$it['quantity'], $it['product_id']]);
                else {
                    $st = val('SELECT stock_qty FROM products WHERE id = ? FOR UPDATE', [$it['product_id']]);
                    if ($st < $it['quantity']) throw new RuntimeException('Cannot delete: some of this purchased stock has already been sold.');
                    q('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?', [$it['quantity'], $it['product_id']]);
                }
            }
            q("DELETE FROM {$c['head']} WHERE id = ?", [$id]);
            $pdo->commit(); flash('Bill deleted and stock adjusted.');
        } catch (RuntimeException $ex) { $pdo->rollBack(); flash($ex->getMessage(), 'err'); }
    }
    redirect($back);
}

function render_bill_list(string $mode): void {
    $c = bill_cfg($mode);
    $party = (int)($_GET['party'] ?? 0); $status = $_GET['status'] ?? '';
    $from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? '';
    $sql = "SELECT b.*, p.name AS party FROM {$c['head']} b JOIN {$c['party_table']} p ON p.id = b.{$c['party_fk']} WHERE 1=1";
    $pr = [];
    if ($party) { $sql .= " AND b.{$c['party_fk']} = ?"; $pr[] = $party; }
    if ($status === 'pending') $sql .= ' AND b.total_amount - b.paid_amount > 0.004';
    if ($status === 'paid') $sql .= ' AND b.total_amount - b.paid_amount <= 0.004';
    if ($from) { $sql .= " AND b.{$c['date_col']} >= ?"; $pr[] = $from; }
    if ($to) { $sql .= " AND b.{$c['date_col']} <= ?"; $pr[] = $to; }
    $list = rows($sql . " ORDER BY b.{$c['date_col']} DESC, b.id DESC LIMIT 500", $pr);
    $parties = rows("SELECT id, name FROM {$c['party_table']} ORDER BY name");
    $tot = $paid = 0;
?>
<form class="bar" method="get">
  <div><label><?= $c['party'] ?></label><select name="party"><option value="">All</option>
    <?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>" <?= $party == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>Status</label><select name="status"><option value="">All</option><option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending balance</option><option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Fully paid</option></select></div>
  <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn alt">Refresh</button>
  <a class="btn" href="<?= $c['new'] ?>">+ New</a>
</form>
<div class="tw"><table>
<tr><th><?= $mode === 'sale' ? 'Bill No' : 'ID' ?></th><th>Date</th><th><?= $c['party'] ?></th><th class="r">Discount</th><th class="r">GST</th><?php if ($mode === 'sale'): ?><th class="r">Labour + Transport</th><?php endif; ?><th class="r">Total</th><th class="r">Paid</th><th class="r">Balance</th><th>Status</th><th>Actions</th></tr>
<?php foreach ($list as $b):
    $bal = round($b['total_amount'] - $b['paid_amount'], 2); $tot += $b['total_amount']; $paid += $b['paid_amount']; ?>
<tr>
  <td>#<?= $b['id'] ?></td><td><?= dmy($b[$c['date_col']]) ?></td><td><?= e($b['party']) ?></td>
  <td class="r"><?= $b['discount_pct'] + 0 ?>%</td><td class="r"><?= $b['gst_pct'] + 0 ?>%</td>
  <?php if ($mode === 'sale'): ?><td class="r"><?= money($b['labour_charges'] + $b['transport_charges']) ?></td><?php endif; ?>
  <td class="r"><?= money($b['total_amount']) ?></td><td class="r"><?= money($b['paid_amount']) ?></td><td class="r"><?= money($bal) ?></td>
  <td><span class="badge <?= $bal > 0.004 ? 'due' : 'paid' ?>"><?= $bal > 0.004 ? 'Pending' : 'Paid' ?></span></td>
  <td style="white-space:nowrap">
    <a class="btn sm alt" href="<?= $c['print'] ?>?id=<?= $b['id'] ?>" target="_blank">Print</a>
    <?php if ($bal > 0.004 && $mode === 'sale'): ?><a class="btn sm" href="receive_payment.php?cid=<?= (int)$b['customer_id'] ?>">Receive</a>
    <?php elseif ($bal > 0.004): ?>
    <details style="display:inline-block;vertical-align:top"><summary class="btn sm"><?= $mode === 'sale' ? 'Receive' : 'Pay' ?></summary>
      <form method="post" style="position:absolute;background:#fff;border:1px solid var(--line);padding:10px;border-radius:8px;z-index:5;width:210px">
        <?= csrf_field() ?><input type="hidden" name="act" value="pay"><input type="hidden" name="id" value="<?= $b['id'] ?>">
        <input type="hidden" name="f_party" value="<?= $party ?>"><input type="hidden" name="f_status" value="<?= e($status) ?>"><input type="hidden" name="f_from" value="<?= e($from) ?>"><input type="hidden" name="f_to" value="<?= e($to) ?>">
        <label>Amount (max <?= number_format($bal, 2) ?>)</label><input type="number" name="amount" step="0.01" min="0.01" max="<?= $bal ?>" value="<?= $bal ?>" required>
        <label style="margin-top:6px">Mode</label><select name="pay_mode"><?php foreach (PAY_MODES as $m): ?><option><?= $m ?></option><?php endforeach; ?></select>
        <button class="btn sm" style="margin-top:8px">Save</button>
      </form></details>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete this bill? Stock will be adjusted back.')">
      <?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $b['id'] ?>">
      <input type="hidden" name="f_party" value="<?= $party ?>"><input type="hidden" name="f_status" value="<?= e($status) ?>">
      <button class="btn sm danger">Delete</button></form>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; if (!$list): ?><tr><td colspan="11">No bills found.</td></tr><?php endif; ?>
<?php if ($list): ?><tr><th colspan="<?= $mode === 'sale' ? 6 : 5 ?>" class="r">Totals</th><th class="r"><?= money($tot) ?></th><th class="r"><?= money($paid) ?></th><th class="r"><?= money($tot - $paid) ?></th><th colspan="2"></th></tr><?php endif; ?>
</table></div>
<?php }

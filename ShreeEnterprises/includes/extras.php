<?php
// Cash sales (walk-in, no bill, paid in full) and Quotations (no stock / no sale effect): list + print screens.
require_once __DIR__ . '/bill.php';

function handle_simple_list_post(string $mode): void {
    $c = bill_cfg($mode);
    check_csrf();
    if (!is_admin()) { flash('Admin access required.', 'err'); redirect($c['list']); }
    $id = (int)($_POST['id'] ?? 0);
    $pdo = db(); $pdo->beginTransaction();
    try {
        if (!row("SELECT id FROM {$c['head']} WHERE id = ? FOR UPDATE", [$id])) throw new RuntimeException('Record not found.');
        if ($mode === 'cash') {
            foreach (rows('SELECT product_id, quantity FROM cash_sale_items WHERE cash_sale_id = ?', [$id]) as $it)
                q('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?', [$it['quantity'], $it['product_id']]);
        }
        q("DELETE FROM {$c['head']} WHERE id = ?", [$id]);
        $pdo->commit();
        flash($mode === 'cash' ? 'Cash sale deleted and stock adjusted.' : 'Quotation deleted.');
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash($ex instanceof RuntimeException ? $ex->getMessage() : 'Could not delete.', 'err');
    }
    redirect($c['list']);
}

function render_simple_list(string $mode): void {
    $c = bill_cfg($mode);
    $from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? ''; $s = trim($_GET['s'] ?? '');
    $sql = "SELECT * FROM {$c['head']} WHERE 1=1"; $pr = [];
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $sql .= " AND {$c['date_col']} >= ?"; $pr[] = $from; }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $sql .= " AND {$c['date_col']} <= ?"; $pr[] = $to; }
    if ($s !== '') { $sql .= ' AND (customer_name LIKE ? OR mobile LIKE ?)'; array_push($pr, "%$s%", "%$s%"); }
    $list = rows($sql . " ORDER BY {$c['date_col']} DESC, id DESC LIMIT 500", $pr);
    $tot = 0;
?>
<form class="bar" method="get">
  <div><label>Name / Mobile</label><input name="s" value="<?= e($s) ?>" maxlength="40"></div>
  <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn alt">Refresh</button>
  <a class="btn" href="<?= $c['new'] ?>">+ New</a>
</form>
<div class="tw"><table>
<tr><th><?= e($c['no']) ?></th><th>Date</th><th>Customer</th><th>Mobile</th><?= $mode === 'quote' ? '<th>Valid Till</th>' : '<th>Mode</th>' ?><th class="r">Total</th><th>Actions</th></tr>
<?php foreach ($list as $b): $tot += $b['total_amount']; ?>
<tr><td>#<?= $b['id'] ?></td><td><?= dmy($b[$c['date_col']]) ?></td><td><?= e($b['customer_name'] ?: 'Walk-in') ?></td><td><?= e($b['mobile']) ?></td>
<td><?= $mode === 'quote' ? dmy($b['valid_till']) : e($b['pay_mode']) ?></td><td class="r"><?= money($b['total_amount']) ?></td>
<td style="white-space:nowrap"><a class="btn sm alt" href="<?= $c['print'] ?>?id=<?= $b['id'] ?>" target="_blank">Print</a>
<?php if (is_admin()): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete this record?<?= $mode === 'cash' ? ' Stock will be adjusted back.' : '' ?>')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="btn sm danger">Delete</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$list): ?><tr><td colspan="7">No records found.</td></tr><?php endif; ?>
<?php if ($list): ?><tr><th colspan="5" class="r">Total</th><th class="r"><?= money($tot) ?></th><th></th></tr><?php endif; ?>
</table></div>
<?php }

function render_simple_print(string $mode): void {
    $c = bill_cfg($mode);
    $id = (int)($_GET['id'] ?? 0);
    $b = row("SELECT * FROM {$c['head']} WHERE id = ?", [$id]);
    if (!$b) { echo '<div class="card">Record not found.</div>'; return; }
    $items = rows("SELECT pr.name, pr.unit, i.quantity, i.sales_rate AS rate, i.line_total FROM {$c['items']} i JOIN products pr ON pr.id = i.product_id WHERE i.{$c['fk']} = ? ORDER BY i.id", [$id]);
    $after = $b['subtotal'] * (1 - $b['discount_pct'] / 100);
    $labour = (float)$b['labour_charges']; $transport = (float)$b['transport_charges'];
    $addr = ($mode === 'quote' && $b['customer_id']) ? val('SELECT address FROM customers WHERE id = ?', [$b['customer_id']]) : '';
?>
<p class="no-print"><button class="btn" onclick="window.print()">🖨 Print</button> <a class="btn alt" href="<?= $c['list'] ?>">Back to list</a></p>
<div class="print-sheet">
  <?= shop_head() ?>
  <div class="pt"><?= $mode === 'quote' ? 'Quotation' : 'Cash Memo' ?></div>
  <div class="pm">
    <div><b><?= e($c['no']) ?>:</b> <?= $b['id'] ?><br>
      <b>Customer Name:</b> <span style="color:#8a0000;font-weight:700"><?= e($b['customer_name'] ?: 'Walk-in Customer') ?></span></div>
    <div style="text-align:right"><?php if ($addr): ?><b>Address:</b> <?= e($addr) ?><br><?php endif; ?><?php if ($b['mobile']): ?><b>Mobile:</b> <?= e($b['mobile']) ?><br><?php endif; ?>
      <b>Date:</b> <?= dmy($b[$c['date_col']]) ?><?php if ($mode === 'quote' && $b['valid_till']): ?><br><b>Valid Till:</b> <?= dmy($b['valid_till']) ?><?php endif; ?></div>
  </div>
  <table>
    <tr><th>Product Name</th><th class="r">Quantity</th><th>Unit</th><th class="r">Rate</th><th class="r">Total</th></tr>
    <?php foreach ($items as $it): ?><tr><td><?= e($it['name']) ?></td><td class="r"><?= $it['quantity'] ?></td><td><?= e($it['unit']) ?></td><td class="r"><?= money($it['rate']) ?></td><td class="r"><?= money($it['line_total']) ?></td></tr><?php endforeach; ?>
  </table>
  <div style="display:flex;justify-content:space-between;border-top:1px solid #333;padding:10px 14px;gap:20px">
    <div style="font-size:13px"><?= $mode === 'quote' ? e($b['notes']) . '<br><i>This is a quotation only, not a bill. Prices and availability are subject to change.</i>' : 'Paid in full (' . e($b['pay_mode']) . ')' ?></div>
    <div class="totals" style="margin:0;min-width:260px">
      <div><span>Sub Total</span><span><?= money($b['subtotal']) ?></span></div>
      <div><span>Discount (<?= $b['discount_pct'] + 0 ?>%)</span><span>− <?= money($b['subtotal'] - $after) ?></span></div>
      <div><span>GST (<?= $b['gst_pct'] + 0 ?>%)</span><span>+ <?= money($b['total_amount'] - $labour - $transport - $after) ?></span></div>
      <?php if ($labour > 0): ?><div><span>Labour Charges</span><span>+ <?= money($labour) ?></span></div><?php endif; ?>
      <?php if ($transport > 0): ?><div><span>Transport Charges</span><span>+ <?= money($transport) ?></span></div><?php endif; ?>
      <div class="grand"><span>Total</span><span><?= money($b['total_amount']) ?></span></div>
    </div>
  </div>
  <div class="sig"><div>Customer Signatory</div><div>Authorised Signature</div></div>
</div>
<?php }

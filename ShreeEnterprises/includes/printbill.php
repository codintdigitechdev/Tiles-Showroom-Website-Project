<?php
require_once __DIR__ . '/bill.php';

function render_print(string $mode): void {
    $c = bill_cfg($mode);
    $id = (int)($_GET['id'] ?? 0);
    $b = row("SELECT b.*, p.name AS party, p.mobile, p.address FROM {$c['head']} b JOIN {$c['party_table']} p ON p.id = b.{$c['party_fk']} WHERE b.id = ?", [$id]);
    if (!$b) { echo '<div class="card">Bill not found.</div>'; return; }
    $rateCol = $mode === 'sale' ? 'i.sales_rate' : 'i.purchase_price';
    $items = rows("SELECT pr.name, pr.unit, i.quantity, $rateCol AS rate, i.line_total FROM {$c['items']} i JOIN products pr ON pr.id = i.product_id WHERE i.{$c['fk']} = ? ORDER BY i.id", [$id]);
    $pays = rows("SELECT * FROM {$c['pay']} WHERE {$c['fk']} = ? ORDER BY pay_date, id", [$id]);
    $after = $b['subtotal'] * (1 - $b['discount_pct'] / 100);
    $labour = (float)($b['labour_charges'] ?? 0); $transport = (float)($b['transport_charges'] ?? 0);
    $bal = $b['total_amount'] - $b['paid_amount'];
    $isSale = $mode === 'sale';
?>
<p class="no-print"><button class="btn" onclick="window.print()">🖨 Print</button>
<a class="btn alt" href="<?= $c['list'] ?>">Back to list</a></p>
<div class="print-sheet">
  <?= shop_head() ?>
  <div class="pt"><?= $isSale ? 'Quotation' : 'Purchase Challan' ?></div>
  <div class="pm">
    <div><b><?= $isSale ? 'Bill No' : 'Purchase ID' ?>:</b> <?= $b['id'] ?><br>
      <b><?= $c['party'] ?> ID:</b> <?= $b[$c['party_fk']] ?><br>
      <b><?= $c['party'] ?> Name:</b> <span style="color:#8a0000;font-weight:700"><?= e($b['party']) ?></span></div>
    <div style="text-align:right"><b>Address:</b> <?= e($b['address']) ?><br><b>Mobile:</b> <?= e($b['mobile']) ?><br><b>Date:</b> <?= dmy($b[$c['date_col']]) ?></div>
  </div>
  <table>
    <tr><th>Product Name</th><th class="r">Quantity</th><th>Unit</th><th class="r"><?= $isSale ? 'Sales Rate' : 'Purchase Price' ?></th><th class="r">Total</th></tr>
    <?php foreach ($items as $it): ?>
    <tr><td><?= e($it['name']) ?></td><td class="r"><?= $it['quantity'] ?></td><td><?= e($it['unit']) ?></td><td class="r"><?= money($it['rate']) ?></td><td class="r"><?= money($it['line_total']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <div style="display:flex;justify-content:space-between;border-top:1px solid #333;padding:10px 14px;gap:20px">
    <div style="font-size:13px"><?php if ($pays): ?><b>Payments</b><br><?php foreach ($pays as $p): ?><?= dmy($p['pay_date']) ?> · <?= e($p['mode']) ?> · <?= money($p['amount']) ?><br><?php endforeach; endif; ?></div>
    <div class="totals" style="margin:0;min-width:260px">
      <div><span>Sub Total</span><span><?= money($b['subtotal']) ?></span></div>
      <div><span>Discount (<?= $b['discount_pct'] + 0 ?>%)</span><span>− <?= money($b['subtotal'] - $after) ?></span></div>
      <div><span>GST (<?= $b['gst_pct'] + 0 ?>%)</span><span>+ <?= money($b['total_amount'] - $labour - $transport - $after) ?></span></div>
      <?php if ($labour > 0): ?><div><span>Labour Charges</span><span>+ <?= money($labour) ?></span></div><?php endif; ?>
      <?php if ($transport > 0): ?><div><span>Transport Charges</span><span>+ <?= money($transport) ?></span></div><?php endif; ?>
      <div class="grand"><span>Total</span><span><?= money($b['total_amount']) ?></span></div>
      <div><span>Paid</span><span><?= money($b['paid_amount']) ?></span></div>
      <div><span>Balance</span><b><?= money($bal) ?></b></div>
    </div>
  </div>
  <div class="sig"><div><?= $isSale ? 'Receiver' : 'Supplier' ?> Signatory</div><div>Authorised Signature</div></div>
</div>
<?php }

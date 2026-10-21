<?php
// Shared logic for the bill screens.
// $mode = 'sale' (regular customer bill) | 'purchase' | 'cash' (walk-in cash sale, no bill) | 'quote' (quotation, no stock effect)

function bill_cfg(string $mode): array {
    $all = [
        'sale' => ['title' => 'New Sale / Bill', 'party' => 'Customer', 'party_table' => 'customers', 'head' => 'invoices', 'items' => 'invoice_items',
            'pay' => 'invoice_payments', 'fk' => 'invoice_id', 'party_fk' => 'customer_id', 'date_col' => 'invoice_date', 'rate_col' => 'sales_rate',
            'new' => 'sale_new.php', 'list' => 'invoices.php', 'print' => 'print_invoice.php', 'label' => 'Bill', 'no' => 'Bill No'],
        'purchase' => ['title' => 'New Purchase Bill', 'party' => 'Supplier', 'party_table' => 'suppliers', 'head' => 'purchases', 'items' => 'purchase_items',
            'pay' => 'purchase_payments', 'fk' => 'purchase_id', 'party_fk' => 'supplier_id', 'date_col' => 'purchase_date', 'rate_col' => 'purchase_price',
            'new' => 'purchase_new.php', 'list' => 'purchases.php', 'print' => 'print_purchase.php', 'label' => 'Purchase', 'no' => 'Purchase ID'],
        'cash' => ['title' => 'New Cash Sale', 'party' => 'Customer', 'party_table' => null, 'head' => 'cash_sales', 'items' => 'cash_sale_items',
            'pay' => null, 'fk' => 'cash_sale_id', 'party_fk' => null, 'date_col' => 'sale_date', 'rate_col' => 'sales_rate',
            'new' => 'cash_sale_new.php', 'list' => 'cash_sales.php', 'print' => 'print_cash.php', 'label' => 'Cash Sale', 'no' => 'Cash Sale No'],
        'quote' => ['title' => 'New Quotation', 'party' => 'Customer', 'party_table' => 'customers', 'head' => 'quotations', 'items' => 'quotation_items',
            'pay' => null, 'fk' => 'quotation_id', 'party_fk' => 'customer_id', 'date_col' => 'quote_date', 'rate_col' => 'sales_rate',
            'new' => 'quotation_new.php', 'list' => 'quotations.php', 'print' => 'print_quote.php', 'label' => 'Quotation', 'no' => 'Quotation No'],
    ];
    return $all[$mode];
}

function handle_bill_post(string $mode): void {
    $c = bill_cfg($mode);
    check_csrf();
    $fail = function (string $msg) use ($c) { flash($msg, 'err'); redirect($c['new']); };
    $pdo = db();
    $inTx = false;
    try {
        $date = v_date($_POST['bill_date'] ?? '', 'Date', false);
        $disc = v_num($_POST['discount'] ?? '0', 'Discount (%)', 0, 100);
        $gst = v_num($_POST['gst'] ?? '0', 'GST (%)', 0, 100);
        $labour = $mode === 'purchase' ? 0.0 : v_num($_POST['labour'] ?? '0', 'Labour charges', 0, 1000000);
        $transport = $mode === 'purchase' ? 0.0 : v_num($_POST['transport'] ?? '0', 'Transport charges', 0, 1000000);

        // ---- party / header fields per mode
        $partyId = 0; $vendorId = null; $commPct = 0.0; $cName = null; $cMobile = null; $validTill = null; $notes = null;
        if ($mode === 'sale' || $mode === 'purchase') {
            $partyId = (int)($_POST['party_id'] ?? 0);
            if (!val("SELECT COUNT(*) FROM {$c['party_table']} WHERE id = ?", [$partyId])) throw new RuntimeException($c['party'] . ' is required.');
        }
        if ($mode === 'sale') {
            $vid = (int)($_POST['vendor_id'] ?? 0);
            if ($vid) {
                $ven = row('SELECT id, commission_pct FROM vendors WHERE id = ?', [$vid]);
                if (!$ven) throw new RuntimeException('Selected vendor no longer exists.');
                $vendorId = $vid;
                $commPct = (float)$ven['commission_pct'];
                if (is_admin() && isset($_POST['commission_pct']) && trim($_POST['commission_pct']) !== '') {
                    $commPct = v_num($_POST['commission_pct'], 'Commission (%)', 0, 100);
                }
            }
        }
        if ($mode === 'cash') {
            $cName = v_name($_POST['customer_name'] ?? '', 'Customer name', false);
            $cMobile = v_mobile($_POST['mobile'] ?? '', 'Mobile No', false);
        }
        if ($mode === 'quote') {
            $cid = (int)($_POST['party_id'] ?? 0);
            if ($cid) {
                $cu = row('SELECT id, name, mobile FROM customers WHERE id = ?', [$cid]);
                if (!$cu) throw new RuntimeException('Selected customer no longer exists.');
                $partyId = $cid; $cName = $cu['name']; $cMobile = $cu['mobile'];
            } else {
                $cName = v_name($_POST['customer_name'] ?? '', 'Customer name', true);
                $cMobile = v_mobile($_POST['mobile'] ?? '', 'Mobile No', false);
            }
            $validTill = v_date($_POST['valid_till'] ?? '', 'Valid till', true, false);
            if ($validTill && $validTill < $date) throw new RuntimeException('"Valid till" cannot be before the quotation date.');
            $notes = v_text($_POST['notes'] ?? '', 'Notes', 255);
        }

        // ---- item lines
        $items = $_POST['items'] ?? [];
        if (!is_array($items) || !$items) throw new RuntimeException('Add at least one product to the bill.');
        if (count($items) > 100) throw new RuntimeException('A bill can have at most 100 lines.');
        $lines = []; $sub = 0.0; $need = [];
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            if ($pid <= 0) throw new RuntimeException('Each line needs a product.');
            $qty = (int)v_num($it['qty'] ?? '', 'Quantity', 1, 100000, true);
            $rate = v_num($it['rate'] ?? '', 'Rate', 0.01, 1000000);
            $srate = $mode === 'purchase' ? v_num($it['srate'] ?? $rate, 'Sales price', 0.01, 1000000) : $rate;
            $need[$pid] = ($need[$pid] ?? 0) + $qty;
            $lines[] = ['pid' => $pid, 'qty' => $qty, 'rate' => $rate, 'srate' => $srate, 'total' => round($qty * $rate, 2)];
            $sub += $qty * $rate;
        }
        $sub = round($sub, 2);
        $total = bill_grand_total($sub, $disc, $gst, $labour, $transport);

        $paid = 0.0; $payMode = 'Cash';
        if ($mode === 'sale' || $mode === 'purchase') $paid = v_num($_POST['paid'] ?? '0', 'Paid amount', 0, 100000000);
        if ($mode === 'cash') $paid = $total; // cash customers pay in full
        $payMode = in_array($_POST['pay_mode'] ?? '', PAY_MODES, true) ? $_POST['pay_mode'] : 'Cash';
        if ($paid > $total + 0.005) throw new RuntimeException('Paid amount cannot be more than the total (' . number_format($total, 2) . ').');

        $pdo->beginTransaction(); $inTx = true;
        // lock products + stock check (not for quotations: they never touch stock)
        foreach ($need as $pid => $qty) {
            $p = row('SELECT id, name, stock_qty FROM products WHERE id = ? FOR UPDATE', [$pid]);
            if (!$p) throw new RuntimeException('A selected product no longer exists.');
            if (($mode === 'sale' || $mode === 'cash') && $p['stock_qty'] < $qty) throw new RuntimeException("Not enough stock for {$p['name']} (available {$p['stock_qty']}).");
        }

        // ---- header insert
        $h = [];
        if ($mode === 'sale') $h = [$c['party_fk'] => $partyId, $c['date_col'] => $date, 'subtotal' => $sub, 'discount_pct' => $disc, 'gst_pct' => $gst,
            'labour_charges' => $labour, 'transport_charges' => $transport, 'vendor_id' => $vendorId, 'total_amount' => $total, 'paid_amount' => $paid, 'created_by' => user()['id']];
        if ($mode === 'purchase') $h = [$c['party_fk'] => $partyId, $c['date_col'] => $date, 'subtotal' => $sub, 'discount_pct' => $disc, 'gst_pct' => $gst,
            'total_amount' => $total, 'paid_amount' => $paid, 'created_by' => user()['id']];
        if ($mode === 'cash') $h = [$c['date_col'] => $date, 'customer_name' => $cName, 'mobile' => $cMobile, 'subtotal' => $sub, 'discount_pct' => $disc, 'gst_pct' => $gst,
            'labour_charges' => $labour, 'transport_charges' => $transport, 'total_amount' => $total, 'pay_mode' => $payMode, 'created_by' => user()['id']];
        if ($mode === 'quote') $h = ['customer_id' => $partyId ?: null, 'customer_name' => $cName, 'mobile' => $cMobile, $c['date_col'] => $date, 'valid_till' => $validTill,
            'subtotal' => $sub, 'discount_pct' => $disc, 'gst_pct' => $gst, 'labour_charges' => $labour, 'transport_charges' => $transport, 'total_amount' => $total,
            'notes' => $notes, 'created_by' => user()['id']];
        q("INSERT INTO {$c['head']} (" . implode(', ', array_keys($h)) . ') VALUES (' . implode(',', array_fill(0, count($h), '?')) . ')', array_values($h));
        $id = (int)$pdo->lastInsertId();

        foreach ($lines as $l) {
            if ($mode === 'purchase') {
                q('INSERT INTO purchase_items (purchase_id, product_id, quantity, purchase_price, sales_price, line_total) VALUES (?,?,?,?,?,?)', [$id, $l['pid'], $l['qty'], $l['rate'], $l['srate'], $l['total']]);
                q('UPDATE products SET stock_qty = stock_qty + ?, purchase_rate = ?, sales_rate = ? WHERE id = ?', [$l['qty'], $l['rate'], $l['srate'], $l['pid']]);
            } else {
                q("INSERT INTO {$c['items']} ({$c['fk']}, product_id, quantity, sales_rate, line_total) VALUES (?,?,?,?,?)", [$id, $l['pid'], $l['qty'], $l['rate'], $l['total']]);
                if ($mode !== 'quote') q('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?', [$l['qty'], $l['pid']]);
            }
        }
        if (($mode === 'sale' || $mode === 'purchase') && $paid > 0) q("INSERT INTO {$c['pay']} ({$c['fk']}, pay_date, amount, mode) VALUES (?,?,?,?)", [$id, $date, $paid, $payMode]);
        if ($mode === 'sale' && $vendorId && $commPct > 0) {
            $base = round($sub * (1 - $disc / 100), 2);
            q('INSERT INTO vendor_commissions (vendor_id, invoice_id, commission_pct, base_amount, commission_amount) VALUES (?,?,?,?,?)',
              [$vendorId, $id, $commPct, $base, round($base * $commPct / 100, 2)]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($inTx) $pdo->rollBack();
        $fail($ex instanceof RuntimeException ? $ex->getMessage() : 'Could not save. Please try again.');
    }
    flash($c['label'] . " #$id saved successfully.");
    redirect($c['print'] . '?id=' . $id);
}

function render_bill_form(string $mode): void {
    $c = bill_cfg($mode);
    $parties = $c['party_table'] ? rows("SELECT id, name, mobile" . ($mode === 'sale' ? ', vendor_id' : '') . " FROM {$c['party_table']} ORDER BY name") : [];
    $vendors = $mode === 'sale' ? rows('SELECT id, name, commission_pct FROM vendors ORDER BY name') : [];
    $cats = rows('SELECT id, name FROM categories ORDER BY name');
    $subs = rows('SELECT id, name, category_id FROM subcategories ORDER BY name');
    $prods = rows('SELECT id, name, category_id, subcategory_id, sales_rate, purchase_rate, unit, stock_qty FROM products ORDER BY name');
    if (!is_admin()) foreach ($prods as &$pp) $pp['purchase_rate'] = 0; // staff never receive purchase rates
    unset($pp);
    $hasPay = $mode === 'sale' || $mode === 'purchase';
?>
<form method="post" id="billform" class="card" novalidate>
<?= csrf_field() ?>
<?php if ($mode === 'quote'): ?>
<p style="margin-top:0;color:#6b5d52">A quotation shows the customer the exact products, quantities, discount and total <b>before</b> the order. It does <b>not</b> reduce stock or count as a sale.</p>
<?php endif; ?>
<div class="form">
<?php if ($mode === 'sale' || $mode === 'purchase'): ?>
  <div><label><?= $c['party'] ?> *</label>
    <select name="party_id" id="party" required><option value="">-- select --</option>
    <?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>" <?= $mode === 'sale' ? 'data-vendor="' . (int)$p['vendor_id'] . '"' : '' ?>><?= e($p['name']) ?> (#<?= $p['id'] ?>)</option><?php endforeach; ?></select></div>
<?php endif; ?>
<?php if ($mode === 'sale'): ?>
  <div><label>Referred by Vendor (optional)</label>
    <select name="vendor_id" id="vendor"><option value="" data-pct="0">-- none --</option>
    <?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>" data-pct="<?= $v['commission_pct'] + 0 ?>"><?= e($v['name']) ?> (<?= $v['commission_pct'] + 0 ?>%)</option><?php endforeach; ?></select></div>
  <?php if (is_admin()): ?><div><label>Vendor Commission (%)</label><input type="number" name="commission_pct" id="commpct" min="0" max="100" step="0.01" placeholder="Vendor default"></div><?php endif; ?>
<?php endif; ?>
<?php if ($mode === 'quote'): ?>
  <div><label>Regular Customer (optional)</label>
    <select name="party_id" id="party"><option value="">-- walk-in / new prospect --</option>
    <?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (#<?= $p['id'] ?>)</option><?php endforeach; ?></select></div>
<?php endif; ?>
<?php if ($mode === 'cash' || $mode === 'quote'): ?>
  <div id="nameBox"><label>Customer Name<?= $mode === 'quote' ? ' *' : ' (optional)' ?></label><input name="customer_name" id="cname" maxlength="80" data-type="name" autocomplete="off"></div>
  <div id="mobBox"><label>Mobile No (optional)</label><input name="mobile" id="cmob" maxlength="10" data-type="digits" inputmode="numeric" placeholder="10 digits" autocomplete="off"></div>
<?php endif; ?>
  <div><label>Date *</label><input type="date" name="bill_date" id="bdate" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
<?php if ($mode === 'quote'): ?>
  <div><label>Valid Till (optional)</label><input type="date" name="valid_till" id="vtill" value="<?= date('Y-m-d', strtotime('+7 days')) ?>"></div>
  <div style="grid-column:1/-1"><label>Notes (optional)</label><input name="notes" maxlength="255" placeholder="Delivery terms, site name..."></div>
<?php endif; ?>
</div>
<h3 style="margin-top:20px">Product Details</h3>
<div class="form">
  <div><label>Category</label><select id="cat"><option value="">-- select --</option><?php foreach ($cats as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>Subcategory</label><select id="sub"><option value="">-- select --</option></select></div>
  <div><label>Product</label><select id="prod"><option value="">-- select --</option></select></div>
  <div><label>Quantity</label><input type="number" id="qty" min="1" step="1" value="1"></div>
  <div><label><?= $mode === 'purchase' ? 'Purchase Price' : 'Sales Price' ?></label><input type="number" id="rate" min="0" step="0.01"></div>
  <?php if ($mode === 'purchase'): ?><div><label>Sales Price</label><input type="number" id="srate" min="0" step="0.01"></div><?php endif; ?>
  <div><label>Unit / Stock</label><input id="info" readonly></div>
  <div style="align-self:end"><button type="button" class="btn" id="add">Add</button></div>
</div>
<div class="tw" style="margin:16px 0"><table id="lines">
<thead><tr><th>Sr</th><th>Category</th><th>Product</th><th class="r">Qty</th><th class="r">Rate</th><?= $mode === 'purchase' ? '<th class="r">Sales Price</th>' : '' ?><th class="r">Total</th><th></th></tr></thead>
<tbody></tbody></table></div>
<div class="form" style="align-items:start">
  <div class="form" style="grid-template-columns:1fr 1fr">
    <div><label>Discount (%)</label><input type="number" name="discount" id="disc" value="0" min="0" max="100" step="0.01"></div>
    <div><label>GST (%)</label><input type="number" name="gst" id="gst" value="<?= $mode === 'cash' ? 0 : 18 ?>" min="0" max="100" step="0.01"></div>
<?php if ($mode !== 'purchase'): ?>
    <div><label>Labour Charges (₹)</label><input type="number" name="labour" id="labour" value="0" min="0" step="0.01"></div>
    <div><label>Transport Charges (₹)</label><input type="number" name="transport" id="transport" value="0" min="0" step="0.01"></div>
<?php endif; ?>
<?php if ($hasPay): ?>
    <div><label>Paid Amount</label><input type="number" name="paid" id="paid" value="0" min="0" step="0.01"></div>
<?php endif; ?>
<?php if ($hasPay || $mode === 'cash'): ?>
    <div><label>Payment Mode</label><select name="pay_mode"><?php foreach (PAY_MODES as $m): ?><option><?= $m ?></option><?php endforeach; ?></select></div>
<?php endif; ?>
  </div>
  <div class="totals">
    <div><span>Sub Total</span><b id="t_sub">₹ 0.00</b></div>
    <div><span>After Discount</span><b id="t_disc">₹ 0.00</b></div>
<?php if ($mode !== 'purchase'): ?><div><span>Labour + Transport</span><b id="t_extra">₹ 0.00</b></div><?php endif; ?>
    <div class="grand"><span>Total Amount</span><b id="t_total">₹ 0.00</b></div>
<?php if ($hasPay): ?><div><span>Balance Amount</span><b id="t_bal">₹ 0.00</b></div><?php endif; ?>
<?php if ($mode === 'cash'): ?><div><span>Paid in full</span><b id="t_paid">₹ 0.00</b></div><?php endif; ?>
  </div>
</div>
<p><button class="btn" id="save">Save <?= e($c['label']) ?></button>
<a class="btn alt" href="<?= $c['list'] ?>">Cancel</a></p>
</form>
<script>
(function () {
  var MODE = <?= json_encode($mode) ?>;
  var subs = <?= json_encode($subs) ?>, prods = <?= json_encode($prods) ?>;
  var $ = function (id) { return document.getElementById(id); };
  var lines = [];
  function fill(sel, list, ph) {
    sel.innerHTML = '<option value="">' + ph + '</option>';
    list.forEach(function (x) { var o = document.createElement('option'); o.value = x.id; o.textContent = x.name; sel.appendChild(o); });
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (ch) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[ch]; }); }
  function num(id) { var el = $(id); return el ? (parseFloat(el.value) || 0) : 0; }
  function decimals(v) { var s = String(v); var i = s.indexOf('.'); return i < 0 ? 0 : s.length - i - 1; }
  $('cat').onchange = function () {
    fill($('sub'), subs.filter(function (s) { return s.category_id == $('cat').value; }), '-- select --');
    fill($('prod'), [], '-- select --'); $('rate').value = ''; $('info').value = '';
  };
  $('sub').onchange = function () {
    fill($('prod'), prods.filter(function (p) { return p.subcategory_id == $('sub').value; }), '-- select --');
  };
  $('prod').onchange = function () {
    var p = prods.find(function (x) { return x.id == $('prod').value; }); if (!p) return;
    $('rate').value = MODE === 'purchase' ? p.purchase_rate : p.sales_rate;
    if ($('srate')) $('srate').value = p.sales_rate;
    $('info').value = p.unit + ' · stock ' + p.stock_qty;
  };
  function inr(n) { return '₹ ' + n.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
  function calc() {
    var sub = lines.reduce(function (a, l) { return a + l.qty * l.rate; }, 0);
    var d = num('disc'), g = num('gst'), paid = num('paid'), extra = num('labour') + num('transport');
    var after = sub * (1 - d / 100), total = Math.round((Math.round(after * (1 + g / 100) * 100) / 100 + extra) * 100) / 100;
    $('t_sub').textContent = inr(sub); $('t_disc').textContent = inr(after);
    if ($('t_extra')) $('t_extra').textContent = inr(extra);
    $('t_total').textContent = inr(total);
    if ($('t_bal')) $('t_bal').textContent = inr(Math.max(total - paid, 0));
    if ($('t_paid')) $('t_paid').textContent = inr(total);
    return total;
  }
  function draw() {
    var tb = document.querySelector('#lines tbody'); tb.innerHTML = '';
    lines.forEach(function (l, i) {
      var tr = document.createElement('tr');
      tr.innerHTML = '<td>' + (i + 1) + '</td><td>' + esc(l.cat) + '</td><td>' + esc(l.name) + '</td><td class="r">' + l.qty + ' ' + esc(l.unit) + '</td><td class="r">' + inr(l.rate) + '</td>' +
        (MODE === 'purchase' ? '<td class="r">' + inr(l.srate) + '</td>' : '') + '<td class="r">' + inr(l.qty * l.rate) + '</td>' +
        '<td><button type="button" class="btn sm danger" data-i="' + i + '">✕</button>' +
        '<input type="hidden" name="items[' + i + '][product_id]" value="' + l.pid + '">' +
        '<input type="hidden" name="items[' + i + '][qty]" value="' + l.qty + '">' +
        '<input type="hidden" name="items[' + i + '][rate]" value="' + l.rate + '">' +
        '<input type="hidden" name="items[' + i + '][srate]" value="' + l.srate + '"></td>';
      tb.appendChild(tr);
    });
    calc();
  }
  document.querySelector('#lines').onclick = function (ev) {
    if (ev.target.dataset.i !== undefined) { lines.splice(+ev.target.dataset.i, 1); draw(); }
  };
  $('add').onclick = function () {
    var p = prods.find(function (x) { return x.id == $('prod').value; });
    var qtyRaw = $('qty').value.trim(), rateRaw = $('rate').value.trim();
    var qty = parseInt(qtyRaw, 10), rate = parseFloat(rateRaw);
    if (!p) return alert('Select a product.');
    if (!/^\d+$/.test(qtyRaw) || !(qty > 0)) return alert('Quantity must be a whole number greater than zero.');
    if (!(rate > 0) || decimals(rateRaw) > 2) return alert('Enter a valid rate greater than zero (max 2 decimals).');
    var srate = rate;
    if ($('srate')) { srate = parseFloat($('srate').value); if (!(srate > 0) || decimals($('srate').value) > 2) return alert('Enter a valid sales price greater than zero.'); }
    var already = lines.filter(function (l) { return l.pid == p.id; }).reduce(function (a, l) { return a + l.qty; }, 0);
    if ((MODE === 'sale' || MODE === 'cash') && already + qty > p.stock_qty) return alert('Only ' + p.stock_qty + ' ' + p.unit + ' in stock.');
    var cat = $('cat').options[$('cat').selectedIndex].textContent;
    lines.push({pid: p.id, name: p.name, cat: cat, unit: p.unit, qty: qty, rate: rate, srate: srate});
    $('qty').value = 1; draw();
  };
  ['disc', 'gst', 'paid', 'labour', 'transport'].forEach(function (id) { if ($(id)) $(id).oninput = calc; });

  // sale: picking a customer pre-selects the vendor who referred them; picking a vendor shows its default commission
  if (MODE === 'sale') {
    $('party').onchange = function () {
      var o = $('party').options[$('party').selectedIndex];
      $('vendor').value = (o && o.dataset.vendor && o.dataset.vendor !== '0') ? o.dataset.vendor : '';
      $('vendor').onchange();
    };
    $('vendor').onchange = function () {
      var o = $('vendor').options[$('vendor').selectedIndex];
      if ($('commpct')) { $('commpct').value = ''; $('commpct').placeholder = o && o.value ? 'Default ' + o.dataset.pct + '%' : 'Select a vendor first'; $('commpct').disabled = !(o && o.value); }
    };
    $('vendor').onchange();
  }
  // quotation: choosing a regular customer hides the manual name/mobile boxes
  if (MODE === 'quote') {
    $('party').onchange = function () {
      var on = !$('party').value;
      $('nameBox').style.display = on ? '' : 'none'; $('mobBox').style.display = on ? '' : 'none';
    };
  }

  $('billform').onsubmit = function (ev) {
    function stop(msg) { ev.preventDefault(); alert(msg); return false; }
    if ((MODE === 'sale' || MODE === 'purchase') && !$('party').value) return stop('Select a ' + <?= json_encode(strtolower($c['party'])) ?> + '.');
    if (MODE === 'quote' && !$('party').value && !$('cname').value.trim()) return stop('Enter the customer name or pick a regular customer.');
    if ($('cname') && $('cname').value.trim() && !/^[A-Za-zÀ-ɏऀ-ॿ][A-Za-zÀ-ɏऀ-ॿ .'\-]+$/.test($('cname').value.trim())) return stop('Customer name may contain letters only (no digits or symbols).');
    if ($('cmob') && $('cmob').value && !/^[6-9]\d{9}$/.test($('cmob').value)) return stop('Mobile No must be exactly 10 digits and start with 6, 7, 8 or 9.');
    if (!$('bdate').value) return stop('Enter a valid date.');
    if (!lines.length) return stop('Add at least one product.');
    if (num('disc') < 0 || num('disc') > 100 || num('gst') < 0 || num('gst') > 100) return stop('Discount and GST must be between 0 and 100.');
    if (num('labour') < 0 || num('transport') < 0) return stop('Labour and transport charges cannot be negative.');
    var total = calc();
    if ($('paid') && num('paid') > total + 0.005) return stop('Paid amount cannot exceed the total.');
    if ($('commpct') && $('commpct').value !== '' && (num('commpct') < 0 || num('commpct') > 100)) return stop('Commission must be between 0 and 100.');
  };
})();
</script>
<?php }

<?php
require_once __DIR__ . '/functions.php';
require_login();
$page_title = $page_title ?? 'Shree Enterprises';
$cur = basename($_SERVER['SCRIPT_NAME']);
$curm = $_GET['m'] ?? '';
// Grouped menu: [group label, [[label, href, who], ...]]  (a group with one item is a plain link)
$menu = [
    ['Dashboard', [['Dashboard', 'dashboard.php', 'all']]],
    ['Customer', [
        ['Regular Customers', 'master.php?m=customers', 'all'],
        ['Vendors', 'master.php?m=vendors', 'admin'],
        ['Vendor Commissions', 'vendor_commissions.php', 'admin'],
    ]],
    ['Product', [
        ['Products', 'master.php?m=products', 'all'],
        ['Categories', 'master.php?m=categories', 'admin'],
        ['Subcategories', 'master.php?m=subcategories', 'admin'],
        ['Suppliers', 'master.php?m=suppliers', 'admin'],
    ]],
    ['Stock', [
        ['Remaining Stock', 'stock.php', 'all'],
        ['New Purchase', 'purchase_new.php', 'admin'],
        ['Purchase Bills', 'purchases.php', 'admin'],
        ['Dead Stock', 'dead_stock.php', 'admin'],
    ]],
    ['Billing / Payments', [
        ['New Sale / Bill', 'sale_new.php', 'all'],
        ['Bills', 'invoices.php', 'all'],
        ['Receive Payment', 'receive_payment.php', 'all'],
        ['New Cash Sale', 'cash_sale_new.php', 'all'],
        ['Cash Sales', 'cash_sales.php', 'all'],
        ['New Quotation', 'quotation_new.php', 'all'],
        ['Quotations', 'quotations.php', 'all'],
        ['Goods Return', 'sales_return.php', 'all'],
    ]],
    ['Reports', [['Reports', 'reports.php', 'admin']]],
    ['Admin', [
        ['Employees', 'master.php?m=employees', 'admin'],
        ['User Master', 'master.php?m=users', 'admin'],
    ]],
];
function menu_active(string $href, string $cur, string $curm): bool {
    if (strpos($href, 'master.php') === 0) return $cur === 'master.php' && $href === 'master.php?m=' . $curm;
    $own = ['vendor_commissions.php' => ['vendor_detail.php'], 'sale_new.php' => ['print_invoice.php'], 'cash_sales.php' => ['print_cash.php'], 'quotations.php' => ['print_quote.php'], 'purchases.php' => ['print_purchase.php']];
    return $href === $cur || in_array($cur, $own[$href] ?? [], true);
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> · Shree Enterprises</title>
<link rel="icon" href="assets/img/favicon.png">
<link rel="stylesheet" href="assets/css/style.css">
</head><body>
<div class="layout">
<aside class="side no-print">
  <div class="brand"><a href="dashboard.php" title="Go to Dashboard"><img src="assets/img/logo.png" alt="Shree Enterprises"></a><span><?= e(SHOP_TAGLINE) ?></span></div>
  <nav>
  <?php foreach ($menu as [$glabel, $items]):
      $items = array_values(array_filter($items, fn($i) => $i[2] !== 'admin' || is_admin()));
      if (!$items) continue;
      if (count($items) === 1): ?>
    <a class="top-link <?= menu_active($items[0][1], $cur, $curm) ? 'active' : '' ?>" href="<?= e($items[0][1]) ?>"><?= e($glabel) ?></a>
  <?php continue; endif;
      $open = false; foreach ($items as $i) if (menu_active($i[1], $cur, $curm)) $open = true; ?>
    <details class="grp" <?= $open ? 'open' : '' ?>><summary><?= e($glabel) ?></summary>
    <?php foreach ($items as [$label, $href]): ?>
      <a class="<?= menu_active($href, $cur, $curm) ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
    <?php endforeach; ?></details>
  <?php endforeach; ?>
  </nav>
  <div class="who"><?= e(user()['full_name']) ?><br><small><?= e(user()['role']) ?></small>
    <a href="logout.php">Logout</a></div>
</aside>
<main>
<header class="top no-print"><h1><?= e($page_title) ?></h1><a href="index.php" target="_blank">View website ↗</a></header>
<?php if ($f = flash()): ?><div class="flash <?= $f[0] ?>"><?= e($f[1]) ?></div><?php endif; ?>
<section class="content">

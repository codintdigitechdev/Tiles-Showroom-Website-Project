<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/printbill.php';
require_admin();
$page_title = 'Purchase Challan';
require __DIR__ . '/includes/header.php';
render_print('purchase');
require __DIR__ . '/includes/footer.php';

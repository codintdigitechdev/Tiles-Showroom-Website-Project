<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/bill.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') handle_bill_post('purchase');
$page_title = 'New Purchase Bill';
require __DIR__ . '/includes/header.php';
render_bill_form('purchase');
require __DIR__ . '/includes/footer.php';

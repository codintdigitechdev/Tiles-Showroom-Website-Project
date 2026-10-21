<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/bill.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') handle_bill_post('sale');
$page_title = 'New Sale / Invoice';
require __DIR__ . '/includes/header.php';
render_bill_form('sale');
require __DIR__ . '/includes/footer.php';

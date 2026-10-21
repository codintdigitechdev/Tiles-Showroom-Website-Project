<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/bill.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') handle_bill_post('cash');
$page_title = 'New Cash Sale';
require __DIR__ . '/includes/header.php';
render_bill_form('cash');
require __DIR__ . '/includes/footer.php';

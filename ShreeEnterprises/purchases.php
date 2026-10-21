<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billlist.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') handle_list_post('purchase');
$page_title = 'Purchase Bill List';
require __DIR__ . '/includes/header.php';
render_bill_list('purchase');
require __DIR__ . '/includes/footer.php';

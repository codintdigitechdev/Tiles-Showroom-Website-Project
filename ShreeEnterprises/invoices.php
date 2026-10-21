<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billlist.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') handle_list_post('sale');
$page_title = 'Invoice List';
require __DIR__ . '/includes/header.php';
render_bill_list('sale');
require __DIR__ . '/includes/footer.php';

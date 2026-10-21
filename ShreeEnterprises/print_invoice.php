<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/printbill.php';
require_login();
$page_title = 'Bill';
require __DIR__ . '/includes/header.php';
render_print('sale');
require __DIR__ . '/includes/footer.php';

<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/extras.php';
require_login();

$page_title = 'Quotation';
require __DIR__ . '/includes/header.php';
render_simple_print('quote');
require __DIR__ . '/includes/footer.php';

<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/extras.php';
require_login();

$page_title = 'Cash Memo';
require __DIR__ . '/includes/header.php';
render_simple_print('cash');
require __DIR__ . '/includes/footer.php';

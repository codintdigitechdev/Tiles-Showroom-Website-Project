<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/extras.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') handle_simple_list_post('cash');
$page_title = 'Cash Sales (no bill)';
require __DIR__ . '/includes/header.php';
render_simple_list('cash');
require __DIR__ . '/includes/footer.php';

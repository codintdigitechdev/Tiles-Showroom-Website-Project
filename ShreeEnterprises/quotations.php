<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/extras.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') handle_simple_list_post('quote');
$page_title = 'Quotations';
require __DIR__ . '/includes/header.php';
render_simple_list('quote');
require __DIR__ . '/includes/footer.php';

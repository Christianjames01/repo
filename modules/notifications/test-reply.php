<?php
// test-reply.php
// Place at: modules/notifications/test-reply.php
// Visit directly in browser: yourdomain.com/modules/notifications/test-reply.php
// DELETE this file after testing.

ob_clean();
header('Content-Type: application/json');
echo json_encode(['reachable' => true, 'method' => $_SERVER['REQUEST_METHOD']]);
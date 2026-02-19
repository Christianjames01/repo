<?php
session_start();
echo "<pre>";
echo "POST Data:\n";
print_r($_POST);
echo "\n\nSESSION Data:\n";
print_r($_SESSION);
echo "</pre>";
exit;
?>
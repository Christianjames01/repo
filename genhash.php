<?php
$password = 'Promogod0915';
$hash = password_hash($password, PASSWORD_DEFAULT);
echo $hash;
?>
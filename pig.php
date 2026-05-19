<?php
$key = 'live';
$s = hash_hmac('sha256', $key, 'living');
echo $s;
?>
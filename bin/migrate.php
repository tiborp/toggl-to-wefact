<?php
declare(strict_types=1);

// Run schema updates explicitly; daily web startup must never migrate the administration.
$argv=[__FILE__,'migrate'];require __DIR__.'/admin.php';

<?php
/** Run separately: check_csrf exits on rejection. */
$root = $argv[1] ?? __DIR__ . '/../Ehtiya/htdocs';
$src = file_get_contents($root . '/includes/bootstrap.php');
$a = strpos($src, 'function check_csrf(): void');
$b = strpos($src, '/** Enforce POST-only', $a);
if ($a === false || $b === false) { exit(2); }
eval(substr($src, $a, $b - $a));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = [];
$_POST = ['csrf' => ''];
register_shutdown_function(static function (): void {
    echo "\nHTTP_STATUS=" . http_response_code() . "\n";
});
check_csrf();
echo "UNEXPECTED_ACCEPTANCE\n";

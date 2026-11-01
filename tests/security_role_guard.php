<?php
/** Isolated role-guard checks using synthetic users; no database or host access. */
$source = file_get_contents(__DIR__ . '/../Ehtiya/htdocs/includes/bootstrap.php');
$start = strpos($source, 'function need_login(): void');
$end = strpos($source, '/** Lock and re-check the student account', $start);
if ($start === false || $end === false) { exit(2); }

$scenario = $argv[1] ?? '';
$cases = [
    'student-allowed' => [['role' => 'student', 'status' => 'active'], 'student', 200],
    'admin-allowed' => [['role' => 'admin', 'status' => 'active'], 'admin', 200],
    'student-denied-admin' => [['role' => 'student', 'status' => 'active'], 'admin', 403],
    'admin-denied-student' => [['role' => 'admin', 'status' => 'active'], 'student', 403],
    'anonymous-redirect' => [null, 'student', 302],
];
if (!isset($cases[$scenario])) { exit(2); }
[$fakeUser, $requiredRole, $expectedStatus] = $cases[$scenario];
function me(): ?array { global $fakeUser; return $fakeUser; }
function go(string $path): void { http_response_code(302); exit; }
eval(substr($source, $start, $end - $start));
register_shutdown_function(static function () use ($expectedStatus, $scenario): void {
    $status = http_response_code() ?: 200;
    if ($status !== $expectedStatus) {
        fwrite(STDERR, "FAIL: $scenario expected $expectedStatus got $status\n");
        exit(1);
    }
    echo "\nPASS: $scenario status $status\n";
});
need_role($requiredRole);

<?php
/** Isolated unit checks; this deliberately does not bootstrap a database connection. */
$root = $argv[1] ?? __DIR__ . '/../Ehtiya/htdocs';
$source = file_get_contents($root . '/includes/bootstrap.php');
if ($source === false) { fwrite(STDERR, "Cannot read bootstrap\n"); exit(1); }
function importFunction(string $source, string $start, string $end): void {
    $a = strpos($source, $start);
    $b = $a === false ? false : strpos($source, $end, $a);
    if ($a === false || $b === false) { fwrite(STDERR, "Missing helper\n"); exit(1); }
    eval(substr($source, $a, $b - $a));
}
importFunction($source, 'function csrf(): string', '/** Validate the submitted CSRF');
importFunction($source, 'function check_csrf(): void', '/** Enforce POST-only');
importFunction($source, 'function me(): ?array', '/** Require an authenticated');
class SecurityFakeStatement {
    public function __construct(private array $record) {}
    public function execute(array $parameters): bool { return true; }
    public function fetch(): array { return $this->record; }
}
class SecurityFakePDO {
    public function __construct(public array $record) {}
    public function prepare(string $sql): SecurityFakeStatement {
        if (!str_contains($sql, 'password_hash')) { throw new RuntimeException('Password version is not fetched'); }
        return new SecurityFakeStatement($this->record);
    }
}
function check(bool $pass, string $description): void {
    if (!$pass) { fwrite(STDERR, "FAIL: $description\n"); exit(1); }
    echo "PASS: $description\n";
}
session_start();
$_SESSION = [];
$token = csrf();
check(strlen($token) === 64 && csrf() === $token, 'CSRF token generated and stable');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf' => $token];
check_csrf();
check(true, 'valid CSRF accepted');
$hash = password_hash('test-only-password', PASSWORD_DEFAULT);
$pdo = new SecurityFakePDO(['id'=>7, 'role'=>'student', 'status'=>'active', 'password_hash'=>$hash]);
$_SESSION = ['uid'=>7, 'password_fingerprint'=>hash('sha256', $hash), 'auth_role'=>'student'];
$user = me();
check(is_array($user) && !isset($user['password_hash']), 'active session accepted without returning hash');
$pdo->record['password_hash'] = password_hash('new-test-password', PASSWORD_DEFAULT);
check(me() === null && !isset($_SESSION['uid']), 'changed password revokes old fingerprint');
$_SESSION = ['uid'=>7, 'password_fingerprint'=>hash('sha256', $pdo->record['password_hash']), 'auth_role'=>'student'];
$pdo->record['status'] = 'suspended';
check(me() === null && !isset($_SESSION['uid']), 'suspended account loses session');

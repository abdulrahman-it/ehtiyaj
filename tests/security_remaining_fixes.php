<?php
/** Isolated tests using application source and fake PDO; not an HTTP/MySQL integration test. */
$root = __DIR__ . '/../Ehtiya/htdocs';
$bootstrap = file_get_contents($root . '/includes/bootstrap.php');
function source_part(string $source, string $start, string $end): string {
    $a = strpos($source, $start);
    $b = $a === false ? false : strpos($source, $end, $a);
    if ($a === false || $b === false) throw new RuntimeException('Missing source marker: ' . $start);
    return substr($source, $a, $b - $a);
}
eval(source_part($bootstrap, 'function check_csrf(): void', '/** Store one escaped-on-render'));
eval(source_part($bootstrap, 'function me(): ?array', '/** Lock and re-check the student account'));

class RedirectForTest extends RuntimeException {}
function go(string $path): void { throw new RedirectForTest($path); }
function input_int(string $name): int { return (int) ($_POST[$name] ?? 0); }
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'] = [$message, $type]; }
class TestPDO {
    public array $users = [];
    public array $notifications = [];
    public function prepare(string $sql): TestStatement { return new TestStatement($this, $sql); }
}
class TestStatement {
    private int $changed = 0;
    private array $params = [];
    public function __construct(private TestPDO $db, private string $sql) {}
    public function execute(array $params): bool {
        $this->params = $params;
        if (str_starts_with($this->sql, 'UPDATE notifications')) {
            if ($this->sql !== 'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND is_read = 0') {
                throw new RuntimeException('Missing notification ownership or unread condition');
            }
            [$id, $user] = $params;
            if (isset($this->db->notifications[$id]) && $this->db->notifications[$id]['user_id'] === $user
                && $this->db->notifications[$id]['is_read'] === 0) {
                $this->db->notifications[$id]['is_read'] = 1;
                $this->changed = 1;
            }
        } elseif (!str_contains($this->sql, 'password_hash FROM users')) {
            throw new RuntimeException('Unexpected SQL');
        }
        return true;
    }
    public function fetch() { return $this->db->users[$this->params[0]] ?? false; }
    public function rowCount(): int { return $this->changed; }
}
function check(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    echo 'PASS: ' . $name . "\n";
}
function session_for(int $user, string $role = 'student', string $hash = 'test-hash'): void {
    $_SESSION = ['uid'=>$user, 'auth_role'=>$role, 'password_fingerprint'=>hash('sha256', $hash), 'csrf'=>'token'];
}

session_start();
$pdo = new TestPDO();
$pdo->users = [7=>['id'=>7, 'role'=>'student', 'status'=>'active', 'password_hash'=>'test-hash'],
               8=>['id'=>8, 'role'=>'admin', 'status'=>'active', 'password_hash'=>'test-hash']];
$pdo->notifications = [42=>['user_id'=>7,'is_read'=>0], 43=>['user_id'=>8,'is_read'=>0], 44=>['user_id'=>7,'is_read'=>1]];
$source = file_get_contents($root . '/notifications/index.php');
$post = source_part($source, "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {", "\n\n\$count =");
$_SERVER['REQUEST_METHOD'] = 'POST';
$csrfCase = $argv[1] ?? '';
if (in_array($csrfCase, ['csrf-missing', 'csrf-invalid'], true)) {
    session_for(7);
    $_POST = ['id'=>'42'];
    if ($csrfCase === 'csrf-invalid') $_POST['csrf'] = 'incorrect';
    register_shutdown_function(static function () use ($pdo, $csrfCase): void {
        if (http_response_code() !== 419 || $pdo->notifications[42]['is_read'] !== 0 || isset($_SESSION['flash'])) {
            fwrite(STDERR, 'FAIL: ' . $csrfCase . "\n");
            return;
        }
        echo "\nPASS: " . $csrfCase . " rejected before update\n";
    });
    eval($post);
    throw new RuntimeException('CSRF rejection unexpectedly returned');
}
$cases = [
    ['own-unread', 7, 'student', 42, 'success', '/notifications/index.php'],
    ['already-read', 7, 'student', 44, 'warning', '/notifications/index.php'],
    ['other-user', 7, 'student', 43, 'warning', '/notifications/index.php'],
    ['missing', 7, 'student', 999, 'warning', '/notifications/index.php'],
    ['admin-own', 8, 'admin', 43, 'success', '/admin/notifications.php'],
];
$warnings = [];
foreach ($cases as [$name, $uid, $role, $id, $type, $redirect]) {
    session_for($uid, $role);
    $_POST = ['id'=>(string)$id, 'csrf'=>'token'];
    $destination = null;
    try { eval($post); } catch (RedirectForTest $e) { $destination = $e->getMessage(); }
    check($destination === $redirect && ($_SESSION['flash'][1] ?? '') === $type, $name . ' feedback and redirect');
    if ($type === 'warning') $warnings[] = $_SESSION['flash'][0];
    if ($name === 'own-unread') check($pdo->notifications[42]['is_read'] === 1, 'owned unread updated');
    if ($name === 'other-user') check($pdo->notifications[43]['is_read'] === 0, 'other user notification unchanged');
}
check(count(array_unique($warnings)) === 1, 'unavailable, foreign and already-read messages are identical');

session_for(7);
check(me()['id'] === 7, 'active account accepted');
$pdo->users[7]['status'] = 'suspended';
try { need_role('student'); throw new RuntimeException('Suspended account was allowed'); }
catch (RedirectForTest $e) { check($e->getMessage() === '/auth/login.php' && !isset($_SESSION['uid']), 'suspended session revoked and protected route redirected'); }
$pdo->users[7]['status'] = 'active';
session_for(7, 'student', 'old-hash');
check(me() === null && !isset($_SESSION['uid']), 'changed password fingerprint revokes session');
$login = file_get_contents($root . '/auth/login.php');
check(str_contains($login, "if (\$user && \$user['status'] === 'active' && \$passwordMatches)"), 'login retains active-account check (static)');
foreach (['student/index.php', 'student/profile.php', 'student/account-status.php', 'requests/view.php', 'chat/view.php'] as $page) {
    check(str_contains(file_get_contents($root . '/' . $page), "need_role('student');"), $page . ' remains student-protected (static)');
}

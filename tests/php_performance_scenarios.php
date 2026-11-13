<?php
/** Isolated source-level render scenarios with a fake PDO; never loads application config. */
$root = __DIR__ . '/../Ehtiya/htdocs';
$scenario = $argv[1] ?? '';
$bootstrap = file_get_contents($root . '/includes/bootstrap.php');
function part(string $source, string $start, string $end): string {
    $a = strpos($source, $start);
    $b = $a === false ? false : strpos($source, $end, $a);
    if ($a === false || $b === false) { throw new RuntimeException("Missing source marker: $start / $end"); }
    return substr($source, $a, $b - $a);
}
eval(part($bootstrap, 'function me(): ?array', '/** Require an authenticated'));

class FakeStatement {
    private array $params = [];
    private int $changed = 0;
    public function __construct(private FakePDO $db, private string $sql) {}
    public function execute(array $params = []): bool {
        $this->params = $params; $this->db->executions[] = $this->sql;
        if (str_starts_with($this->sql, 'UPDATE notifications SET is_read')) {
            if ($params[0] === 42 && ($this->db->unread[$params[1]] ?? 0) > 0) {
                $this->db->unread[$params[1]]--;
                $this->changed = 1;
            }
        }
        return true;
    }
    public function rowCount(): int { return $this->changed; }
    public function fetch() {
        if (str_contains($this->sql, 'password_hash FROM users')) return $this->db->user;
        if (str_contains($this->sql, 'SELECT id FROM offers')) return $this->db->hasOffer ? ['id'=>77] : false;
        throw new RuntimeException('Unexpected fetch: ' . $this->sql);
    }
    public function fetchAll(): array {
        if (str_contains($this->sql, 'FROM offers o JOIN users')) return $this->db->offers;
        throw new RuntimeException('Unexpected fetchAll: ' . $this->sql);
    }
    public function fetchColumn(): int {
        if (str_contains($this->sql, 'COUNT(*) FROM notifications')) return $this->db->unread[$this->params[0]] ?? 0;
        throw new RuntimeException('Unexpected fetchColumn: ' . $this->sql);
    }
}
class FakePDO {
    public array $executions = [];
    public array $unread = [7=>2, 8=>5];
    public array $offers = [];
    public bool $hasOffer = false;
    public array $user = ['id'=>7, 'role'=>'student', 'status'=>'active', 'password_hash'=>'fake-hash'];
    public function prepare(string $sql): FakeStatement { return new FakeStatement($this, $sql); }
}
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function input_int(string $name): int { return 42; }
function flash(string $message, string $type = 'success'): void {}
function csrf(): string { return 'test-token'; }
function ui_context_url(string $path, array $context): string { return $path; }
function app_url(string $path): string { return '/' . $path; }
function ensure(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }

session_start();
$pdo = new FakePDO();
$_SESSION = ['uid'=>7, 'auth_role'=>'student', 'password_fingerprint'=>hash('sha256','fake-hash')];
if (str_starts_with($scenario, 'chat')) {
    $source = file_get_contents($root . '/chat/view.php');
    $fragment = part($source, '<div class="d-flex flex-column gap-3">', '<?php }if (!$messages)');
    $messages = $scenario === 'chat-empty' ? [] : [
        ['sender_id'=>7, 'body'=>'my message', 'image_path'=>'a', 'id'=>1, 'created_at'=>'2026-01-01'],
        ['sender_id'=>8, 'body'=>'other message', 'image_path'=>null, 'id'=>2, 'created_at'=>'2026-01-02'],
        ['sender_id'=>7, 'body'=>'second message', 'image_path'=>'b', 'id'=>3, 'created_at'=>'2026-01-03'],
    ];
    ob_start(); eval('?>' . $fragment . '<?php } ?>'); $html = ob_get_clean();
    ensure(substr_count($html, 'class="message mine"') === ($messages ? 2 : 0), 'mine message classification');
    ensure(substr_count($html, 'class="message-image"') === ($messages ? 2 : 0), 'image visibility');
    echo json_encode(['scenario'=>$scenario, 'queries'=>count($pdo->executions), 'html_sha256'=>hash('sha256',$html)]), "\n";
} elseif (str_starts_with($scenario, 'request')) {
    $source = file_get_contents($root . '/requests/view.php');
    $block = part($source, '$offers = [];', "header_ui('تفاصيل الطلب')");
    if (($argv[2] ?? '') === 'baseline') {
        $replacement = <<<'OLD'
}$mineOffer = $pdo->prepare('SELECT id FROM offers WHERE request_id=? AND student_id=?');
$mineOffer->execute([$id,me()['id']]);
$already = (bool)$mineOffer->fetch();
OLD;
        $start = strpos($block, "}\n\$already = false;");
        if ($start === false) throw new RuntimeException('Optimized offer block missing');
        $block = substr($block, 0, $start) . $replacement;
    }
    $mine = in_array($scenario, ['request-owner', 'request-owner-empty'], true); $id = 11;
    $pdo->offers = $scenario === 'request-owner-empty' ? [] : [['id'=>12, 'status'=>'submitted']];
    $pdo->hasOffer = $scenario === 'request-visitor-has';
    eval($block);
    ensure($mine ? count($offers) === count($pdo->offers) : $already === $pdo->hasOffer, 'offer ownership/result');
    $queryCount = count($pdo->executions);
    $r = ['status'=>'receiving_offers']; $requestChatContext = [];
    $panel = part($source, '<aside class="request-action-panel"', '</aside>');
    ob_start(); eval('?>' . $panel); $html = ob_get_clean();
    ensure($mine ? str_contains($html, 'إدارة الطلب') :
        ($pdo->hasOffer ? str_contains($html, 'أرسلت عرضك بالفعل') : str_contains($html, 'قدّم عرض مساعدة')), 'request action output');
    echo json_encode(['scenario'=>$scenario, 'queries'=>$queryCount, 'already'=>$already, 'offers'=>count($offers), 'panel_sha256'=>hash('sha256', $html)]), "\n";
} elseif (str_starts_with($scenario, 'dashboard') || str_starts_with($scenario, 'header-')) {
    // Exercise the actual initial PHP portion of the shared header, with its real signature.
    $header = part($bootstrap, 'function header_ui(', '    $currentAdminPath =');
    $header = str_replace('): void', '): int', $header) . "    return \$unreadCount;\n}";
    eval($header);
    if (str_starts_with($scenario, 'header-')) {
        if ($scenario === 'header-anonymous') {
            $_SESSION = [];
            $count = header_ui('test', false, ['user_id'=>7, 'count'=>99]);
            ensure($count === 0 && count($pdo->executions) === 0, 'anonymous header ignores snapshot');
        } elseif ($scenario === 'header-mismatch') {
            $count = header_ui('test', false, ['user_id'=>8, 'count'=>5]);
            ensure($count === 2 && count($pdo->executions) === 2, 'mismatched user must query own count');
        } elseif ($scenario === 'header-other-user') {
            $pdo->user['id'] = 8; $_SESSION['uid'] = 8;
            $count = header_ui('test', false, ['user_id'=>8, 'count'=>5]);
            ensure($count === 5 && count($pdo->executions) === 1, 'matching second user snapshot');
        } else { throw new RuntimeException('Unknown header scenario'); }
        echo json_encode(['scenario'=>$scenario, 'queries'=>count($pdo->executions), 'count'=>$count]), "\n";
        exit;
    }
    $source = file_get_contents($root . '/student/index.php');
    $dashboard = part($source, '$unreadStatement =', '?>');
    $dashboard = str_replace("header_ui('مساحة الطالب');", "\$headerCount = header_ui('مساحة الطالب');", $dashboard);
    // Also handle the optimized call while recording its return value.
    $dashboard = preg_replace('/(?<![\\w$])header_ui\(([^;]+)\);/', '\$headerCount = header_ui($1);', $dashboard);
    $studentId = 7;
    if ($scenario === 'dashboard-zero') $pdo->unread[7] = 0;
    if ($scenario === 'dashboard-read') {
        $pdo->unread[7] = 1;
        $inbox = file_get_contents($root . '/notifications/index.php');
        eval(part($inbox, "        \$statement = \$pdo->prepare('UPDATE notifications", "        if (\$statement->rowCount() === 1)"));
        ensure($pdo->unread[7] === 0 && $pdo->unread[8] === 5, 'mark read must stay user scoped');
        $pdo->executions = []; // model a fresh request after the redirect
    }
    eval($dashboard);
    ensure($headerCount === $unreadNotifications && $headerCount === $pdo->unread[7], 'dashboard and header counts');
    echo json_encode(['scenario'=>$scenario, 'queries'=>count($pdo->executions), 'count'=>$headerCount]), "\n";
} elseif (in_array($scenario, ['anonymous', 'invalid-session', 'inactive-session'], true)) {
    if ($scenario === 'anonymous') $_SESSION = [];
    elseif ($scenario === 'invalid-session') $_SESSION['password_fingerprint'] = hash('sha256', 'old-password');
    else $pdo->user['status'] = 'suspended';
    ensure(me() === null && !isset($_SESSION['uid']), 'anonymous or revoked session');
    echo json_encode(['scenario'=>$scenario, 'queries'=>count($pdo->executions)]), "\n";
} else { throw new RuntimeException('Unknown scenario'); }

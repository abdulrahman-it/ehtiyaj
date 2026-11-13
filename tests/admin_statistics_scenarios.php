<?php
/** Dashboard query orchestration against fixtures, not MySQL, HTTP, or a concurrency test. */
$source = file_get_contents(__DIR__ . '/../Ehtiya/htdocs/admin/index.php');
$start = strpos($source, '$metricGroups =');
$end = strpos($source, "header_ui('لوحة الإدارة');", $start);
if ($start === false || $end === false) throw new RuntimeException('Metric block missing');
$block = substr($source, $start, $end - $start);
function checkMetric(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . "\n";
}
class MetricsStatement {
    public function __construct(private $row) {}
    public function fetch() { return $this->row; }
}
class MetricsPDO {
    public array $queries = [];
    public string $fail = '';
    public bool $missingResult = false;
    public array $students = [], $requests = [], $offers = [], $reports = [], $conversations = [];
    public function query(string $sql) {
        $this->queries[] = $sql;
        if (str_contains($sql, ' JOIN ')) throw new RuntimeException('Unexpected join');
        if (str_contains($sql, 'FROM users')) {
            $group = 'students';
            $rows = array_filter($this->students, fn($r) => $r['role'] === 'student');
            $result = ['total' => count($rows), 'active' => count(array_filter($rows, fn($r) => $r['status'] === 'active')), 'suspended' => count(array_filter($rows, fn($r) => $r['status'] === 'suspended')), 'deactivated' => count(array_filter($rows, fn($r) => $r['status'] === 'deactivated'))];
        } elseif (str_contains($sql, 'FROM requests')) {
            $group = 'requests';
            $result = ['working' => count(array_filter($this->requests, fn($r) => in_array($r['status'], ['receiving_offers','in_progress'], true) && $r['moderation_status'] === 'published')), 'completed' => count(array_filter($this->requests, fn($r) => $r['status'] === 'completed')), 'cancelled' => count(array_filter($this->requests, fn($r) => $r['status'] === 'cancelled'))];
        } elseif (str_contains($sql, 'FROM offers')) {
            $group = 'offers'; $result = ['total' => count($this->offers)];
        } elseif (str_contains($sql, 'FROM conversation_reports')) {
            $group = 'reports'; $result = ['open_count' => count(array_filter($this->reports, fn($r) => $r['status'] === 'open'))];
        } elseif (str_contains($sql, 'FROM conversations')) {
            $group = 'conversations'; $result = ['total' => count($this->conversations)];
        } else throw new RuntimeException('Unexpected query');
        if ($this->fail === $group) {
            if ($this->missingResult) return new MetricsStatement(false);
            throw new RuntimeException('Simulated error');
        }
        return new MetricsStatement($result);
    }
}
function runMetrics(string $block, MetricsPDO $pdo): array {
    http_response_code(200); eval($block);
    return [$counts, $metricsError, $metrics, $secondaryMetrics, http_response_code()];
}
$pdo = new MetricsPDO();
$pdo->students = [['role'=>'student','status'=>'active'],['role'=>'student','status'=>'suspended'],['role'=>'student','status'=>'deactivated'],['role'=>'admin','status'=>'active']];
$pdo->requests = [['status'=>'receiving_offers','moderation_status'=>'published'],['status'=>'in_progress','moderation_status'=>'published'],['status'=>'in_progress','moderation_status'=>'removed'],['status'=>'completed','moderation_status'=>'removed'],['status'=>'completed','moderation_status'=>'published'],['status'=>'cancelled','moderation_status'=>'removed']];
$pdo->offers = [[],[],[]]; $pdo->reports = [['status'=>'open'],['status'=>'closed'],['status'=>'open']]; $pdo->conversations = [[],[]];
[$counts,$error,$metrics,$secondary,$status] = runMetrics($block,$pdo);
checkMetric(!$error && $status === 200 && count($pdo->queries) === 5, 'five grouped queries');
checkMetric($counts['students'] === ['total'=>3,'active'=>1,'suspended'=>1,'deactivated'=>1], 'student roles and active suspended deactivated states');
checkMetric($counts['requests'] === ['working'=>2,'completed'=>2,'cancelled'=>1], 'request states and moderation');
checkMetric($counts['offers']['total']===3 && $counts['reports']['open_count']===2 && $counts['conversations']['total']===2, 'offers open reports and all conversations');
$expectedSql = ["status = 'active'", "status = 'suspended'", "status = 'deactivated'", "role = 'student'", "status IN ('receiving_offers', 'in_progress') AND moderation_status = 'published'", "status = 'completed'", "status = 'cancelled'", "status = 'open'"];
foreach ($expectedSql as $text) checkMetric(str_contains(implode("\n", $pdo->queries), $text), 'SQL condition '.$text);
$empty = new MetricsPDO(); [$emptyCounts,$emptyError] = runMetrics($block,$empty);
checkMetric(!$emptyError && $emptyCounts['students']['total']===0 && $emptyCounts['requests']['working']===0 && $emptyCounts['reports']['open_count']===0, 'empty tables produce genuine zeros');
foreach (['students','requests','offers','reports','conversations'] as $fail) {
    $pdo->queries=[]; $pdo->fail=$fail; $pdo->missingResult=false;
    [$failed,$hasError,$m,$s,$status]=runMetrics($block,$pdo);
    checkMetric($hasError && $status===500 && count($pdo->queries)===5 && count(array_filter($failed[$fail], fn($v)=>$v !== null))===0, $fail.' error produces unavailable, not zero');
}
$pdo->fail='reports'; $pdo->missingResult=true; [$failed,$error]=runMetrics($block,$pdo);
checkMetric($error && $failed['reports']['open_count']===null,'missing result is unavailable');
checkMetric(str_contains($source,"need_role('admin');") && !str_contains($source,"\$_POST") && !str_contains($source,'method="post"'),'admin role and read-only dashboard (static)');
checkMetric(str_contains($source,"\$reportCount === null ? 'عدد البلاغات المفتوحة غير متاح'") && str_contains($source,"\$reportCount === null ? ''"),'failed report count has no false clear state (static)');
echo "PASS: admin statistics scenarios completed (fake PDO)\n";

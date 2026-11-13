<?php
/** Executes admin code fragments with stubs and transactional fake storage; not HTTP/MySQL. */
ob_start();
$root = __DIR__.'/../Ehtiya/htdocs/';
$bootstrap = file_get_contents($root.'includes/bootstrap.php');
function part(string $s, string $a, string $b): string { $i=strpos($s,$a); $j=$i===false?false:strpos($s,$b,$i); if ($i===false||$j===false) throw new RuntimeException('Missing marker '.$a); return substr($s,$i,$j-$i); }
eval(part($bootstrap, 'function check_csrf(): void', '/** Store one escaped-on-render'));
eval(part($bootstrap, 'function status_label(string $status): string', '/** Return the current authenticated'));
define('BASE_URL','/sub');
function app_url(string $p): string { return BASE_URL.'/'.ltrim($p,'/'); }
require $root.'admin/includes/navigation.php';
function e($s): string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function input_string(string $key,string $source='post',string $default=''): string { $v=($source==='get'?$_GET:$_POST)[$key]??$default; return is_string($v)?$v:$default; }
function input_int(string $key,string $source='post',int $default=0): int { return (int)input_string($key,$source,(string)$default); }
function optional_input_string(string $key,string $source='post'): ?string { $v=($source==='get'?$_GET:$_POST)[$key]??null; return is_string($v)?$v:null; }
function is_active_major(string $m): bool { return in_array($m,['A','B'],true); }
function me(): array { return ['id'=>1,'role'=>'admin']; }
function audit(...$args): void { global $pdo; $pdo->events[]='audit'; }
function notify(...$args): void { global $pdo; $pdo->events[]='notify'; }
function flash(string $s,string $type='success'): void { $GLOBALS['flash']=[$s,$type]; }
class TestRedirect extends RuntimeException {}
function go(string $path): void { throw new TestRedirect(BASE_URL.$path); }
function pagination_state(int $n): array { return ['total'=>$n,'page'=>1,'per_page'=>20,'offset'=>0]; }
function check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException('FAIL '.$label); echo 'PASS: '.$label."\n"; }
class AdminFakePDO {
    public array $users=[], $types=[], $suggestions=[], $events=[], $snapshot=[]; public bool $transaction=false, $fail=false, $failCommit=false, $failQuery=false; public int $rollbackCalls=0, $matchCount=0, $totalCount=0; public array $rows=[];
    function beginTransaction(): bool { $this->snapshot=[$this->users,$this->types,$this->suggestions,$this->events]; $this->transaction=true; return true; }
    function inTransaction(): bool { return $this->transaction; }
    function commit(): bool { if ($this->failCommit) return false; $this->transaction=false; return true; }
    function rollBack(): bool { $this->rollbackCalls++; [$this->users,$this->types,$this->suggestions,$this->events]=$this->snapshot; $this->transaction=false; return true; }
    function prepare(string $sql): AdminFakeStatement { if ($this->failQuery) throw new PDOException('simulated query failure'); return new AdminFakeStatement($this,$sql); }
    function query(string $sql): AdminFakeStatement { $stmt=$this->prepare($sql); $stmt->execute(); return $stmt; }
    function lastInsertId(): string { return '3'; }
}
class AdminFakeStatement {
    private $row=false; private $column=false; private $count=0;
    function __construct(private AdminFakePDO $db,private string $sql) {}
    function bindValue(...$args): bool { return true; }
    function execute(array $p=[]): bool {
        $db=$this->db; $s=$this->sql; if ($db->fail) throw new PDOException('simulated write failure');
        if (str_starts_with($s,'SELECT COUNT(*) FROM users')) { $this->column=str_contains($s,'LIKE') ? $db->matchCount : $db->totalCount; }
        elseif (str_starts_with($s,'SELECT id,full_name,email,major,status,created_at FROM users')) { }
        elseif (str_starts_with($s,'SELECT status FROM users')) { $this->row=$db->users[$p[0]]??false; }
        elseif (str_starts_with($s,'SELECT major FROM users')) { $this->column=$db->users[$p[0]]['major']??false; }
        elseif (str_starts_with($s,'SELECT id FROM users')) { $this->row=$db->users[$p[0]]??false; }
        elseif (str_starts_with($s,'UPDATE users SET status=')) { [$v,$id]=$p; if (isset($db->users[$id]) && $db->users[$id]['status']!==$v) { $db->users[$id]['status']=$v; $this->count=1; } }
        elseif (str_starts_with($s,'UPDATE users SET major=')) { [$v,$id]=$p; if (isset($db->users[$id]) && $db->users[$id]['major']!==$v) { $db->users[$id]['major']=$v; $this->count=1; } }
        elseif (str_starts_with($s,'INSERT INTO assistance_types')) { $db->types[3]=['name'=>$p[0],'is_active'=>1]; $this->count=1; }
        elseif (str_starts_with($s,'UPDATE assistance_types SET name=')) { [$v,$id]=$p; if (isset($db->types[$id]) && $db->types[$id]['name']!==$v) { $db->types[$id]['name']=$v; $this->count=1; } }
        elseif (str_starts_with($s,'SELECT id FROM assistance_types')) { $this->row=$db->types[$p[0]]??false; }
        elseif (str_starts_with($s,'SELECT is_active FROM assistance_types')) { $this->row=$db->types[$p[0]]??false; }
        elseif (str_starts_with($s,'UPDATE assistance_types SET is_active=')) { [$v,$id]=$p; if (isset($db->types[$id]) && $db->types[$id]['is_active']!==$v) { $db->types[$id]['is_active']=$v; $this->count=1; } }
        elseif (str_starts_with($s,'SELECT * FROM assistance_type_suggestions')) { $v=$db->suggestions[$p[0]]??false; if ($v && $v['status']==='pending') $this->row=$v; }
        elseif (str_starts_with($s,'SELECT id FROM assistance_type_suggestions')) { $this->row=$db->suggestions[$p[0]]??false; }
        elseif (str_starts_with($s,'UPDATE assistance_type_suggestions')) { $id=$p[count($p)-1]; if (isset($db->suggestions[$id]) && $db->suggestions[$id]['status']==='pending') { $db->suggestions[$id]['status']=str_contains($s,"status='accepted'")?'accepted':'rejected'; $this->count=1; } }
        else throw new RuntimeException('Unexpected SQL '.$s);
        return true;
    }
    function fetch() { return $this->row; } function fetchColumn() { return $this->column; } function fetchAll(): array { return $this->db->rows; } function rowCount(): int { return $this->count; }
}
$usersSource=file_get_contents($root.'admin/users.php');
$typesSource=file_get_contents($root.'admin/assistance_types.php');
$usersBlock=part($usersSource,"if (\$_SERVER['REQUEST_METHOD'] === 'POST') {",'// Prepare a paginated');
$typesBlock=part($typesSource,"if (\$_SERVER['REQUEST_METHOD'] === 'POST') {",'$types =');
function runPost(string $block,array $post,array $get=[],array $opts=[]): array {
    global $pdo,$flash; $pdo=new AdminFakePDO(); $pdo->users=$opts['users']??[7=>['status'=>'active','major'=>'A']]; $pdo->types=$opts['types']??[4=>['name'=>'Type','is_active'=>1]]; $pdo->suggestions=$opts['suggestions']??[5=>['status'=>'pending','student_id'=>7,'suggested_name'=>'New']];
    $pdo->fail=$opts['fail']??false; $pdo->failCommit=$opts['failCommit']??false; $flash=null; $_GET=$get; $userStatus=admin_filter_input('user_status'); $_POST=['csrf'=>'token']+$post; $_SESSION=['csrf'=>'token']; $_SERVER['REQUEST_METHOD']='POST'; $redirect=null;
    try { eval($block); } catch (TestRedirect $e) { $redirect=$e->getMessage(); }
    return [$flash,$pdo,$redirect];
}
if (($argv[1] ?? '') === 'search-invalid-post') {
    $pdo=new AdminFakePDO(); $pdo->users=[7=>['status'=>'active','major'=>'A']];
    $_GET=['q'=>['invalid']]; $_POST=['csrf'=>'token','action'=>'status','id'=>'7','status'=>'suspended']; $_SESSION=['csrf'=>'token']; $_SERVER['REQUEST_METHOD']='POST';
    $searchGuard=part($usersSource, "if (array_key_exists('q', \$_GET)", "\n}\n") . "\n}";
    register_shutdown_function(static function () use ($pdo): void {
        if (http_response_code() === 400 && $pdo->users[7]['status'] === 'active' && !$pdo->events) echo "\nPASS: malformed POST search rejected before write\n";
        else echo "\nFAIL: malformed POST search\n";
    });
    eval($searchGuard); eval($usersBlock);
    throw new RuntimeException('Invalid search was not rejected');
}
if (($argv[1] ?? '') === 'csrf-invalid') {
    $pdo=new AdminFakePDO(); $pdo->users=[7=>['status'=>'active','major'=>'A']];
    $_GET=[]; $_POST=['csrf'=>'invalid','action'=>'status','id'=>'7','status'=>'suspended']; $_SESSION=['csrf'=>'token']; $_SERVER['REQUEST_METHOD']='POST';
    register_shutdown_function(static function () use ($pdo): void {
        if (http_response_code() === 419 && $pdo->users[7]['status'] === 'active' && !$pdo->events) echo "\nPASS: admin invalid CSRF rejected before write\n";
        else echo "\nFAIL: admin CSRF rejection\n";
    });
    eval($usersBlock);
    throw new RuntimeException('CSRF check returned unexpectedly');
}
[$f,$d,$url]=runPost($usersBlock,['action'=>'status','id'=>'7','status'=>'suspended'],['q'=>'مرحبا','page'=>'3','per_page'=>'50']);
check($f[1]==='success' && $d->users[7]['status']==='suspended' && count($d->events)===2 && $url===admin_parent_url('users',['q'=>'مرحبا','page'=>'3','per_page'=>'50']), 'user change and context retained');
[$f,$d]=runPost($usersBlock,['action'=>'status','id'=>'7','status'=>'active']); check($f[1]==='warning' && !$d->events && $d->users[7]['status']==='active', 'user unchanged');
[$f,$d]=runPost($usersBlock,['action'=>'status','id'=>'8','status'=>'suspended']); check($f[1]==='warning' && str_contains($f[0],'لم يُعثر') && !$d->events, 'user missing');
[$f,$d]=runPost($usersBlock,['action'=>'status','id'=>'7','status'=>'suspended'],[],['failCommit'=>true]); check($f[1]==='danger' && $d->rollbackCalls===1 && $d->users[7]['status']==='active' && !$d->events, 'user failed commit rolls back');
[$f,$d]=runPost($usersBlock,['action'=>'major','id'=>'7','major'=>'B']); check($f[1]==='success' && $d->users[7]['major']==='B', 'major changed');
[$f,$d]=runPost($usersBlock,['action'=>'major','id'=>'7','major'=>'A']); check($f[1]==='warning' && !$d->events, 'major unchanged');
[$f,$d]=runPost($usersBlock,['action'=>'major','id'=>'8','major'=>'A']); check($f[1]==='warning' && str_contains($f[0],'لم يُعثر'), 'major missing user');
foreach ([['create',['name'=>'New'],'success'],['update',['id'=>'4','name'=>'Type'],'warning'],['update',['id'=>'9','name'=>'Type'],'warning'],['update',['id'=>'4','name'=>'Renamed'],'success'],['toggle',['id'=>'4'],'success'],['toggle',['id'=>'9'],'warning'],['accept',['id'=>'5','type_id'=>'4'],'success'],['reject',['id'=>'5','note'=>''],'success'],['accept',['id'=>'9','type_id'=>'4'],'warning']] as [$action,$data,$type]) { [$f,$d]=runPost($typesBlock,['action'=>$action]+$data); check($f[1]===$type, 'assistance '.$action.' '.json_encode($data).' result'); }
[$f,$d]=runPost($typesBlock,['action'=>'reject','id'=>'5'],[],['suggestions'=>[5=>['status'=>'accepted']]]); check($f[1]==='warning' && str_contains($f[0],'سبق التعامل'), 'already reviewed suggestion');
[$f,$d,$url]=runPost($typesBlock,['action'=>'toggle','id'=>'4'],['page'=>'3','per_page'=>'50']); check($url===admin_parent_url('assistance-types',['page'=>'3','per_page'=>'50']), 'assistance pagination retained');
[$f,$d]=runPost($typesBlock,['action'=>'toggle','id'=>'4'],[],['failCommit'=>true]); check($f[1]==='danger' && $d->rollbackCalls===1 && $d->types[4]['is_active']===1, 'type failed commit rolls back');
$loadBlock=part($usersSource,"\$searchPattern = '%'.\$term.'%';","header_ui('المستخدمون');");
foreach ([[0,0,'',false,'مسجلة'],[0,3,'abc',false,'مطابقة'],[0,0,'abc',false,'مسجلة'],[0,0,'abc',true,'']] as [$match,$total,$term,$fail,$expected]) {
    $pdo=new AdminFakePDO(); $pdo->matchCount=$match; $pdo->totalCount=$total; $pdo->failQuery=$fail; $userStatus=''; eval($loadBlock);
    check($fail ? $loadError && !$emptyMessage : !$loadError && str_contains($emptyMessage,$expected), 'empty/error state '.json_encode([$match,$total,$term,$fail]));
}
foreach (['pending'=>'بانتظار المراجعة','accepted'=>'مقبول','rejected'=>'مرفوض'] as $state=>$label) check(status_label($state)===$label, 'suggestion label '.$state);
check(str_contains($typesSource,"status_label((string)\$s['status'])"), 'suggestion template uses labels (static)');
check(admin_parent_url('users',['q'=>'https://evil.test/','page'=>'3','per_page'=>'50']) === '/sub/admin/users.php?q=https%3A%2F%2Fevil.test%2F&page=3&per_page=50' || str_starts_with(admin_parent_url('users',['q'=>'https://evil.test/','page'=>'3','per_page'=>'50']), '/sub/admin/users.php?'), 'external-like query remains inside local destination');
$dashboard=file_get_contents($root.'admin/index.php'); check(str_contains($dashboard,'جميع الطلبات') && str_contains($dashboard,'جميع البلاغات') && str_contains(file_get_contents($root.'admin/requests.php'),'SELECT COUNT(*) FROM requests') && str_contains(file_get_contents($root.'admin/reports.php'),'SELECT COUNT(*) FROM conversation_reports'), 'dashboard labels match unfiltered lists (static)');
$url=admin_parent_url('https://evil.test/',['q'=>'https://evil.test/','page'=>'abc','per_page'=>'99']); check(str_starts_with($url,'/sub/admin/index.php') && !str_contains($url,'https://evil.test/'), 'unknown external return destination is ignored');
foreach (['users.php','user_view.php','request_view.php','report_view.php','assistance_types.php'] as $file) check(str_contains(file_get_contents($root.'admin/'.$file),"need_role('admin');"), $file.' admin guard present (static)');
foreach (['request_view.php','report_view.php'] as $file) { $s=file_get_contents($root.'admin/'.$file); check(str_contains($s,"admin_detail_url('$file'") && str_contains($s, "'back_page' => input_string('back_page', 'get')") && str_contains($s, "'back_per_page' => input_string('back_per_page', 'get')"), $file.' redirect retains validated pagination (static)'); }
check(str_contains(file_get_contents($root.'admin/user_view.php'), "admin_parent_url('users', ['q' => input_string('back_q', 'get')"), 'user detail action retains search context (static)');
echo "PASS: admin dashboard scenarios completed (fake PDO)\n";

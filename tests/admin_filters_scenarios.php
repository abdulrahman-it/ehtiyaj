<?php
/** Actual query-building fragments exercised with fixtures and fake PDO; not MySQL or HTTP. */
ob_start();
$root=__DIR__.'/../Ehtiya/htdocs/'; $bootstrap=file_get_contents($root.'includes/bootstrap.php');
function part(string $s,string $a,string $b): string { $i=strpos($s,$a); $j=$i===false?false:strpos($s,$b,$i); if ($i===false||$j===false) throw new RuntimeException('Missing '.$a); return substr($s,$i,$j-$i); }
function input_string(string $key,string $source='post',string $default=''): string { $v=($source==='get'?$_GET:$_POST)[$key]??$default; return is_string($v)?$v:$default; }
function e($x): string { return htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
define('BASE_URL','/sub');
function app_url(string $p): string { return BASE_URL.'/'.ltrim($p,'/'); }
function major_catalog(): array { return $GLOBALS['testCatalog'] ?? []; }
function header_ui(string $title): void { echo '<header>'.e($title).'</header>'; }
function footer_ui(): void { echo '<footer></footer>'; }
require $root.'admin/includes/navigation.php';
eval(part($bootstrap,'function pagination_state(', '/** Internal link helper'));
eval(part($bootstrap,'function ui_context_url(', '/** Resolve only known internal destinations'));
function check(bool $v,string $name): void { if (!$v) throw new RuntimeException('FAIL: '.$name); echo 'PASS: '.$name."\n"; }
if (($argv[1]??'')==='major-inactive') {
    $src=file_get_contents($root.'requests/index.php');
    $_GET=['major'=>'old','q'=>'بحث','page'=>'3','per_page'=>'50']; $term='بحث'; $majors=['valid']; $typeId=null;
    $GLOBALS['testCatalog']=[['name'=>'old','active'=>false],['name'=>'valid','active'=>true]];
    $guard=part($src, "if (array_key_exists('major', \$_GET)", '$sql =');
    register_shutdown_function(static function (): void {
        $html=ob_get_contents();
        $ok=http_response_code()===400 && str_contains($html,'لم يعد نشطًا') && str_contains($html,'per_page=50') && str_contains($html,'q=%D8') && !str_contains($html,'major=') && !str_contains($html,'page=3');
        echo $ok ? "\nPASS: inactive major recovery retains search and page size without stale major\n" : "\nFAIL: inactive major recovery\n";
    });
    eval($guard); throw new RuntimeException('Inactive major unexpectedly accepted');
}
if (($argv[1]??'')==='major-invalid') {
    $src=file_get_contents($root.'requests/index.php'); $_GET=['major'=>($argv[2]==='array' ? ['bad'] : 'bad')]; $majors=['valid'];
    $guard=part($src, "if (array_key_exists('major', \$_GET)", '$sql =');
    register_shutdown_function(static function () { echo http_response_code()===400 ? "\nPASS: invalid major rejected 400\n" : "\nFAIL: invalid major accepted\n"; });
    eval($guard); throw new RuntimeException('Invalid major unexpectedly allowed');
}
if (($argv[1]??'')==='search-invalid') {
    $file=$argv[2]; $src=file_get_contents($root.$file); $_GET=['q'=>['bad']];
    $guard=part($src, "if (array_key_exists('q', \$_GET)", "\n}\n") . "\n}";
    register_shutdown_function(static function () { echo http_response_code()===400 ? "\nPASS: non-scalar search rejected 400\n" : "\nFAIL: non-scalar search accepted\n"; });
    eval($guard); throw new RuntimeException('Invalid search unexpectedly allowed');
}
if (($argv[1]??'')==='invalid') {
    $_GET=[$argv[2]=>($argv[3]==='array'?['active']:($argv[3]==='null'?null:$argv[3]))];
    register_shutdown_function(static function () { echo http_response_code()===400 ? "\nPASS: invalid filter rejected 400\n" : "\nFAIL: invalid filter not rejected\n"; });
    admin_filter_input($argv[2]); throw new RuntimeException('Invalid filter unexpectedly allowed');
}
class FilterPDO {
    public array $users=[], $requests=[], $reports=[], $executed=[];
    function prepare(string $sql): FilterStatement { return new FilterStatement($this,$sql); }
    function query(string $sql): FilterStatement { $s=$this->prepare($sql); $s->execute(); return $s; }
}
class FilterStatement {
    private array $bound=[], $matched=[];
    function __construct(private FilterPDO $db,private string $sql) {}
    function bindValue(int $i,$v,$type): bool { $this->bound[$i]=$v; return true; }
    function execute(array $params=[]): bool {
        $p=$params ?: array_values($this->bound); $s=$this->sql; $this->db->executed[]=[$s,$p];
        if (str_contains($s,'FROM users')) {
            $rows=$this->db->users;
            if (str_contains($s,'LIKE ?')) { $term=trim($p[0],'%'); $rows=array_values(array_filter($rows,fn($r)=>$term==='' || str_contains($r['full_name'],$term) || str_contains($r['email'],$term) || str_contains($r['major'],$term))); }
            if (str_contains($s,'AND status = ?')) { $status=$p[3]; $rows=array_values(array_filter($rows,fn($r)=>$r['status']===$status)); }
        } elseif (str_contains($s,'FROM requests r')) {
            $rows=$this->db->requests; $i=0;
            if (str_contains($s,'r.status = ?')) { $v=$p[$i++]; $rows=array_values(array_filter($rows,fn($r)=>$r['status']===$v)); }
            if (str_contains($s,'r.moderation_status = ?')) { $v=$p[$i++]; $rows=array_values(array_filter($rows,fn($r)=>$r['moderation_status']===$v)); }
        } elseif (str_contains($s,'FROM conversation_reports x')) {
            $rows=$this->db->reports;
            if (str_contains($s,'x.status = ?')) { $v=$p[0]; $rows=array_values(array_filter($rows,fn($r)=>$r['status']===$v)); }
        } else throw new RuntimeException('Unexpected query '.$s);
        if (!str_contains($s,'COUNT(*)')) { $limit=$p[count($p)-2]; $offset=$p[count($p)-1]; $rows=array_slice($rows,$offset,$limit); }
        $this->matched=$rows; return true;
    }
    function fetchColumn() { return count($this->matched); }
    function fetchAll(): array { return $this->matched; }
}
function fixtures(): FilterPDO {
    $db=new FilterPDO();
    $db->users=[['id'=>1,'full_name'=>'أحمد','email'=>'a@test','major'=>'A','status'=>'active'],['id'=>2,'full_name'=>'أحمد','email'=>'b@test','major'=>'B','status'=>'suspended'],['id'=>3,'full_name'=>'سارة','email'=>'c@test','major'=>'A','status'=>'deactivated']];
    $db->requests=[['id'=>1,'status'=>'receiving_offers','moderation_status'=>'published'],['id'=>2,'status'=>'in_progress','moderation_status'=>'published'],['id'=>3,'status'=>'completed','moderation_status'=>'removed'],['id'=>4,'status'=>'cancelled','moderation_status'=>'published']];
    $db->reports=[['id'=>1,'status'=>'open'],['id'=>2,'status'=>'closed']]; return $db;
}
function runList(string $list,array $get): array {
    global $root,$pdo; $_GET=$get; $pdo=fixtures(); $src=file_get_contents($root.'admin/'.$list.'.php');
    if ($list==='users') { $term=trim(input_string('q','get')); $userStatus=admin_filter_input('user_status'); $block=part($src,"\$searchPattern = '%'.\$term.'%';","header_ui('المستخدمون');"); }
    elseif ($list==='requests') $block=part($src,"\$requestStatus = admin_filter_input", "header_ui('طلبات المساعدة');");
    else $block=part($src,"\$reportStatus = admin_filter_input", "header_ui('البلاغات');");
    eval($block); return [$rows,$pagination,$pdo];
}
foreach ([['',3],['active',1],['suspended',1]] as [$state,$expected]) { [$rows,$p,$db]=runList('users',['user_status'=>$state]); check(count($rows)===$expected && $p['total']===$expected,'user filter '.($state?:'all')); }
[$rows,$p,$db]=runList('users',['q'=>' أحمد ','user_status'=>'suspended']); check(count($rows)===1 && $rows[0]['id']===2 && $p['total']===1 && $db->executed[0][1]===['%أحمد%','%أحمد%','%أحمد%','suspended'],'search and user filter bound together');
[$rows,$p,$db]=runList('users',['q'=>'%_','user_status'=>'active']); check($db->executed[0][1]===['%%_%','%%_%','%%_%','active'], 'LIKE wildcards remain unchanged and bound');
[$rows,$p]=runList('users',['q'=>'nonexistent','user_status'=>'suspended']); check(!$rows && $p['total']===0, 'no matching filtered users');
foreach ([['receiving_offers',1],['in_progress',2],['completed',3],['cancelled',4]] as [$state,$id]) { [$r]=runList('requests',['request_status'=>$state]); check(count($r)===1 && $r[0]['id']===$id,'request status '.$state); }
foreach ([['published',3],['removed',1]] as [$state,$n]) { [$r]=runList('requests',['moderation'=>$state]); check(count($r)===$n,'publication '.$state); }
[$r,$p,$db]=runList('requests',['moderation'=>'removed','request_status'=>'completed']); check(count($r)===1 && $r[0]['id']===3 && $db->executed[0][1]===['completed','removed'],'combined request filters and parameters');
[$r,$p]=runList('requests',['moderation'=>'removed','request_status'=>'cancelled']); check(!$r && $p['total']===0,'no filtered request results');
foreach ([['',2],['open',1],['closed',1]] as [$state,$n]) { [$r]=runList('reports',['report_status'=>$state]); check(count($r)===$n,'report filter '.($state?:'all')); }
$_GET=['q'=>'أحمد','user_status'=>'active','page'=>'2','per_page'=>'10']; $nav=pagination_ui(['total_pages'=>3,'page'=>2,'per_page'=>10,'total'=>25,'previous'=>true,'next'=>true],'الطلاب'); check(str_contains($nav,'user_status=active') && str_contains($nav,'q=') && str_contains($nav,'per_page=10') && str_contains($nav,'page=3'),'pagination retains filters and search');
foreach (['users','requests','reports'] as $file) { $s=file_get_contents($root.'admin/'.$file.'.php'); $form=part($s,$file==='users' ? '<form class="d-flex flex-wrap' : '<form method="get"', '</form>'); check(!str_contains($form,'name="page"') && str_contains($form,'name="per_page"'),'new filter submission resets page and retains page size '.$file); }
$url=admin_detail_url('request_view.php',['id'=>1,'back_request_status'=>'completed','back_moderation'=>'removed','back_page'=>2,'back_per_page'=>50]); check(str_contains($url,'back_request_status=completed') && str_contains($url,'back_moderation=removed') && str_contains($url,'back_page=2'),'detail context contains validated filters');
$url=admin_parent_url('reports',['report_status'=>'closed','page'=>2,'per_page'=>10]); check(str_contains($url,'report_status=closed') && str_starts_with($url,'/sub/admin/reports.php?'),'report return uses local path and filter');
$url=admin_parent_url('users',['user_status'=>'evil','q'=>'hello']); check(!str_contains($url,'user_status=') && str_contains($url,'q=hello'),'invalid return context omitted');
foreach (['users','requests','reports'] as $file) { $s=file_get_contents($root.'admin/'.$file.'.php'); check(str_contains($s,"need_role('admin');"),$file.' retains admin role guard (static)'); }
check(str_contains(file_get_contents($root.'admin/users.php'),'post_only();'),'admin POST CSRF guard remains (static)');
foreach (['request_view.php'=>['back_request_status','back_moderation'],'report_view.php'=>['back_report_status'],'reported_conversation.php'=>['back_report_status'],'user_view.php'=>['back_user_status']] as $file=>$keys) {
    $s=file_get_contents($root.'admin/'.$file); foreach ($keys as $key) check(str_contains($s, "input_string('".$key."', 'get')"),$file.' preserves '.$key.' (static)');
}
$studentSource=file_get_contents($root.'requests/index.php'); check(str_contains($studentSource,"['q' => \$term, 'type' => \$typeId ?? '', 'per_page' => \$pagination['per_page']]") ,'removing major retains search, type and size (static)');

$studentSource=file_get_contents($root.'requests/index.php');
check(str_contains($studentSource, "COUNT(*) FROM requests r JOIN users u ON u.id=r.owner_id JOIN assistance_types t ON t.id=r.assistance_type_id"), 'student count matches result joins (static)');
check(str_contains($studentSource, "ui_context_url('requests/index.php', ['per_page' => \$pagination['per_page']])"), 'clear all preserves page size without page or filters (static)');
$userSource=file_get_contents($root.'admin/users.php');
check(strpos($userSource, "if (array_key_exists('q', \$_GET)") < strpos($userSource, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')"), 'search type validation before POST (static)');
echo "PASS: admin filter scenarios completed (fake PDO)\n";

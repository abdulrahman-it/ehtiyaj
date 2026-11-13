<?php
/** Execute the actual acceptance block with a transactional fake PDO; not MySQL or HTTP. */
$source = file_get_contents(__DIR__ . '/../Ehtiya/htdocs/requests/view.php');
$start = strpos($source, "    // Accept valid selected submitted");
if ($start === false) $start = strpos($source, '    // Accept valid selected offers');
$end = strpos($source, '    // Completion/cancellation', $start);
if ($start === false || $end === false) throw new RuntimeException('Missing acceptance block');
$block = substr($source, $start, $end - $start);
function check(bool $ok, string $name): void { if (!$ok) throw new RuntimeException('FAIL: ' . $name); echo 'PASS: ' . $name . "\n"; }
function input_string(string $key): string { return $_POST[$key] ?? ''; }
function me(): array { return ['id'=>7, 'role'=>'student', 'status'=>'active']; }
function lock_active_student(int $id): bool { return $GLOBALS['active']; }
function flash(string $message, string $type='success'): void { $GLOBALS['flash'] = [$message,$type]; }
function notify($id,$type,$title,$message,$link): void { $GLOBALS['pdo']->notices[] = [$id,$type,$link]; }
class AcceptanceFakePDO {
    public array $request; public array $offers; public array $conversations=[]; public array $notices=[];
    public bool $transaction=false; public bool $failInsert=false; public bool $zeroUpdate=false; public bool $failCommit=false; public int $failInsertAt=0; public int $insertAttempts=0; public int $rollbackCalls=0; public bool $firstSavedBeforeFailure=false; public array $snapshot=[];
    function beginTransaction() { $this->snapshot=[$this->request,$this->offers,$this->conversations,$this->notices]; $this->transaction=true; return true; }
    function commit() { if ($this->failCommit) return false; $this->transaction=false; return true; }
    function inTransaction() { return $this->transaction; }
    function rollBack() { $this->rollbackCalls++; [$this->request,$this->offers,$this->conversations,$this->notices]=$this->snapshot; $this->transaction=false; return true; }
    function prepare(string $sql): AcceptanceStatement { return new AcceptanceStatement($this,$sql); }
    function lastInsertId(): string { return (string)count($this->conversations); }
}
class AcceptanceStatement {
    private $result=false; private int $count=0;
    function __construct(private AcceptanceFakePDO $db, private string $sql) {}
    function execute(array $p): bool {
        $db=$this->db; $sql=$this->sql;
        if (str_starts_with($sql, 'SELECT * FROM requests')) {
            if (!str_contains($sql, 'owner_id=?') || !str_contains($sql, "moderation_status='published' FOR UPDATE")) throw new RuntimeException('Bad request lock');
            $r=$db->request; if ($r['id']===$p[0] && $r['owner_id']===$p[1] && $r['moderation_status']==='published') $this->result=$r;
        } elseif (str_starts_with($sql, 'SELECT * FROM offers')) {
            if (!str_contains($sql, "request_id=? AND status='submitted' FOR UPDATE")) throw new RuntimeException('Bad offer lock');
            $o=$db->offers[$p[0]] ?? null; if ($o && $o['request_id']===$p[1] && $o['status']==='submitted') $this->result=$o;
        } elseif (str_starts_with($sql, 'UPDATE offers')) {
            if (!str_contains($sql, "request_id=? AND status='submitted'")) throw new RuntimeException('Bad offer update');
            if (!$db->zeroUpdate && isset($db->offers[$p[0]]) && $db->offers[$p[0]]['request_id']===$p[1] && $db->offers[$p[0]]['status']==='submitted') { $db->offers[$p[0]]['status']='accepted'; $this->count=1; }
        } elseif (str_starts_with($sql, 'INSERT INTO conversations')) {
            $db->insertAttempts++;
            if ($db->failInsert || $db->insertAttempts === $db->failInsertAt) {
                $db->firstSavedBeforeFailure = count($db->conversations) === 1 && count($db->notices) === 1 && $db->offers[10]['status'] === 'accepted';
                throw new PDOException('Simulated insert failure');
            }
            $db->conversations[]=$p;
        } elseif (str_starts_with($sql, 'UPDATE requests')) {
            if ($db->request['id']===$p[0] && $db->request['status']==='receiving_offers') { $db->request['status']='in_progress'; $this->count=1; }
        } else { throw new RuntimeException('Unexpected query: '.$sql); }
        return true;
    }
    function fetch() { return $this->result; }
    function rowCount(): int { return $this->count; }
}
function scenario(array $ids, string $offerStatus='submitted', string $visibleStatus='receiving_offers', string $dbStatus='receiving_offers', bool $owner=true, array $opts=[]): array {
    global $pdo,$flash,$active,$block;
    $pdo=new AcceptanceFakePDO(); $pdo->request=['id'=>3,'owner_id'=>7,'status'=>$dbStatus,'moderation_status'=>$opts['moderation'] ?? 'published'];
    $pdo->offers=[10=>['id'=>10,'request_id'=>3,'student_id'=>9,'status'=>$offerStatus], 11=>['id'=>11,'request_id'=>3,'student_id'=>12,'status'=>'submitted'], 12=>['id'=>12,'request_id'=>4,'student_id'=>13,'status'=>'submitted']];
    foreach ($opts['extraOffers'] ?? [] as $extraId) $pdo->offers[$extraId]=['id'=>$extraId,'request_id'=>3,'student_id'=>19,'status'=>'submitted'];
    $pdo->failInsertAt=$opts['failInsertAt'] ?? 0;
    $pdo->failInsert=$opts['failInsert'] ?? false; $pdo->zeroUpdate=$opts['zeroUpdate'] ?? false; $pdo->failCommit=$opts['failCommit'] ?? false;
    $active=$opts['active'] ?? true; $flash=null; $id=3; $mine=$owner; $r=['owner_id'=>7,'status'=>$visibleStatus]; $_POST=['action'=>'accept','offers'=>$ids];
    eval($block);
    return [$flash,$pdo];
}
[$f,$d]=scenario([10]); check($f[1]==='success' && $d->offers[10]['status']==='accepted' && count($d->conversations)===1 && count($d->notices)===1 && $d->request['status']==='in_progress', 'valid offer commits with conversation and notice');
foreach ([['missing',[99],'submitted'],['rejected',[10],'rejected'],['already accepted',[10],'accepted'],['withdrawn',[10],'withdrawn'],['other request',[12],'submitted']] as [$label,$ids,$status]) {
    [$f,$d]=scenario($ids,$status); check($f[1]==='warning' && str_contains($f[0],'لم يُقبل أي عرض') && !$d->conversations && !$d->notices && $d->request['status']==='receiving_offers', $label.' has no false success');
}
[$f,$d]=scenario([10],'submitted','completed','completed'); check($f[1]==='warning' && !$d->conversations && $d->offers[10]['status']==='submitted', 'closed request rejected');
[$f,$d]=scenario([10],'submitted','receiving_offers','completed'); check($f[1]==='warning' && str_contains($f[0],'تغيرت حالة الطلب') && !$d->conversations, 'stale request state rechecked under lock');
[$f,$d]=scenario([10,99]); check($f[1]==='warning' && str_contains($f[0],'قُبل 1') && str_contains($f[0],'لم يُقبل 1') && count($d->conversations)===1, 'mixed batch reports partial acceptance');
[$f,$d]=scenario([10,11,10]); check($f[1]==='success' && count($d->conversations)===2 && count($d->notices)===2, 'multiple valid offers accepted once each');
[$f,$d]=scenario([10, ['bad']]); check($f[1]==='warning' && str_contains($f[0],'لم يُقبل 1') && count($d->conversations)===1, 'malformed selection reported');
[$f,$d]=scenario([10],'submitted','receiving_offers','receiving_offers',false); check($f[1]==='danger' && !$d->transaction && !$d->conversations && $d->offers[10]['status']==='submitted', 'non-owner cannot accept');
foreach (['failInsert','zeroUpdate','failCommit'] as $opt) { [$f,$d]=scenario([10], 'submitted','receiving_offers','receiving_offers',true,[$opt=>true]); check($f[1]==='danger' && !$d->transaction && !$d->conversations && !$d->notices && $d->offers[10]['status']==='submitted' && $d->request['status']==='receiving_offers', $opt.' rolls back with no success'); }
// Compare normalized decimal IDs against the platform's true integer boundary.
$max = (string)PHP_INT_MAX;
$over = substr($max, 0, -1) . (string)((int)substr($max, -1) + 1);
$boundaryOffers = ['extraOffers'=>[1, PHP_INT_MAX - 1, PHP_INT_MAX]];
foreach (['1', '0001', (string)(PHP_INT_MAX - 1), $max, PHP_INT_MAX, '000'.$max] as $validId) {
    [$f,$d]=scenario([$validId], 'submitted','receiving_offers','receiving_offers',true,$boundaryOffers);
    check($f[1]==='success' && count($d->conversations)===1 && $d->conversations[0][1] === (int)$validId, 'valid boundary ID '.$validId.' accepted');
}
foreach ([$over, '000'.$over, $max.'0', '', '0', '000', '-1', '+1', '1.0', '1a', ' 1', [], 1.0, true] as $invalidId) {
    [$f,$d]=scenario([$invalidId], 'submitted','receiving_offers','receiving_offers',true,$boundaryOffers);
    check($f[1]==='warning' && !$d->conversations && !$d->notices && $d->offers[PHP_INT_MAX]['status']==='submitted', 'invalid ID rejected: '.json_encode($invalidId));
}
[$f,$d]=scenario([10, '00010', $over], 'submitted','receiving_offers','receiving_offers',true,$boundaryOffers);
check($f[1]==='warning' && str_contains($f[0],'قُبل 1') && str_contains($f[0],'لم يُقبل 1') && count($d->conversations)===1 && $d->offers[PHP_INT_MAX]['status']==='submitted', 'overflow skipped and duplicate normalized ID accepted only once');
[$f,$d]=scenario([10,11], 'submitted','receiving_offers','receiving_offers',true,['failInsertAt'=>2]);
check($d->firstSavedBeforeFailure && $d->insertAttempts===2 && $d->rollbackCalls===1 && $f[1]==='danger' && !$d->transaction && !$d->conversations && !$d->notices && $d->offers[10]['status']==='submitted' && $d->offers[11]['status']==='submitted' && $d->request['status']==='receiving_offers', 'second offer failure rolls back first offer and reports no success');
[$f,$d]=scenario([10],'submitted','receiving_offers','receiving_offers',true,['moderation'=>'removed']); check($f[1]==='warning' && !$d->conversations, 'unpublished request rejected under lock');
check(strpos($source, "post_only(['offers']);") < $start && strpos($source, 'active_write();') < $start && str_contains($source, "need_role('student');"), 'CSRF and role guards remain before acceptance (static)');
echo "PASS: acceptance scenarios completed (fake PDO)\n";

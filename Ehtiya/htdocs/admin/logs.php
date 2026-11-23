<?php
/** Search, summarize, and review the existing append-only administrative audit records. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');

// Severity is based only on operations that are actually written by current admin workflows.
function admin_log_is_sensitive(string $action, string $entityType): bool
{
    return in_array($action, ['suspend', 'reactivate', 'review_report', 'remove_request', 'access_reported_conversation'], true)
        || ($action === 'toggle' && $entityType === 'assistance_type')
        || ($action === 'toggle_major' && $entityType === 'major');
}

function admin_log_action_label(string $action): string
{
    return [
        'suspend' => 'تعليق حساب', 'reactivate' => 'إعادة تفعيل حساب',
        'change_major' => 'تغيير تخصص', 'review_report' => 'إغلاق بلاغ',
        'remove_request' => 'إزالة طلب', 'access_reported_conversation' => 'مراجعة محادثة مبلّغ عنها',
        'toggle' => 'تغيير حالة نوع مساعدة', 'review_suggestion' => 'مراجعة اقتراح نوع مساعدة',
        'add_major' => 'إضافة تخصص', 'toggle_major' => 'تغيير حالة تخصص',
    ][$action] ?? $action;
}

function admin_log_entity_label(string $entityType): string
{
    return [
        'user' => 'مستخدم', 'request' => 'طلب', 'offer' => 'عرض', 'report' => 'بلاغ',
        'conversation' => 'محادثة', 'assistance_type' => 'نوع مساعدة',
        'suggestion' => 'اقتراح', 'major' => 'تخصص',
    ][$entityType] ?? $entityType;
}

function admin_log_icon(string $entityType): string
{
    return [
        'user' => 'bi-person-gear', 'request' => 'bi-journal-text', 'offer' => 'bi-hand-thumbs-up',
        'report' => 'bi-flag', 'conversation' => 'bi-chat-dots', 'assistance_type' => 'bi-grid',
        'suggestion' => 'bi-lightbulb', 'major' => 'bi-mortarboard',
    ][$entityType] ?? 'bi-activity';
}

function admin_log_sensitive_sql(): string
{
    return "(l.action IN ('suspend','reactivate','review_report','remove_request','access_reported_conversation')"
        . " OR (l.action='toggle' AND l.entity_type='assistance_type')"
        . " OR (l.action='toggle_major' AND l.entity_type='major'))";
}

function admin_log_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$scalarKeys = ['q', 'admin_id', 'action', 'entity_type', 'sensitivity', 'date_from', 'date_to', 'view', 'detail_id', 'export'];
foreach ($scalarKeys as $key) {
    if (array_key_exists($key, $_GET) && !is_string($_GET[$key])) {
        http_response_code(400);
        exit('قيمة التصفية غير صالحة.');
    }
}

$term = trim(input_string('q', 'get'));
if (mb_strlen($term) > 120) $term = mb_substr($term, 0, 120);
$adminId = input_string('admin_id', 'get');
$action = input_string('action', 'get');
$entityType = input_string('entity_type', 'get');
$sensitivity = input_string('sensitivity', 'get', 'all');
$dateFrom = input_string('date_from', 'get');
$dateTo = input_string('date_to', 'get');
$view = input_string('view', 'get', 'table');
$validDate = static function (string $value): bool {
    if ($value === '') return true;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
if (!$validDate($dateFrom) || !$validDate($dateTo) || ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo)) {
    http_response_code(400);
    exit('نطاق التاريخ غير صالح.');
}
if (!in_array($sensitivity, ['all', 'sensitive', 'normal'], true) || !in_array($view, ['table', 'timeline'], true)) {
    http_response_code(400);
    exit('قيمة التصفية غير صالحة.');
}

// Filter choices come from audit records, not a hard-coded list of hypothetical operations.
$admins = $pdo->query("SELECT DISTINCT l.admin_id, COALESCE(u.full_name, CONCAT('مسؤول #', l.admin_id)) AS full_name FROM admin_logs l LEFT JOIN users u ON u.id=l.admin_id WHERE l.admin_id IS NOT NULL ORDER BY full_name, l.admin_id")->fetchAll();
$actions = $pdo->query('SELECT DISTINCT action FROM admin_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$entityTypes = $pdo->query('SELECT DISTINCT entity_type FROM admin_logs ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);
$validAdminIds = array_map(static fn(array $row): string => (string)$row['admin_id'], $admins);
if (($adminId !== '' && (!ctype_digit($adminId) || !in_array($adminId, $validAdminIds, true)))
    || ($action !== '' && !in_array($action, $actions, true))
    || ($entityType !== '' && !in_array($entityType, $entityTypes, true))) {
    http_response_code(400);
    exit('قيمة التصفية غير صالحة.');
}

// One parameterized WHERE clause powers the table, timeline, detail-independent count, and CSV export.
$conditions = [];
$params = [];
if ($term !== '') {
    $conditions[] = "(l.action LIKE ? OR l.entity_type LIKE ? OR l.description LIKE ? OR CAST(l.entity_id AS CHAR) LIKE ? OR COALESCE(u.full_name, '') LIKE ?)";
    $like = '%' . $term . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($adminId !== '') { $conditions[] = 'l.admin_id = ?'; $params[] = (int)$adminId; }
if ($action !== '') { $conditions[] = 'l.action = ?'; $params[] = $action; }
if ($entityType !== '') { $conditions[] = 'l.entity_type = ?'; $params[] = $entityType; }
if ($sensitivity === 'sensitive') $conditions[] = admin_log_sensitive_sql();
elseif ($sensitivity === 'normal') $conditions[] = 'NOT ' . admin_log_sensitive_sql();
if ($dateFrom !== '') { $conditions[] = 'l.created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $conditions[] = 'l.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $dateTo . ' 00:00:00'; }
$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$joinSql = ' FROM admin_logs l LEFT JOIN users u ON u.id=l.admin_id';

// A read-only, administrator-guarded JSON detail endpoint supplies the drawer and resolves only known entity types.
$detailId = input_string('detail_id', 'get');
if ($detailId !== '') {
    if (!ctype_digit($detailId) || (int)$detailId < 1) admin_log_json(['error' => 'معرّف السجل غير صالح.'], 400);
    try {
        $detailQuery = $pdo->prepare('SELECT l.id,l.admin_id,l.action,l.entity_type,l.entity_id,l.description,l.created_at,u.full_name FROM admin_logs l LEFT JOIN users u ON u.id=l.admin_id WHERE l.id=?');
        $detailQuery->execute([(int)$detailId]);
        $record = $detailQuery->fetch();
        if (!$record) admin_log_json(['error' => 'السجل غير موجود.'], 404);
        $entityId = $record['entity_id'] === null ? 0 : (int)$record['entity_id'];
        $info = [];
        $entityUrl = null;
        if ($entityId > 0) {
            switch ((string)$record['entity_type']) {
                case 'user':
                    $lookup = $pdo->prepare("SELECT id,full_name,major,status,created_at FROM users WHERE id=? AND role='student'");
                    $lookup->execute([$entityId]);
                    if ($entity = $lookup->fetch()) {
                        $info = [['label' => 'اسم المستخدم', 'value' => $entity['full_name']], ['label' => 'التخصص', 'value' => $entity['major']], ['label' => 'حالة الحساب', 'value' => $entity['status']], ['label' => 'تاريخ الانضمام', 'value' => $entity['created_at']]];
                        $entityUrl = app_url('admin/user_view.php?id=' . $entityId);
                    }
                    break;
                case 'request':
                    $lookup = $pdo->prepare('SELECT id,title,status,moderation_status FROM requests WHERE id=?');
                    $lookup->execute([$entityId]);
                    if ($entity = $lookup->fetch()) {
                        $info = [['label' => 'عنوان الطلب', 'value' => $entity['title']], ['label' => 'حالة الطلب', 'value' => $entity['status']], ['label' => 'حالة النشر', 'value' => $entity['moderation_status']]];
                        $entityUrl = app_url('admin/request_view.php?id=' . $entityId);
                    }
                    break;
                case 'report':
                    $lookup = $pdo->prepare('SELECT x.id,x.status,x.reason,c.id AS conversation_id,r.title FROM conversation_reports x JOIN conversations c ON c.id=x.conversation_id JOIN requests r ON r.id=c.request_id WHERE x.id=?');
                    $lookup->execute([$entityId]);
                    if ($entity = $lookup->fetch()) {
                        $info = [['label' => 'الطلب المرتبط', 'value' => $entity['title']], ['label' => 'حالة البلاغ', 'value' => $entity['status']], ['label' => 'رقم المحادثة', 'value' => (string)$entity['conversation_id']], ['label' => 'سبب البلاغ', 'value' => $entity['reason']]];
                        $entityUrl = app_url('admin/report_view.php?id=' . $entityId);
                    }
                    break;
                case 'conversation':
                    $lookup = $pdo->prepare('SELECT c.id,c.request_id,c.status,r.title FROM conversations c JOIN requests r ON r.id=c.request_id WHERE c.id=?');
                    $lookup->execute([$entityId]);
                    if ($entity = $lookup->fetch()) {
                        $info = [['label' => 'الطلب المرتبط', 'value' => $entity['title']], ['label' => 'حالة المحادثة', 'value' => $entity['status']], ['label' => 'رقم الطلب', 'value' => (string)$entity['request_id']]];
                        $reportId = 0;
                        if ((string)$record['action'] === 'access_reported_conversation'
                            && preg_match('/البلاغ رقم\s+([0-9]+)/u', (string)$record['description'], $reportMatch)) {
                            $reportLookup = $pdo->prepare('SELECT id FROM conversation_reports WHERE id=? AND conversation_id=?');
                            $reportLookup->execute([(int)$reportMatch[1], $entityId]);
                            $reportId = (int)$reportLookup->fetchColumn();
                        }
                        if ($reportId < 1) {
                            $reportLookup = $pdo->prepare('SELECT id FROM conversation_reports WHERE conversation_id=? ORDER BY id DESC LIMIT 1');
                            $reportLookup->execute([$entityId]);
                            $reportId = (int)$reportLookup->fetchColumn();
                        }
                        if ($reportId > 0) $entityUrl = app_url('admin/reported_conversation.php?report_id=' . $reportId);
                    }
                    break;
                case 'offer':
                    $lookup = $pdo->prepare('SELECT o.status,o.request_id,r.title,u.full_name FROM offers o JOIN requests r ON r.id=o.request_id JOIN users u ON u.id=o.student_id WHERE o.id=?');
                    $lookup->execute([$entityId]);
                    if ($entity = $lookup->fetch()) {
                        $info = [['label' => 'مقدم العرض', 'value' => $entity['full_name']], ['label' => 'حالة العرض', 'value' => $entity['status']], ['label' => 'الطلب المرتبط', 'value' => $entity['title']]];
                        $entityUrl = app_url('admin/request_view.php?id=' . (int)$entity['request_id']);
                    }
                    break;
                case 'assistance_type':
                    $lookup = $pdo->prepare('SELECT name,is_active FROM assistance_types WHERE id=?');
                    $lookup->execute([$entityId]);
                    if ($entity = $lookup->fetch()) {
                        $info = [['label' => 'نوع المساعدة', 'value' => $entity['name']], ['label' => 'الحالة', 'value' => (int)$entity['is_active'] === 1 ? 'مفعل' : 'معطل']];
                        $entityUrl = app_url('admin/assistance_types.php');
                    }
                    break;
                case 'suggestion':
                    $lookup = $pdo->prepare('SELECT suggested_name,status,details FROM assistance_type_suggestions WHERE id=?');
                    $lookup->execute([$entityId]);
                    if ($entity = $lookup->fetch()) {
                        $info = [['label' => 'الاقتراح', 'value' => $entity['suggested_name']], ['label' => 'الحالة', 'value' => $entity['status']], ['label' => 'التفاصيل', 'value' => (string)$entity['details']]];
                        $entityUrl = app_url('admin/assistance_types.php');
                    }
                    break;
            }
        }
        admin_log_json(['record' => [
            'id' => (int)$record['id'], 'admin' => $record['full_name'] ?: 'مسؤول غير معروف',
            'admin_id' => $record['admin_id'] === null ? null : (int)$record['admin_id'],
            'action' => (string)$record['action'], 'action_label' => admin_log_action_label((string)$record['action']),
            'entity_type' => (string)$record['entity_type'], 'entity_label' => admin_log_entity_label((string)$record['entity_type']),
            'entity_id' => $record['entity_id'] === null ? null : (int)$record['entity_id'],
            'description' => (string)$record['description'], 'created_at' => (string)$record['created_at'],
            'sensitive' => admin_log_is_sensitive((string)$record['action'], (string)$record['entity_type']),
            'info' => $info, 'entity_url' => $entityUrl,
        ]]);
    } catch (Throwable $exception) {
        error_log('[Ehtiyaj] Admin log detail lookup failed: ' . $exception->getMessage());
        admin_log_json(['error' => 'تعذر تحميل تفاصيل السجل.'], 500);
    }
}

if (input_string('export', 'get') === 'csv') {
    $exportQuery = $pdo->prepare('SELECT l.id,l.created_at,l.admin_id,COALESCE(u.full_name, \'مسؤول غير معروف\') AS full_name,l.action,l.entity_type,l.entity_id,l.description' . $joinSql . $whereSql . ' ORDER BY l.created_at DESC,l.id DESC');
    $exportQuery->execute($params);
    $csvSafe = static function ($value): string {
        $value = (string)($value ?? '');
        return preg_match('/^[\t\r ]*[=+\-@]/u', $value) ? "'" . $value : $value;
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ehtiyaj-admin-logs-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['رقم السجل', 'التاريخ والوقت', 'معرف المسؤول', 'المسؤول', 'العملية', 'نوع الكيان', 'معرف الكيان', 'الوصف', 'الحساسية'], ',', '"', '\\');
    while ($row = $exportQuery->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            (int)$row['id'], $csvSafe($row['created_at']), $row['admin_id'], $csvSafe($row['full_name']),
            $csvSafe(admin_log_action_label((string)$row['action'])), $csvSafe(admin_log_entity_label((string)$row['entity_type'])),
            $row['entity_id'], $csvSafe($row['description']), admin_log_is_sensitive((string)$row['action'], (string)$row['entity_type']) ? 'حساسة' : 'عادية',
        ], ',', '"', '\\');
    }
    fclose($output);
    exit;
}

$countQuery = $pdo->prepare('SELECT COUNT(*)' . $joinSql . $whereSql);
$countQuery->execute($params);
$pagination = pagination_state((int)$countQuery->fetchColumn());
$rowQuery = $pdo->prepare('SELECT l.id,l.created_at,l.admin_id,COALESCE(u.full_name, \'مسؤول غير معروف\') AS full_name,l.action,l.entity_type,l.entity_id,l.description' . $joinSql . $whereSql . ' ORDER BY l.created_at DESC,l.id DESC LIMIT ? OFFSET ?');
foreach ($params as $index => $param) $rowQuery->bindValue($index + 1, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
$rowQuery->bindValue(count($params) + 1, $pagination['per_page'], PDO::PARAM_INT);
$rowQuery->bindValue(count($params) + 2, $pagination['offset'], PDO::PARAM_INT);
$rowQuery->execute();
$rows = $rowQuery->fetchAll(PDO::FETCH_ASSOC);

$stats = $pdo->query('SELECT COUNT(*) AS total, SUM(CASE WHEN created_at >= CURDATE() AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS today, COUNT(DISTINCT admin_id) AS admins, SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN 1 ELSE 0 END) AS last_week FROM admin_logs')->fetch(PDO::FETCH_ASSOC);
$sensitiveCount = (int)$pdo->query('SELECT COUNT(*) FROM admin_logs l WHERE ' . admin_log_sensitive_sql())->fetchColumn();
$statCards = [
    ['label' => 'إجمالي العمليات', 'value' => (int)$stats['total'], 'description' => 'كل السجلات المحفوظة', 'icon' => 'bi-journal-text', 'tone' => 'total'],
    ['label' => 'عمليات اليوم', 'value' => (int)$stats['today'], 'description' => 'منذ بداية اليوم', 'icon' => 'bi-calendar2-check', 'tone' => 'today'],
    ['label' => 'المسؤولون النشطون', 'value' => (int)$stats['admins'], 'description' => 'لديهم عمليات مسجلة', 'icon' => 'bi-people', 'tone' => 'admins'],
    ['label' => 'عمليات حساسة', 'value' => $sensitiveCount, 'description' => 'تغييرات إدارية مهمة', 'icon' => 'bi-shield-exclamation', 'tone' => 'sensitive'],
    ['label' => 'آخر 7 أيام', 'value' => (int)$stats['last_week'], 'description' => 'خلال الأيام السبعة الماضية', 'icon' => 'bi-calendar-week', 'tone' => 'week'],
];
$filterValues = ['q' => $term, 'admin_id' => $adminId, 'action' => $action, 'entity_type' => $entityType, 'sensitivity' => $sensitivity, 'date_from' => $dateFrom, 'date_to' => $dateTo];
$viewUrl = static function (string $target) use ($filterValues): string {
    return '?' . http_build_query($filterValues + ['view' => $target], '', '&', PHP_QUERY_RFC3986);
};
$exportUrl = '?' . http_build_query($filterValues + ['export' => 'csv'], '', '&', PHP_QUERY_RFC3986);
header_ui('سجل الإدارة');
?>
<?= admin_page_back_link('dashboard') ?>
<?= admin_page_intro('سجل الإدارة', 'مركز لمتابعة العمليات الإدارية ومراجعة تفاصيلها.', 'clock-history') ?>

<section class="admin-log-stat-grid" aria-label="ملخص نشاط الإدارة">
    <?php foreach ($statCards as $card): ?>
        <article class="admin-log-stat-card is-<?= e($card['tone']) ?>">
            <span class="admin-log-stat-icon" aria-hidden="true"><i class="bi <?= e($card['icon']) ?>"></i></span>
            <span class="admin-log-stat-copy"><small><?= e($card['label']) ?></small><strong><?= e($card['value']) ?></strong><span><?= e($card['description']) ?></span></span>
        </article>
    <?php endforeach; ?>
</section>

<form class="admin-log-filters" method="get" role="search">
    <div class="admin-log-filter-heading"><div><h2><i class="bi bi-funnel" aria-hidden="true"></i> البحث والتصفية</h2><p>يمكن دمج أكثر من معيار للعثور على السجل المطلوب.</p></div></div>
    <div class="admin-log-filter-grid">
        <label class="admin-log-search-field"><span>بحث نصي</span><span class="admin-log-search-control"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" name="q" value="<?= e($term) ?>" maxlength="120" placeholder="الوصف، المسؤول، معرف الكيان أو العملية"></span></label>
        <label><span>المسؤول</span><select class="form-select" name="admin_id"><option value="">جميع المسؤولين</option><?php foreach ($admins as $admin): ?><option value="<?= (int)$admin['admin_id'] ?>" <?= $adminId === (string)$admin['admin_id'] ? 'selected' : '' ?>><?= e($admin['full_name']) ?></option><?php endforeach; ?></select></label>
        <label><span>نوع العملية</span><select class="form-select" name="action"><option value="">جميع العمليات</option><?php foreach ($actions as $actionOption): ?><option value="<?= e($actionOption) ?>" <?= $action === $actionOption ? 'selected' : '' ?>><?= e(admin_log_action_label((string)$actionOption)) ?></option><?php endforeach; ?></select></label>
        <label><span>نوع الكيان</span><select class="form-select" name="entity_type"><option value="">جميع الكيانات</option><?php foreach ($entityTypes as $entityOption): ?><option value="<?= e($entityOption) ?>" <?= $entityType === $entityOption ? 'selected' : '' ?>><?= e(admin_log_entity_label((string)$entityOption)) ?></option><?php endforeach; ?></select></label>
        <label><span>الحساسية</span><select class="form-select" name="sensitivity"><option value="all" <?= $sensitivity === 'all' ? 'selected' : '' ?>>كل المستويات</option><option value="sensitive" <?= $sensitivity === 'sensitive' ? 'selected' : '' ?>>حساسة فقط</option><option value="normal" <?= $sensitivity === 'normal' ? 'selected' : '' ?>>عادية فقط</option></select></label>
        <label><span>من تاريخ</span><input class="form-control" type="date" name="date_from" value="<?= e($dateFrom) ?>"></label>
        <label><span>إلى تاريخ</span><input class="form-control" type="date" name="date_to" value="<?= e($dateTo) ?>"></label>
        <input type="hidden" name="per_page" value="<?= (int)$pagination['per_page'] ?>">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <div class="admin-log-filter-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> تطبيق الفلاتر</button><a class="btn btn-outline-secondary" href="<?= e(app_url('admin/logs.php')) ?>">إعادة ضبط</a></div>
    </div>
</form>

<div class="admin-log-toolbar">
    <div class="admin-log-view-switch" role="group" aria-label="طريقة عرض السجلات"><a class="<?= $view === 'table' ? 'is-current' : '' ?>" href="<?= e($viewUrl('table')) ?>" <?= $view === 'table' ? 'aria-current="page"' : '' ?>><i class="bi bi-table" aria-hidden="true"></i> جدول</a><a class="<?= $view === 'timeline' ? 'is-current' : '' ?>" href="<?= e($viewUrl('timeline')) ?>" <?= $view === 'timeline' ? 'aria-current="page"' : '' ?>><i class="bi bi-clock-history" aria-hidden="true"></i> خط زمني</a></div>
    <div class="admin-log-export"><span><?= e($pagination['total']) ?> نتيجة</span><a class="btn btn-outline-primary btn-sm" href="<?= e($exportUrl) ?>"><i class="bi bi-download" aria-hidden="true"></i> تصدير CSV</a></div>
</div>

<?php if ($view === 'table'): ?>
    <section class="card admin-data-card admin-log-table-card"><div class="table-responsive"><table class="table admin-log-table"><thead><tr><th>التاريخ والوقت</th><th>المسؤول</th><th>العملية</th><th>الكيان</th><th>الوصف</th><th><span class="visually-hidden">تفاصيل</span></th></tr></thead><tbody>
    <?php foreach ($rows as $row): $isSensitive = admin_log_is_sensitive((string)$row['action'], (string)$row['entity_type']); ?>
        <tr><td><time datetime="<?= e(date(DATE_ATOM, strtotime((string)$row['created_at']))) ?>"><?= e($row['created_at']) ?></time></td><td><?= e($row['full_name']) ?></td><td><span class="admin-log-action"><i class="bi <?= $isSensitive ? 'bi-shield-exclamation' : 'bi-record-circle' ?>" aria-hidden="true"></i><?= e(admin_log_action_label((string)$row['action'])) ?></span><?php if ($isSensitive): ?><span class="admin-log-severity is-sensitive">حساسة</span><?php endif; ?></td><td><?= e(admin_log_entity_label((string)$row['entity_type'])) ?> <?= $row['entity_id'] !== null ? '#' . e($row['entity_id']) : '—' ?></td><td class="admin-log-description"><?= e($row['description']) ?></td><td><button class="btn btn-sm btn-outline-primary admin-log-open" type="button" data-log-open="<?= (int)$row['id'] ?>">التفاصيل</button></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">لا توجد سجلات مطابقة للفلاتر المحددة.</td></tr><?php endif; ?>
    </tbody></table></div></section>
<?php else: ?>
    <section class="admin-log-timeline" aria-label="النشاط الإداري بترتيب زمني">
        <?php foreach ($rows as $row): $isSensitive = admin_log_is_sensitive((string)$row['action'], (string)$row['entity_type']); ?>
            <button type="button" class="admin-log-event<?= $isSensitive ? ' is-sensitive' : '' ?>" data-log-open="<?= (int)$row['id'] ?>"><span class="admin-log-event-icon"><i class="bi <?= $isSensitive ? 'bi-shield-exclamation' : 'bi-activity' ?>" aria-hidden="true"></i></span><span class="admin-log-event-content"><span class="admin-log-event-top"><strong><?= e(admin_log_action_label((string)$row['action'])) ?></strong><?php if ($isSensitive): ?><span class="admin-log-severity is-sensitive">حساسة</span><?php else: ?><span class="admin-log-severity">عادية</span><?php endif; ?></span><span><?= e($row['description']) ?></span><small><?= e($row['full_name']) ?> · <?= e($row['created_at']) ?> · <?= e(admin_log_entity_label((string)$row['entity_type'])) ?> <?= $row['entity_id'] !== null ? '#' . e($row['entity_id']) : '' ?></small></span><i class="bi bi-chevron-left admin-log-event-arrow" aria-hidden="true"></i></button>
        <?php endforeach; ?>
        <?php if (!$rows): ?><div class="empty admin-log-empty">لا توجد سجلات مطابقة للفلاتر المحددة.</div><?php endif; ?>
    </section>
<?php endif; ?>
<?= pagination_ui($pagination, 'سجلات الإدارة') ?>

<div class="admin-log-backdrop" data-log-close hidden></div>
<aside class="admin-log-drawer" id="admin-log-drawer" role="dialog" aria-modal="true" aria-labelledby="admin-log-drawer-title" aria-hidden="true" tabindex="-1">
    <header class="admin-log-drawer-head"><div><span class="section-kicker">تفاصيل النشاط</span><h2 id="admin-log-drawer-title">سجل الإدارة</h2></div><button class="admin-log-drawer-close" type="button" data-log-close aria-label="إغلاق التفاصيل"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
    <div class="admin-log-drawer-body" aria-live="polite"><div class="admin-log-drawer-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> جارٍ تحميل التفاصيل…</div></div>
</aside>
<script>
(() => {
    const drawer = document.getElementById('admin-log-drawer');
    const body = drawer?.querySelector('.admin-log-drawer-body');
    const backdrop = document.querySelector('.admin-log-backdrop');
    if (!drawer || !body || !backdrop) return;
    let returnFocus = null;
    const field = (label, value, extra = '') => {
        const section = document.createElement('section');
        section.className = 'admin-log-detail-field ' + extra;
        const heading = document.createElement('h3'); heading.textContent = label;
        const content = document.createElement('p'); content.textContent = value || '—';
        section.append(heading, content); return section;
    };
    function closeDrawer() {
        drawer.classList.remove('is-open'); drawer.setAttribute('aria-hidden', 'true');
        backdrop.hidden = true; document.body.classList.remove('admin-log-drawer-open');
        returnFocus?.focus();
    }
    async function openDrawer(id, trigger) {
        returnFocus = trigger; drawer.classList.add('is-open'); drawer.setAttribute('aria-hidden', 'false');
        backdrop.hidden = false; document.body.classList.add('admin-log-drawer-open');
        body.replaceChildren(); const loading = document.createElement('p'); loading.className = 'admin-log-drawer-loading'; loading.textContent = 'جارٍ تحميل التفاصيل…'; body.append(loading);
        drawer.querySelector('.admin-log-drawer-close')?.focus();
        try {
            const response = await fetch('<?= e(app_url('admin/logs.php')) ?>?detail_id=' + encodeURIComponent(id), {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            const payload = await response.json();
            if (!response.ok || !payload.record) throw new Error(payload.error || 'تعذر تحميل التفاصيل.');
            const record = payload.record; body.replaceChildren();
            const summary = document.createElement('div'); summary.className = 'admin-log-detail-summary';
            const badge = document.createElement('span'); badge.className = 'admin-log-severity ' + (record.sensitive ? 'is-sensitive' : ''); badge.textContent = record.sensitive ? 'عملية حساسة' : 'عملية عادية';
            const title = document.createElement('h3'); title.textContent = record.action_label;
            const desc = document.createElement('p'); desc.textContent = record.description || 'لا يوجد وصف إضافي.';
            summary.append(badge, title, desc); body.append(summary);
            const facts = document.createElement('div'); facts.className = 'admin-log-detail-facts';
            facts.append(field('المسؤول', record.admin), field('التاريخ والوقت', record.created_at), field('نوع الكيان', record.entity_label), field('معرف الكيان', record.entity_id === null ? 'غير مرتبط' : '#' + record.entity_id));
            body.append(facts);
            if (record.info?.length) {
                const extra = document.createElement('section'); extra.className = 'admin-log-detail-extra';
                const heading = document.createElement('h3'); heading.textContent = 'معلومات الكيان'; extra.append(heading);
                record.info.forEach(item => extra.append(field(item.label, item.value)));
                body.append(extra);
            }
            if (record.entity_url) {
                const link = document.createElement('a'); link.className = 'btn btn-primary admin-log-entity-link'; link.href = record.entity_url;
                link.innerHTML = '<i class="bi bi-box-arrow-up-left" aria-hidden="true"></i> فتح الكيان المرتبط'; body.append(link);
            }
        } catch (error) {
            body.replaceChildren(); const message = document.createElement('p'); message.className = 'alert alert-warning'; message.textContent = error.message || 'تعذر تحميل تفاصيل السجل.'; body.append(message);
        }
    }
    document.querySelectorAll('[data-log-open]').forEach(button => button.addEventListener('click', () => openDrawer(button.dataset.logOpen, button)));
    document.querySelectorAll('[data-log-close]').forEach(button => button.addEventListener('click', closeDrawer));
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && drawer.classList.contains('is-open')) closeDrawer(); });
})();
</script>
<?php footer_ui(); ?>

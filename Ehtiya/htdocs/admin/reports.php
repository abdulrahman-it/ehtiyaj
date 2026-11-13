<?php
/** Paginated report queue joined to its reporter, conversation, and related request. */
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
$reportStatus = admin_filter_input('report_status');
$where = $reportStatus !== '' ? ' WHERE x.status = ?' : '';
$params = $reportStatus !== '' ? [$reportStatus] : [];
$count = $pdo->prepare('SELECT COUNT(*) FROM conversation_reports x JOIN users u ON u.id=x.reporter_id JOIN conversations c ON c.id=x.conversation_id JOIN requests r ON r.id=c.request_id'.$where);
$count->execute($params);
$pagination = pagination_state((int)$count->fetchColumn());
$q = $pdo->prepare('SELECT x.*,u.full_name,c.id cid,r.title FROM conversation_reports x JOIN users u ON u.id=x.reporter_id JOIN conversations c ON c.id=x.conversation_id JOIN requests r ON r.id=c.request_id'.$where.' ORDER BY x.created_at DESC,x.id DESC LIMIT ? OFFSET ?');
$index = 1;
foreach ($params as $param) $q->bindValue($index++, $param, PDO::PARAM_STR);
$q->bindValue($index++, $pagination['per_page'], PDO::PARAM_INT);
$q->bindValue($index, $pagination['offset'], PDO::PARAM_INT);
$q->execute();
$rows = $q->fetchAll();
header_ui('البلاغات');
?>
<?= admin_page_back_link('dashboard') ?><?= admin_page_intro('البلاغات', 'راجع البلاغات وحالة كل منها، وافتح التفاصيل عند الحاجة.', 'flag') ?>
<form method="get" class="d-flex flex-wrap align-items-end gap-2 mb-3" role="search">
    <input type="hidden" name="per_page" value="<?= (int)$pagination['per_page'] ?>">
    <div><label class="form-label" for="report-status-filter">حالة البلاغ</label><select class="form-select" id="report-status-filter" name="report_status"><option value="">كل البلاغات</option><option value="open" <?= $reportStatus === 'open' ? 'selected' : '' ?>>مفتوح</option><option value="closed" <?= $reportStatus === 'closed' ? 'selected' : '' ?>>مغلق</option></select></div>
    <button type="submit" class="btn btn-primary">تطبيق التصفية</button>
</form>
<div class="card admin-data-card"><div class="table-responsive"><table class="table"><thead><tr><th>المبلغ</th><th>الموضوع</th><th>السبب</th><th>الحالة</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?=e($r['full_name'])?></td><td><?=e($r['title'])?></td><td><?=e(mb_strimwidth($r['reason'],0,100,'…'))?></td><td><?=status_badge($r['status'])?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= e(admin_detail_url('report_view.php', ['id' => (int)$r['id'], 'back_report_status' => $reportStatus, 'back_page' => $pagination['page'], 'back_per_page' => $pagination['per_page']])) ?>">مراجعة</a></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="empty"><?= $reportStatus !== '' ? 'لا توجد بلاغات مطابقة للتصفية.' : 'لا توجد بلاغات.' ?></td></tr><?php endif; ?>
</tbody></table></div></div>
<?=pagination_ui($pagination, 'البلاغات')?>
<?php footer_ui(); ?>

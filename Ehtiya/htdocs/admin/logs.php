<?php
/** Search and paginate the append-only administrative audit records. */
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
// Search only the existing audit fields and keep the current query in pagination links.
$term = trim(input_string('q', 'get'));
$pattern = '%'.$term.'%';
$count = $pdo->prepare('SELECT COUNT(*) FROM admin_logs WHERE action LIKE ? OR entity_type LIKE ? OR description LIKE ?');
$count->execute([$pattern, $pattern, $pattern]);
$pagination = pagination_state((int)$count->fetchColumn());
$q = $pdo->prepare('SELECT l.*,u.full_name FROM admin_logs l LEFT JOIN users u ON u.id=l.admin_id WHERE l.action LIKE ? OR l.entity_type LIKE ? OR l.description LIKE ? ORDER BY l.id DESC LIMIT ? OFFSET ?');
$q->bindValue(1, $pattern, PDO::PARAM_STR);
$q->bindValue(2, $pattern, PDO::PARAM_STR);
$q->bindValue(3, $pattern, PDO::PARAM_STR);
$q->bindValue(4, $pagination['per_page'], PDO::PARAM_INT);
$q->bindValue(5, $pagination['offset'], PDO::PARAM_INT);
$q->execute();
$rows = $q->fetchAll();
header_ui('سجل الإدارة');
?>
<?= admin_page_back_link('dashboard') ?><?= admin_page_intro('سجل الإدارة', 'ابحث في الإجراءات الإدارية المسجلة وراجع تفاصيلها.', 'clock-history') ?>
<form class="admin-search-form" method="get" role="search"><label class="visually-hidden" for="admin-log-search">بحث في السجل</label><i class="bi bi-search" aria-hidden="true"></i><input id="admin-log-search" name="q" class="form-control" value="<?=e($term)?>" placeholder="بحث في السجل"><button class="btn btn-primary" type="submit">بحث</button></form>
<div class="card admin-data-card admin-audit-card"><div class="table-responsive"><table class="table"><thead><tr><th>الوقت</th><th>المدير</th><th>الإجراء</th><th>الكيان</th><th>الوصف</th></tr></thead><tbody>
<?php foreach ($rows as $l): ?><tr><td><?=e($l['created_at'])?></td><td><?=e($l['full_name']??'—')?></td><td><?=e($l['action'])?></td><td><?=e($l['entity_type'])?> #<?=e($l['entity_id'])?></td><td><?=e($l['description'])?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="empty">لا توجد سجلات مطابقة.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?=pagination_ui($pagination, 'سجلات الإدارة')?>
<?php footer_ui(); ?>

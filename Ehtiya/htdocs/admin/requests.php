<?php
/** Paginated admin monitoring list for requests and their moderation states. */
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
$requestStatus = admin_filter_input('request_status');
$moderation = admin_filter_input('moderation');
$conditions = [];
$params = [];
if ($requestStatus !== '') { $conditions[] = 'r.status = ?'; $params[] = $requestStatus; }
if ($moderation !== '') { $conditions[] = 'r.moderation_status = ?'; $params[] = $moderation; }
$where = $conditions ? ' WHERE '.implode(' AND ', $conditions) : '';
$count = $pdo->prepare('SELECT COUNT(*) FROM requests r JOIN users u ON u.id=r.owner_id'.$where);
$count->execute($params);
$pagination = pagination_state((int)$count->fetchColumn());
$q = $pdo->prepare('SELECT r.*,u.full_name FROM requests r JOIN users u ON u.id=r.owner_id'.$where.' ORDER BY r.created_at DESC,r.id DESC LIMIT ? OFFSET ?');
$index = 1;
foreach ($params as $param) $q->bindValue($index++, $param, PDO::PARAM_STR);
$q->bindValue($index++, $pagination['per_page'], PDO::PARAM_INT);
$q->bindValue($index, $pagination['offset'], PDO::PARAM_INT);
$q->execute();
$rows = $q->fetchAll();
header_ui('طلبات المساعدة');
?>
<?= admin_page_back_link('dashboard') ?><?= admin_page_intro('طلبات المساعدة', 'استعرض الطلبات وحالات النشر لمراجعتها إداريًا.', 'journals') ?>
<form method="get" class="d-flex flex-wrap align-items-end gap-2 mb-3" role="search">
    <input type="hidden" name="per_page" value="<?= (int)$pagination['per_page'] ?>">
    <div><label class="form-label" for="request-status-filter">حالة الطلب</label><select class="form-select" id="request-status-filter" name="request_status"><option value="">كل حالات الطلب</option><?php foreach (['receiving_offers','in_progress','completed','cancelled'] as $status): ?><option value="<?= e($status) ?>" <?= $requestStatus === $status ? 'selected' : '' ?>><?= e(status_label($status)) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="moderation-filter">حالة النشر</label><select class="form-select" id="moderation-filter" name="moderation"><option value="">كل حالات النشر</option><option value="published" <?= $moderation === 'published' ? 'selected' : '' ?>>منشور</option><option value="removed" <?= $moderation === 'removed' ? 'selected' : '' ?>>أزيل من المنصة</option></select></div>
    <button type="submit" class="btn btn-primary">تطبيق التصفية</button>
</form>
<div class="card admin-data-card"><div class="table-responsive"><table class="table"><thead><tr><th>العنوان</th><th>الطالب</th><th>الحالة</th><th>النشر</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?=e($r['title'])?></td><td><?=e($r['full_name'])?></td><td><?=status_badge($r['status'])?></td><td><?=status_badge($r['moderation_status'])?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= e(admin_detail_url('request_view.php', ['id' => (int)$r['id'], 'back_request_status' => $requestStatus, 'back_moderation' => $moderation, 'back_page' => $pagination['page'], 'back_per_page' => $pagination['per_page']])) ?>">عرض</a></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="empty"><?= ($requestStatus !== '' || $moderation !== '') ? 'لا توجد طلبات مطابقة للتصفية.' : 'لا توجد طلبات.' ?></td></tr><?php endif; ?>
</tbody></table></div></div>
<?=pagination_ui($pagination, 'الطلبات')?>
<?php footer_ui(); ?>

<?php
/** Paginated admin monitoring list for requests and their moderation states. */
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
// Count all requests first so pagination can clamp the requested page.
$count = (int)$pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn();
$pagination = pagination_state($count);
$q = $pdo->prepare('SELECT r.*,u.full_name FROM requests r JOIN users u ON u.id=r.owner_id ORDER BY r.created_at DESC,r.id DESC LIMIT ? OFFSET ?');
$q->bindValue(1, $pagination['per_page'], PDO::PARAM_INT);
$q->bindValue(2, $pagination['offset'], PDO::PARAM_INT);
$q->execute();
$rows = $q->fetchAll();
header_ui('طلبات المساعدة');
?>
<?= admin_page_back_link('dashboard') ?><?= admin_page_intro('طلبات المساعدة', 'استعرض الطلبات وحالات النشر لمراجعتها إداريًا.', 'journals') ?>
<div class="card admin-data-card"><div class="table-responsive"><table class="table"><thead><tr><th>العنوان</th><th>الطالب</th><th>الحالة</th><th>النشر</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?=e($r['title'])?></td><td><?=e($r['full_name'])?></td><td><?=status_badge($r['status'])?></td><td><?=status_badge($r['moderation_status'])?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= e(admin_detail_url('request_view.php', ['id' => (int)$r['id'], 'back_page' => $pagination['page'], 'back_per_page' => $pagination['per_page']])) ?>">عرض</a></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="empty">لا توجد طلبات.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?=pagination_ui($pagination, 'الطلبات')?>
<?php footer_ui(); ?>

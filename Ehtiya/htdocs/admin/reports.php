<?php
/** Paginated report queue joined to its reporter, conversation, and related request. */
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
// Include every report state in the review queue; the status badge communicates its current state.
$count = (int)$pdo->query('SELECT COUNT(*) FROM conversation_reports')->fetchColumn();
$pagination = pagination_state($count);
$q = $pdo->prepare('SELECT x.*,u.full_name,c.id cid,r.title FROM conversation_reports x JOIN users u ON u.id=x.reporter_id JOIN conversations c ON c.id=x.conversation_id JOIN requests r ON r.id=c.request_id ORDER BY x.created_at DESC,x.id DESC LIMIT ? OFFSET ?');
$q->bindValue(1, $pagination['per_page'], PDO::PARAM_INT);
$q->bindValue(2, $pagination['offset'], PDO::PARAM_INT);
$q->execute();
$rows = $q->fetchAll();
header_ui('البلاغات');
?>
<?= admin_page_back_link('dashboard') ?><?= admin_page_intro('البلاغات', 'راجع البلاغات وحالة كل منها، وافتح التفاصيل عند الحاجة.', 'flag') ?>
<div class="card admin-data-card"><div class="table-responsive"><table class="table"><thead><tr><th>المبلغ</th><th>الموضوع</th><th>السبب</th><th>الحالة</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?=e($r['full_name'])?></td><td><?=e($r['title'])?></td><td><?=e(mb_strimwidth($r['reason'],0,100,'…'))?></td><td><?=status_badge($r['status'])?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= e(admin_detail_url('report_view.php', ['id' => (int)$r['id'], 'back_page' => $pagination['page'], 'back_per_page' => $pagination['per_page']])) ?>">مراجعة</a></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="empty">لا توجد بلاغات.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?=pagination_ui($pagination, 'البلاغات')?>
<?php footer_ui(); ?>

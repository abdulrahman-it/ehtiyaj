<?php
/** Admin overview: aggregate existing platform counts and expose the existing management destinations. */
require __DIR__ . '/../includes/bootstrap.php';
need_role('admin');

// Keep dashboard indicators as read-only counts; no workflow state is changed here.
$primaryMetrics = [
    'الطلاب' => "SELECT COUNT(*) FROM users WHERE role = 'student'",
    'طلبات قيد العمل' => "SELECT COUNT(*) FROM requests WHERE status IN ('receiving_offers', 'in_progress') AND moderation_status = 'published'",
    'العروض' => 'SELECT COUNT(*) FROM offers',
    'بلاغات تحتاج مراجعة' => "SELECT COUNT(*) FROM conversation_reports WHERE status = 'open'",
];
$metrics = [];
foreach ($primaryMetrics as $label => $sql) {
    $metrics[$label] = (int) $pdo->query($sql)->fetchColumn();
}
// Secondary totals supplement the main operational indicators without introducing new data.
$secondaryMetrics = [
    'طلبات مكتملة' => (int) $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'completed'")->fetchColumn(),
    'طلبات ملغاة' => (int) $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'cancelled'")->fetchColumn(),
    'محادثات' => (int) $pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn(),
];

header_ui('لوحة الإدارة');
?>
<section class="admin-welcome">
    <div><span class="eyebrow"><i class="bi bi-shield-check"></i> إدارة المنصة</span><h1>لوحة التحكم</h1><p>ملخص موجز لمجتمع احتياج والعناصر التي تحتاج متابعة.</p></div>
</section>

<section class="admin-metrics" aria-label="مؤشرات المنصة">
    <?php foreach ($metrics as $label => $value): ?>
        <div class="admin-metric<?= $label === 'بلاغات تحتاج مراجعة' ? ($value > 0 ? ' is-critical' : ' is-clear') : '' ?>">
            <span class="admin-metric-icon"><i class="bi <?= $label === 'الطلاب' ? 'bi-people' : ($label === 'طلبات قيد العمل' ? 'bi-journal-text' : ($label === 'العروض' ? 'bi-hand-thumbs-up' : 'bi-flag')) ?>" aria-hidden="true"></i></span>
            <span class="admin-metric-copy"><small><?= e($label) ?></small><strong><?= e($value) ?></strong><span class="admin-metric-note"><?php if ($label === 'الطلاب'): ?>حسابات الطلاب المسجلة<?php elseif ($label === 'طلبات قيد العمل'): ?>تستقبل عروضًا أو قيد التنفيذ<?php elseif ($label === 'العروض'): ?>إجمالي العروض المسجلة<?php elseif ($value > 0): ?>تحتاج إلى مراجعة<?php else: ?>لا توجد بلاغات مفتوحة<?php endif; ?></span></span>
        </div>
    <?php endforeach; ?>
    <div class="admin-metric">
        <span class="admin-metric-icon"><i class="bi bi-chat-dots" aria-hidden="true"></i></span>
            <span class="admin-metric-copy"><small>المحادثات</small><strong><?= e($secondaryMetrics['محادثات']) ?></strong><span class="admin-metric-note">إجمالي المحادثات المسجلة</span></span>
    </div>
</section>

<section class="admin-secondary-stats" aria-label="مؤشرات إضافية">
    <?php foreach ($secondaryMetrics as $label => $value): ?>
        <?php if ($label !== 'محادثات'): ?><div><span><?= e($label) ?></span><strong><?= e($value) ?></strong></div><?php endif; ?>
    <?php endforeach; ?>
</section>

<div class="admin-workspace">
    <section class="admin-work-group">
        <div class="admin-group-heading"><span class="admin-group-icon"><i class="bi bi-people"></i></span><div><h2>إدارة المجتمع</h2><p>المستخدمون والطلبات وأنواع المساعدة.</p></div></div>
        <a class="admin-action-row" href="<?= e(app_url('admin/users.php')) ?>"><span><i class="bi bi-person-lines-fill"></i> المستخدمون</span><i class="bi bi-arrow-left"></i></a>
        <a class="admin-action-row" href="<?= e(app_url('admin/requests.php')) ?>"><span><i class="bi bi-journals"></i> الطلبات المنشورة</span><i class="bi bi-arrow-left"></i></a>
        <a class="admin-action-row" href="<?= e(app_url('admin/assistance_types.php')) ?>"><span><i class="bi bi-grid"></i> أنواع المساعدة والاقتراحات</span><i class="bi bi-arrow-left"></i></a>
        <a class="admin-action-row" href="<?= e(app_url('admin/majors.php')) ?>"><span><i class="bi bi-mortarboard"></i> التخصصات</span><i class="bi bi-arrow-left"></i></a>
    </section>
    <section class="admin-work-group">
        <div class="admin-group-heading"><span class="admin-group-icon review-icon"><i class="bi bi-clipboard-check"></i></span><div><h2>المراجعة والمتابعة</h2><p>راجع البلاغات وتابع سجل الإجراءات.</p></div></div>
        <a class="admin-action-row admin-report-action-row<?= $metrics['بلاغات تحتاج مراجعة'] > 0 ? ' is-critical' : ' is-clear' ?>" href="<?= e(app_url('admin/reports.php')) ?>"><span><i class="bi bi-flag"></i><span class="admin-action-copy"><strong>البلاغات المفتوحة</strong><small><?= $metrics['بلاغات تحتاج مراجعة'] > 0 ? 'تحتاج إلى مراجعة إدارية' : 'لا توجد بلاغات مفتوحة — كل شيء تحت السيطرة' ?></small></span><b class="admin-action-count"><?= e($metrics['بلاغات تحتاج مراجعة']) ?></b></span><i class="bi bi-arrow-left"></i></a>
        <a class="admin-action-row" href="<?= e(app_url('admin/logs.php')) ?>"><span><i class="bi bi-clock-history"></i> سجل الإدارة</span><i class="bi bi-arrow-left"></i></a>
        <a class="admin-action-row" href="<?= e(app_url('admin/notifications.php')) ?>"><span><i class="bi bi-bell"></i> إشعارات الإدارة</span><i class="bi bi-arrow-left"></i></a>
    </section>
</div>
<?php footer_ui(); ?>

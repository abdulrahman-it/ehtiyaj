<?php
/** Admin overview: aggregate existing platform counts and expose the existing management destinations. */
require __DIR__ . '/../includes/bootstrap.php';
need_role('admin');

// Counts are grouped per base table, without joins that could multiply rows.
// A failed group remains null; zero is reserved for a successfully counted empty set.
$metricGroups = [
    'students' => [
        'sql' => "SELECT COUNT(*) AS total, COUNT(CASE WHEN status = 'active' THEN 1 END) AS active, COUNT(CASE WHEN status = 'suspended' THEN 1 END) AS suspended, COUNT(CASE WHEN status = 'deactivated' THEN 1 END) AS deactivated FROM users WHERE role = 'student'",
        'keys' => ['total', 'active', 'suspended', 'deactivated'],
    ],
    'requests' => [
        'sql' => "SELECT COUNT(CASE WHEN status IN ('receiving_offers', 'in_progress') AND moderation_status = 'published' THEN 1 END) AS working, COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed, COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled FROM requests",
        'keys' => ['working', 'completed', 'cancelled'],
    ],
    'offers' => ['sql' => 'SELECT COUNT(*) AS total FROM offers', 'keys' => ['total']],
    'reports' => ['sql' => "SELECT COUNT(*) AS open_count FROM conversation_reports WHERE status = 'open'", 'keys' => ['open_count']],
    'conversations' => ['sql' => 'SELECT COUNT(*) AS total FROM conversations', 'keys' => ['total']],
];
$counts = [];
$metricsError = false;
foreach ($metricGroups as $group => $definition) {
    $counts[$group] = array_fill_keys($definition['keys'], null);
    try {
        $statement = $pdo->query($definition['sql']);
        if ($statement === false || !is_array($result = $statement->fetch())) {
            throw new RuntimeException('No metric result');
        }
        foreach ($definition['keys'] as $key) {
            if (!array_key_exists($key, $result) || !is_numeric($result[$key]) || (int)$result[$key] < 0) {
                throw new RuntimeException('Invalid metric result');
            }
        }
        foreach ($definition['keys'] as $key) $counts[$group][$key] = (int)$result[$key];
    } catch (Throwable $exception) {
        $metricsError = true;
        error_log('[Ehtiyaj] Admin metric group failed (' . $group . '): ' . $exception->getMessage());
    }
}
if ($metricsError) http_response_code(500);
$metrics = [
    'الطلاب' => $counts['students']['total'],
    'طلبات قيد العمل' => $counts['requests']['working'],
    'العروض' => $counts['offers']['total'],
    'بلاغات تحتاج مراجعة' => $counts['reports']['open_count'],
];
$secondaryMetrics = [
    'طلبات مكتملة' => $counts['requests']['completed'],
    'طلبات ملغاة' => $counts['requests']['cancelled'],
    'محادثات' => $counts['conversations']['total'],
    'طلاب نشطون' => $counts['students']['active'],
    'طلاب موقوفون مؤقتًا' => $counts['students']['suspended'],
    'طلاب معطّلون ذاتيًا' => $counts['students']['deactivated'],
];
$reportCount = $metrics['بلاغات تحتاج مراجعة'];
header_ui('لوحة الإدارة');
?>
<section class="admin-welcome">
    <div><span class="eyebrow"><i class="bi bi-shield-check"></i> إدارة المنصة</span><h1>لوحة التحكم</h1><p>ملخص موجز لمجتمع احتياج والعناصر التي تحتاج متابعة.</p></div>
</section>

<?php if ($metricsError): ?><div class="alert alert-warning" role="alert">تعذر تحميل بعض الإحصائيات. حاول مرة أخرى لاحقًا.</div><?php endif; ?>
<section class="admin-metrics" aria-label="مؤشرات المنصة">
    <?php foreach ($metrics as $label => $value): ?>
        <div class="admin-metric<?= $label === 'بلاغات تحتاج مراجعة' && $value !== null ? ($value > 0 ? ' is-critical' : ' is-clear') : '' ?>">
            <span class="admin-metric-icon"><i class="bi <?= $label === 'الطلاب' ? 'bi-people' : ($label === 'طلبات قيد العمل' ? 'bi-journal-text' : ($label === 'العروض' ? 'bi-hand-thumbs-up' : 'bi-flag')) ?>" aria-hidden="true"></i></span>
            <span class="admin-metric-copy"><small><?= e($label) ?></small><strong><?= e($value === null ? 'غير متاح' : $value) ?></strong><span class="admin-metric-note"><?php if ($value === null): ?>تعذر تحميل المؤشر<?php elseif ($label === 'الطلاب'): ?>جميع حالات حسابات الطلاب<?php elseif ($label === 'طلبات قيد العمل'): ?>منشورة وتستقبل عروضًا أو قيد التنفيذ<?php elseif ($label === 'العروض'): ?>إجمالي العروض المسجلة<?php elseif ($value > 0): ?>تحتاج إلى مراجعة<?php else: ?>لا توجد بلاغات مفتوحة<?php endif; ?></span></span>
        </div>
    <?php endforeach; ?>
    <div class="admin-metric">
        <span class="admin-metric-icon"><i class="bi bi-chat-dots" aria-hidden="true"></i></span>
            <span class="admin-metric-copy"><small>المحادثات</small><strong><?= e($secondaryMetrics['محادثات'] === null ? 'غير متاح' : $secondaryMetrics['محادثات']) ?></strong><span class="admin-metric-note">إجمالي المحادثات المسجلة</span></span>
    </div>
</section>

<section class="admin-secondary-stats" aria-label="مؤشرات إضافية">
    <?php foreach ($secondaryMetrics as $label => $value): ?>
        <?php if ($label !== 'محادثات'): ?><div><span><?php if ($label === 'طلاب نشطون' || $label === 'طلاب موقوفون مؤقتًا'): ?><a href="<?= e(app_url('admin/users.php?user_status=' . ($label === 'طلاب نشطون' ? 'active' : 'suspended'))) ?>"><?= e($label) ?></a><?php else: ?><?= e($label) ?><?= in_array($label, ['طلبات مكتملة', 'طلبات ملغاة'], true) ? ' (كل حالات النشر)' : '' ?><?php endif; ?></span><strong><?= e($value === null ? 'غير متاح' : $value) ?></strong></div><?php endif; ?>
    <?php endforeach; ?>
</section>

<div class="admin-workspace">
    <section class="admin-work-group">
        <div class="admin-group-heading"><span class="admin-group-icon"><i class="bi bi-people"></i></span><div><h2>إدارة المجتمع</h2><p>المستخدمون والطلبات وأنواع المساعدة.</p></div></div>
        <a class="admin-action-row" href="<?= e(app_url('admin/users.php')) ?>"><span><i class="bi bi-person-lines-fill"></i> المستخدمون</span><i class="bi bi-arrow-left"></i></a>
        <a class="admin-action-row" href="<?= e(app_url('admin/requests.php')) ?>"><span><i class="bi bi-journals"></i> جميع الطلبات</span><i class="bi bi-arrow-left"></i></a>
        <a class="admin-action-row" href="<?= e(app_url('admin/assistance_types.php')) ?>"><span><i class="bi bi-grid"></i> أنواع المساعدة والاقتراحات</span><i class="bi bi-arrow-left"></i></a>
        <a class="admin-action-row" href="<?= e(app_url('admin/majors.php')) ?>"><span><i class="bi bi-mortarboard"></i> التخصصات</span><i class="bi bi-arrow-left"></i></a>
    </section>
    <section class="admin-work-group">
        <div class="admin-group-heading"><span class="admin-group-icon review-icon"><i class="bi bi-clipboard-check"></i></span><div><h2>المراجعة والمتابعة</h2><p>راجع البلاغات وتابع سجل الإجراءات.</p></div></div>
        <a class="admin-action-row admin-report-action-row<?= $reportCount === null ? '' : ($reportCount > 0 ? ' is-critical' : ' is-clear') ?>" href="<?= e(app_url('admin/reports.php')) ?>"><span><i class="bi bi-flag"></i><span class="admin-action-copy"><strong>جميع البلاغات</strong><small><?= $reportCount === null ? 'عدد البلاغات المفتوحة غير متاح' : ($reportCount > 0 ? 'بلاغات مفتوحة تحتاج إلى مراجعة' : 'لا توجد بلاغات مفتوحة — كل شيء تحت السيطرة') ?></small></span><b class="admin-action-count"><?= e($reportCount === null ? 'غير متاح' : $reportCount) ?></b></span><i class="bi bi-arrow-left"></i></a>
        <a class="admin-action-row" href="<?= e(app_url('admin/logs.php')) ?>"><span><i class="bi bi-clock-history"></i> سجل الإدارة</span><i class="bi bi-arrow-left"></i></a>
        <a class="admin-action-row" href="<?= e(app_url('admin/notifications.php')) ?>"><span><i class="bi bi-bell"></i> إشعارات الإدارة</span><i class="bi bi-arrow-left"></i></a>
    </section>
</div>
<?php footer_ui(); ?>

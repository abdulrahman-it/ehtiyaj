<?php
/** Student request search, limited to published requests that are receiving offers. */
require __DIR__ . '/../includes/bootstrap.php';
need_role('student');

$term = trim(input_string('q', 'get'));
$majors = active_majors();
$requestedMajor = trim(input_string('major', 'get'));
// Only active catalog entries may be selected; missing/invalid values safely mean “all majors”.
$major = $requestedMajor !== '' && in_array($requestedMajor, $majors, true) ? $requestedMajor : '';
// Add only validated optional filters; all user-provided values remain bound parameters.
$sql = "SELECT r.*, u.full_name, t.name AS type_name
        FROM requests r
        JOIN users u ON u.id = r.owner_id
        JOIN assistance_types t ON t.id = r.assistance_type_id
        WHERE r.moderation_status = 'published'
          AND r.status = 'receiving_offers'
          AND r.owner_id <> ?";
$parameters = [me()['id']];
if ($major !== '') {
    $sql .= ' AND r.major = ?';
    $parameters[] = $major;
}
if ($term !== '') {
    $sql .= ' AND (r.title LIKE ? OR r.description LIKE ? OR r.subject LIKE ?)';
    array_push($parameters, '%' . $term . '%', '%' . $term . '%', '%' . $term . '%');
}
// Mirror the result filters in the count query before calculating pagination.
$countSql = 'SELECT COUNT(*) FROM requests r WHERE r.moderation_status=\'published\' AND r.status=\'receiving_offers\' AND r.owner_id<>?';
$countParams = [me()['id']];
if ($major !== '') {
    $countSql .= ' AND r.major=?';
    $countParams[] = $major;
}
if ($term !== '') {
    $countSql .= ' AND (r.title LIKE ? OR r.description LIKE ? OR r.subject LIKE ?)';
    array_push($countParams, '%' . $term . '%', '%' . $term . '%', '%' . $term . '%');
}
$countQuery = $pdo->prepare($countSql);
$countQuery->execute($countParams);
$pagination = pagination_state((int)$countQuery->fetchColumn());
$sql .= ' ORDER BY r.created_at DESC,r.id DESC LIMIT ? OFFSET ?';
$statement = $pdo->prepare($sql);
$index = 1;
foreach ($parameters as $parameter) {
    $statement->bindValue($index++, $parameter, is_int($parameter) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$statement->bindValue($index++, $pagination['per_page'], PDO::PARAM_INT);
$statement->bindValue($index, $pagination['offset'], PDO::PARAM_INT);
$statement->execute();
$requests = $statement->fetchAll();
$returnContextParams = [
    'return_to' => 'public',
    'back_q' => $term,
    'back_major' => $major,
    'back_page' => $pagination['page'],
    'back_per_page' => $pagination['per_page'],
];
$createRequestUrl = ui_context_url('requests/create.php', $returnContextParams);

header_ui('تصفح الطلبات');
?>
<nav class="ux-breadcrumb" aria-label="مسار الصفحة"><a href="<?= e(app_url('student/index.php')) ?>">الرئيسية</a><i class="bi bi-chevron-left" aria-hidden="true"></i><span>الطلبات</span></nav>
<div class="page-heading requests-heading">
    <div>
        <span class="eyebrow"><i class="bi bi-compass"></i> طلبات الطلاب</span>
        <h1>الطلبات</h1>
        <p>طلبات المساعدة التي نشرها الطلاب. افتح طلبًا لتقرأ تفاصيله ثم أرسل عرضك إن استطعت المساعدة.</p>
    </div>
    <?php if (me()['status'] === 'active'): ?>
        <a href="<?= e($createRequestUrl) ?>" class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i> أنشئ طلبًا أنت</a>
    <?php endif; ?>
</div>

<div class="search-panel-heading"><strong>ابحث عن طلب مناسب</strong><span>النتائج هنا تستقبل عروضًا حاليًا، مرتبة من الأحدث.</span></div>
<form class="search-panel" method="get" role="search">
    <div class="search-field search-keyword">
        <label class="visually-hidden" for="request-search">البحث في عنوان الطلب أو وصفه</label>
        <i class="bi bi-search" aria-hidden="true"></i>
        <input id="request-search" class="form-control" name="q" value="<?= e($term) ?>" placeholder="ابحث بعنوان الطلب أو وصفه">
    </div>
    <div class="search-field search-major">
        <label class="search-major-label" for="request-major">التخصص</label>
        <i class="bi bi-mortarboard" aria-hidden="true"></i>
        <select id="request-major" class="form-select" name="major" aria-label="التخصص">
            <option value="">جميع التخصصات</option>
            <?php foreach ($majors as $availableMajor): ?>
                <option value="<?= e($availableMajor) ?>" <?= $major === $availableMajor ? 'selected' : '' ?>><?= e($availableMajor) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn btn-primary search-submit" type="submit">بحث</button>
    <?php if ($term !== '' || $major !== ''): ?><a class="search-clear" href="<?= e(app_url('requests/index.php?major=')) ?>">مسح الفلاتر</a><?php endif; ?>
</form>

<div class="results-heading">
    <span><strong><?= $pagination['total'] ?></strong> طلب<?= $pagination['total'] === 1 ? '' : 'ات' ?> متاح</span>
    <?php if ($major !== ''): ?><span class="active-filter"><i class="bi bi-funnel"></i> <?= e($major) ?> <a href="<?= e(app_url('requests/index.php?q=' . urlencode($term) . '&major=')) ?>" aria-label="إزالة فلتر التخصص">×</a></span><?php endif; ?>
</div>

<?php if ($requests): ?>
    <div class="request-results" aria-label="نتائج طلبات المساعدة">
        <?php foreach ($requests as $request): ?>
            <article class="request-result">
                <div class="request-result-main">
                    <div class="request-result-meta">
                        <span class="type-label"><i class="bi bi-bookmark"></i>نوع المساعدة: <?= e($request['type_name']) ?></span>
                        <span class="ux-status-caption">حالة الطلب</span><?= status_badge($request['status']) ?>
                    </div>
                    <h2><a href="<?= e(ui_context_url('requests/view.php', ['id' => (int)$request['id']] + $returnContextParams)) ?>"><?= e($request['title']) ?></a></h2>
                    <p><?= e(mb_strimwidth($request['description'], 0, 120, '…')) ?></p>
                    <div class="request-result-byline">
                        <span><i class="bi bi-person"></i>صاحب الطلب: <?= e($request['full_name']) ?></span>
                        <span><i class="bi bi-mortarboard"></i><?= e($request['major']) ?></span>
                        <?php if ($request['subject']): ?><span><i class="bi bi-journal"></i><?= e($request['subject']) ?></span><?php endif; ?>
                        <time datetime="<?= e(date(DATE_ATOM, strtotime($request['created_at']))) ?>"><i class="bi bi-clock"></i><?= e(date('Y-m-d', strtotime($request['created_at']))) ?></time>
                    </div>
                </div>
                <a class="btn btn-outline-primary request-result-action" href="<?= e(ui_context_url('requests/view.php', ['id' => (int)$request['id']] + $returnContextParams)) ?>">عرض الطلب <i class="bi bi-arrow-left"></i></a>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="results-empty">
        <span class="results-empty-icon"><i class="bi bi-search"></i></span>
        <h2>لم نعثر على طلبات مطابقة</h2>
        <p>جرّب تغيير كلمات البحث أو إزالة فلتر التخصص.</p>
        <a class="btn btn-outline-primary" href="<?= e(app_url('requests/index.php?major=')) ?>">عرض كل التخصصات</a>
    </div>
<?php endif; ?>
<?= pagination_ui($pagination, 'الطلبات') ?>
<?php footer_ui(); ?>

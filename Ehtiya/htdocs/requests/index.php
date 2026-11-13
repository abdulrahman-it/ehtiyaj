<?php
/** Student request search, limited to published requests that are receiving offers. */
require __DIR__ . '/../includes/bootstrap.php';
need_role('student');

if (array_key_exists('q', $_GET) && !is_string($_GET['q'])) {
    http_response_code(400);
    exit('عبارة البحث غير صالحة.');
}
$term = trim(input_string('q', 'get'));
$majors = active_majors();
if (array_key_exists('type', $_GET) && !is_string($_GET['type'])) {
    http_response_code(400);
    exit('نوع المساعدة غير صالح.');
}
// Include disabled types: older published requests may still reference them.
$assistanceTypes = $pdo->query('SELECT id, name, is_active FROM assistance_types ORDER BY name, id')->fetchAll();
$requestedType = input_string('type', 'get');
$typeId = null;
$typeName = '';
if ($requestedType !== '') {
    foreach ($assistanceTypes as $type) {
        if ((string)$type['id'] === $requestedType) {
            $typeId = (int)$type['id'];
            $typeName = (string)$type['name'];
            break;
        }
    }
    if ($typeId === null) {
        $recoveryPage = pagination_state(0);
        $safeMajor = trim(input_string('major', 'get'));
        $recoveryParams = ['q' => $term, 'per_page' => $recoveryPage['per_page']];
        if (in_array($safeMajor, $majors, true)) $recoveryParams['major'] = $safeMajor;
        http_response_code(400);
        header_ui('تصفح الطلبات');
        echo '<div class="alert alert-warning" role="alert">نوع المساعدة غير صالح أو لم يعد موجودًا.</div>';
        echo '<a class="btn btn-outline-primary" href="' . e(ui_context_url('requests/index.php', $recoveryParams)) . '">إزالة فلتر نوع المساعدة والعودة إلى الطلبات</a>';
        footer_ui();
        exit;
    }
}
if (array_key_exists('major', $_GET) && !is_string($_GET['major'])) {
    http_response_code(400);
    exit('قيمة التخصص غير صالحة.');
}
$requestedMajor = trim(input_string('major', 'get'));
if ($requestedMajor !== '' && !in_array($requestedMajor, $majors, true)) {
    // A saved filter may refer to a major disabled after the link was created.
    foreach (major_catalog() as $catalogMajor) {
        if (!$catalogMajor['active'] && $catalogMajor['name'] === $requestedMajor) {
            $recoveryPage = pagination_state(0);
            $recoveryParams = ['q' => $term, 'per_page' => $recoveryPage['per_page']];
            if ($typeId !== null) $recoveryParams['type'] = $typeId;
            $recoveryUrl = ui_context_url('requests/index.php', $recoveryParams);
            http_response_code(400);
            header_ui('تصفح الطلبات');
            echo '<div class="alert alert-warning" role="alert">التخصص المحدد لم يعد نشطًا ولا يمكن التصفية به حاليًا.</div>';
            echo '<a class="btn btn-outline-primary" href="' . e($recoveryUrl) . '">إزالة فلتر التخصص والعودة إلى الطلبات</a>';
            footer_ui();
            exit;
        }
    }
    http_response_code(400);
    exit('قيمة التخصص غير صالحة.');
}
$major = $requestedMajor;
// Add only validated optional filters; all user-provided values remain bound parameters.
$sql = "SELECT r.*, u.full_name, t.name AS type_name
        FROM requests r
        JOIN users u ON u.id = r.owner_id
        JOIN assistance_types t ON t.id = r.assistance_type_id
        WHERE r.moderation_status = 'published'
          AND r.status = 'receiving_offers'
          AND r.owner_id <> ?";
$parameters = [me()['id']];
if ($typeId !== null) {
    $sql .= ' AND r.assistance_type_id = ?';
    $parameters[] = $typeId;
}
if ($major !== '') {
    $sql .= ' AND r.major = ?';
    $parameters[] = $major;
}
if ($term !== '') {
    $sql .= ' AND (r.title LIKE ? OR r.description LIKE ? OR r.subject LIKE ?)';
    array_push($parameters, '%' . $term . '%', '%' . $term . '%', '%' . $term . '%');
}
// Mirror the result filters in the count query before calculating pagination.
$countSql = 'SELECT COUNT(*) FROM requests r JOIN users u ON u.id=r.owner_id JOIN assistance_types t ON t.id=r.assistance_type_id WHERE r.moderation_status=\'published\' AND r.status=\'receiving_offers\' AND r.owner_id<>?';
$countParams = [me()['id']];
if ($typeId !== null) {
    $countSql .= ' AND r.assistance_type_id=?';
    $countParams[] = $typeId;
}
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
    'back_type' => $typeId ?? '',
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
<form class="search-panel discovery-search" method="get" role="search">
    <input type="hidden" name="per_page" value="<?= (int)$pagination['per_page'] ?>">
    <div class="search-field search-keyword">
        <label class="visually-hidden" for="request-search">البحث في عنوان الطلب أو وصفه أو مادته</label>
        <i class="bi bi-search" aria-hidden="true"></i>
        <input id="request-search" class="form-control" name="q" value="<?= e($term) ?>" list="request-title-suggestions" aria-describedby="request-search-help" placeholder="ابحث بالعنوان أو الوصف أو المادة">
    </div>
    <div class="search-field search-type">
        <label class="form-label" for="request-type-filter">نوع المساعدة</label>
        <select id="request-type-filter" class="form-select" name="type">
            <option value="">جميع أنواع المساعدة</option>
            <?php foreach ($assistanceTypes as $type): ?>
                <option value="<?= e($type['id']) ?>" <?= $typeId !== null && (int)$type['id'] === $typeId ? 'selected' : '' ?>><?= e($type['name']) ?><?= !(int)$type['is_active'] ? ' (غير متاح للطلبات الجديدة)' : '' ?></option>
            <?php endforeach; ?>
        </select>
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
    <?php if ($term !== '' || $major !== '' || $typeId !== null): ?><a class="search-clear" href="<?= e(ui_context_url('requests/index.php', ['per_page' => $pagination['per_page']])) ?>">مسح الفلاتر</a><?php endif; ?>
</form>
<p id="request-search-help" class="discovery-search-help">اقتراحات العناوين من النتائج الظاهرة في هذه الصفحة فقط؛ لا تُرسل عمليات بحث أثناء الكتابة.</p>
<datalist id="request-title-suggestions">
    <?php $suggestedTitles = []; foreach ($requests as $request):
        $title = (string)$request['title'];
        if (isset($suggestedTitles[$title]) || count($suggestedTitles) >= 10) continue;
        $suggestedTitles[$title] = true;
    ?><option value="<?= e($title) ?>"></option><?php endforeach; ?>
</datalist>

<div class="results-heading discovery-results-heading">
    <span><strong><?= $pagination['total'] ?></strong> طلب<?= $pagination['total'] === 1 ? '' : 'ات' ?> متاح</span>
    <?php if ($major !== ''): ?><span class="active-filter"><i class="bi bi-funnel"></i> <?= e($major) ?> <a href="<?= e(ui_context_url('requests/index.php', ['q' => $term, 'type' => $typeId ?? '', 'per_page' => $pagination['per_page']])) ?>" aria-label="إزالة فلتر التخصص">×</a></span><?php endif; ?>
    <?php if ($typeId !== null): ?><span class="active-filter"><i class="bi bi-funnel" aria-hidden="true"></i> <?= e($typeName) ?> <a href="<?= e(ui_context_url('requests/index.php', ['q' => $term, 'major' => $major, 'per_page' => $pagination['per_page']])) ?>" aria-label="إزالة فلتر نوع المساعدة">×</a></span><?php endif; ?>
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
                        <span><i class="bi bi-mortarboard" aria-hidden="true"></i>التخصص: <?= e($request['major']) ?></span>
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
        <span class="results-empty-icon"><i class="bi bi-search" aria-hidden="true"></i></span>
        <?php if ($term !== '' || $major !== '' || $typeId !== null): ?>
            <h2>لم نعثر على طلبات مطابقة</h2>
            <p>جرّب كلمات بحث أخرى أو أزل الفلاتر لعرض جميع الطلبات المتاحة.</p>
            <a class="btn btn-outline-primary" href="<?= e(ui_context_url('requests/index.php', ['per_page' => $pagination['per_page']])) ?>">مسح البحث والفلاتر</a>
        <?php else: ?>
            <h2>لا توجد طلبات متاحة لك الآن</h2>
            <p>ستظهر هنا طلبات الطلاب المنشورة التي تستقبل عروضًا. يمكنك العودة لاحقًا أو إنشاء طلبك.</p>
            <a class="btn btn-outline-primary" href="<?= e($createRequestUrl) ?>">أنشئ طلبًا</a>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?= pagination_ui($pagination, 'الطلبات') ?>
<?php footer_ui(); ?>

<?php
/** Admin editor for the JSON-backed major catalog; the database schema has no majors table. */
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $action = input_string('action');
    // Load the current file-backed list so each edit preserves all unrelated catalog entries.
    $catalog = major_catalog();
    if ($action === 'add') {
        $name = trim(input_string('name'));
        if ($name === '' || mb_strlen($name) > 120) {
            $error = 'اسم التخصص مطلوب وبحد أقصى 120 حرفًا.';
        } else {
            $exists = false;
            foreach ($catalog as $major) {
                if (mb_strtolower($major['name']) === mb_strtolower($name)) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) {
                $error = 'التخصص موجود في القائمة بالفعل.';
            } else {
                $catalog[] = ['name' => $name, 'active' => true];
                $success = 'أُضيف التخصص إلى قائمة التسجيل والتصفية.';
            }
        }
    } elseif ($action === 'toggle') {
        $name = trim(input_string('name'));
        $found = false;
        foreach ($catalog as &$major) {
            if (hash_equals($major['name'], $name)) {
                $major['active'] = !$major['active'];
                $found = true;
                break;
            }
        }
        unset($major);
        if (!$found) {
            $error = 'التخصص غير موجود.';
        } else {
            $success = 'حُدثت حالة التخصص. لن تتأثر قيم users.major أو لقطات الطلبات القديمة.';
        }
    } else {
        $error = 'الإجراء غير معروف.';
    }
    // Persist only a validated catalog change; the helper performs a locked atomic file replacement.
    if ($error === '' && !write_major_catalog($catalog)) {
        $error = 'تعذر حفظ القائمة. تحقق من صلاحية الكتابة إلى config/majors.json.';
        $success = '';
    }
    if ($error === '') {
        audit($action === 'add' ? 'add_major' : 'toggle_major', 'major', null, $action === 'add' ? 'إضافة تخصص' : 'تغيير حالة تخصص');
    }
}
$catalog = major_catalog();
header_ui('إدارة التخصصات');
?>
<?= admin_page_back_link('dashboard') ?><?= admin_page_intro('إدارة التخصصات', 'القائمة معتمدة للتسجيل والتصفية وتغيير تخصص الطالب؛ تعطيل خيار لا يغيّر سجلات الطلبات السابقة.', 'mortarboard') ?>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
<div class="card p-4 mb-4">
    <h2 class="h5">إضافة تخصص</h2>
    <form method="post" class="d-flex flex-wrap gap-2">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="add">
        <label class="visually-hidden" for="major-name">اسم التخصص</label>
        <input id="major-name" class="form-control" name="name" maxlength="120" placeholder="اسم التخصص" required>
        <button class="btn btn-primary" type="submit">إضافة</button>
    </form>
</div>
<div class="card p-3"><div class="table-responsive"><table class="table"><thead><tr><th>التخصص</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody>
<?php foreach ($catalog as $major): ?>
<tr><td><?= e($major['name']) ?></td><td><?= status_badge($major['active'] ? 'active' : 'deactivated') ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="name" value="<?= e($major['name']) ?>"><button class="btn btn-sm btn-outline-<?= $major['active'] ? 'danger' : 'success' ?>" type="submit"><?= $major['active'] ? 'تعطيل' : 'تفعيل' ?></button></form></td></tr>
<?php endforeach; ?>
<?php if (!$catalog): ?><tr><td colspan="3" class="empty">لا توجد تخصصات في القائمة.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php footer_ui(); ?>

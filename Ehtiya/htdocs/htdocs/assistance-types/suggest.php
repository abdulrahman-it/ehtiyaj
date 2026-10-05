<?php
/** Student proposal form; a suggestion is pending until an admin reviews it. */
require __DIR__.'/../includes/bootstrap.php';
need_role('student');
active_write();
$back = ui_back_context('profile');
// Commit the suggestion and notices to active admins together so the review queue stays in sync.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $name = trim(input_string('name'));
    $details = trim(input_string('details'));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        flash('اسم النوع مطلوب وبحد أقصى 100 حرف.', 'danger');
    } else {
        try {
            $pdo->beginTransaction();
            if (!lock_active_student((int)me()['id'])) {
                throw new RuntimeException('الحساب لم يعد نشطًا.');
            }
            $pdo->prepare('INSERT INTO assistance_type_suggestions(student_id,suggested_name,details) VALUES(?,?,?)')->execute([me()['id'],$name,$details !== '' ? $details : null]);
            foreach ($pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'") as $admin) {
                notify((int)$admin['id'], 'assistance_type_suggestion', 'اقتراح نوع مساعدة', 'ورد اقتراح جديد لمراجعته.', '/admin/assistance_types.php');
            }
            $pdo->commit();
            flash('تم إرسال اقتراحك للمراجعة.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Ehtiyaj] Assistance suggestion failed: '.$exception->getMessage());
            flash('تعذر إرسال الاقتراح. لم تُحفظ العملية.', 'danger');
        }
    }
    go('/assistance-types/suggest.php');
}
header_ui('اقتراح نوع'); ?>
<?= page_back_link($back['url'], $back['label']) ?>
<header class="ux-page-header">
    <div><span class="eyebrow"><i class="bi bi-lightbulb" aria-hidden="true"></i> مشاركة المجتمع</span><h1>اقتراح نوع مساعدة</h1><p>سيصل الاقتراح إلى الإدارة للمراجعة ولا ينشر مباشرة.</p></div>
</header>
<section class="card suggestion-form-card">
    <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf())?>">
        <div class="suggestion-form-field"><label class="form-label" for="suggestion-name">النوع المقترح</label><input id="suggestion-name" name="name" maxlength="100" class="form-control" required></div>
        <div class="suggestion-form-field"><label class="form-label" for="suggestion-details">تفاصيل (اختياري)</label><textarea id="suggestion-details" name="details" class="form-control"></textarea></div>
        <button class="btn btn-primary" type="submit">إرسال الاقتراح</button>
    </form>
</section>
<?php footer_ui(); ?>
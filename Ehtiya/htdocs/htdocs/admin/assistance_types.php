<?php
/** Manage assistance-type catalog records and review student suggestions. */
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
// Route the existing create, update, toggle, accept, and reject actions after CSRF validation.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $action = input_string('action');
    try {
        if ($action === 'create' || $action === 'update') {
            $name = trim(input_string('name'));
            $id = input_int('id');
            if ($name === '' || mb_strlen($name) > 100 || ($action === 'update' && $id < 1)) {
                flash('اسم النوع مطلوب وبحد أقصى 100 حرف.', 'danger');
            } else {
                $pdo->beginTransaction();
                if ($action === 'create') {
                    $pdo->prepare('INSERT INTO assistance_types(name) VALUES(?)')->execute([$name]);
                    $entityId = (int)$pdo->lastInsertId();
                } else {
                    $update = $pdo->prepare('UPDATE assistance_types SET name=? WHERE id=?');
                    $update->execute([$name, $id]);
                    if ($update->rowCount() === 0) {
                        $exists = $pdo->prepare('SELECT id FROM assistance_types WHERE id=?');
                        $exists->execute([$id]);
                        if (!$exists->fetch()) {
                            throw new RuntimeException('نوع المساعدة غير موجود.');
                        }
                    }
                    $entityId = $id;
                }
                audit($action, 'assistance_type', $entityId, 'إدارة نوع مساعدة');
                $pdo->commit();
            }
        } elseif ($action === 'toggle') {
            $id = input_int('id');
            // Serialize a toggle so concurrent admins cannot derive a new state from the same stale value.
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT is_active FROM assistance_types WHERE id=? FOR UPDATE');
            $lock->execute([$id]);
            $type = $lock->fetch();
            if ($type) {
                $newState = (int)$type['is_active'] === 1 ? 0 : 1;
                $pdo->prepare('UPDATE assistance_types SET is_active=? WHERE id=?')->execute([$newState, $id]);
                audit('toggle', 'assistance_type', $id, 'تغيير حالة النوع');
            }
            $pdo->commit();
        } elseif ($action === 'accept' || $action === 'reject') {
            $id = input_int('id');
            $pdo->beginTransaction();
            // Review only a still-pending suggestion; the lock protects the one-time decision and its result.
            $lock = $pdo->prepare("SELECT * FROM assistance_type_suggestions WHERE id=? AND status='pending' FOR UPDATE");
            $lock->execute([$id]);
            $suggestion = $lock->fetch();
            if ($suggestion) {
                if ($action === 'accept') {
                    $typeValue = optional_input_string('type_id');
                    if ($typeValue === null || !preg_match('/^[0-9]+$/D', $typeValue)) {
                        throw new RuntimeException('اختر نوع مساعدة صالحًا.');
                    }
                    $typeId = (int)$typeValue;
                    if ($typeId === 0) {
                        $pdo->prepare('INSERT INTO assistance_types(name) VALUES(?)')->execute([$suggestion['suggested_name']]);
                        $typeId = (int)$pdo->lastInsertId();
                    } else {
                        $typeCheck = $pdo->prepare('SELECT id FROM assistance_types WHERE id=?');
                        $typeCheck->execute([$typeId]);
                        if (!$typeCheck->fetch()) {
                            throw new RuntimeException('نوع المساعدة المحدد غير موجود.');
                        }
                    }
                    $review = $pdo->prepare("UPDATE assistance_type_suggestions SET status='accepted',reviewed_by=?,resulting_type_id=? WHERE id=? AND status='pending'");
                    $review->execute([me()['id'], $typeId, $id]);
                } else {
                    $note = trim(input_string('note'));
                    $review = $pdo->prepare("UPDATE assistance_type_suggestions SET status='rejected',reviewed_by=?,note_review=? WHERE id=? AND status='pending'");
                    $review->execute([me()['id'], $note !== '' ? $note : null, $id]);
                }
                if ($review->rowCount() !== 1) {
                    throw new RuntimeException('تغيرت حالة الاقتراح.');
                }
                $accepted = $action === 'accept';
                notify((int)$suggestion['student_id'], $accepted ? 'assistance_type_suggestion_accepted' : 'assistance_type_suggestion_rejected', $accepted ? 'قُبل اقتراح نوع المساعدة' : 'رُفض اقتراح نوع المساعدة', $accepted ? 'اعتمد المدير اقتراحك.' : 'راجع المدير اقتراحك ولم يعتمده.');
                audit('review_suggestion', 'suggestion', $id, 'مراجعة اقتراح نوع مساعدة');
            }
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Ehtiyaj] Assistance type operation failed: '.$exception->getMessage());
        flash('تعذر حفظ التغيير. لم تُحفظ العملية.', 'danger');
    }
    go('/admin/assistance_types.php');
}
$types = $pdo->query('SELECT * FROM assistance_types ORDER BY name')->fetchAll();
$suggestionCount = (int)$pdo->query('SELECT COUNT(*) FROM assistance_type_suggestions')->fetchColumn();
$suggestionPagination = pagination_state($suggestionCount);
$suggestionQuery = $pdo->prepare('SELECT s.*,u.full_name FROM assistance_type_suggestions s JOIN users u ON u.id=s.student_id ORDER BY s.created_at DESC,s.id DESC LIMIT ? OFFSET ?');
$suggestionQuery->bindValue(1, $suggestionPagination['per_page'], PDO::PARAM_INT);
$suggestionQuery->bindValue(2, $suggestionPagination['offset'], PDO::PARAM_INT);
$suggestionQuery->execute();
$suggestions = $suggestionQuery->fetchAll();
header_ui('أنواع المساعدة');?><?= admin_page_back_link('dashboard') ?><?= admin_page_intro('أنواع المساعدة', 'إدارة أنواع المساعدة ومراجعة اقتراحات الطلاب.', 'grid') ?><div class="card p-4 mb-3"><h5>إضافة نوع</h5><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="create"><input name="name" class="form-control" maxlength="100" required placeholder="اسم النوع"><button class="btn btn-primary">إضافة</button></form></div><div class="card p-3 mb-4"><div class="table-responsive"><table class="table"><thead><tr><th>الاسم</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody><?php foreach ($types as $t) {?><tr><td><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=$t['id']?>"><input name="name" class="form-control" value="<?=e($t['name'])?>"><button class="btn btn-outline-primary">حفظ</button></form></td><td><?=$t['is_active'] ? 'مفعل' : 'معطل'?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="btn btn-sm btn-outline-secondary">تفعيل/تعطيل</button></form></td></tr><?php }?></tbody></table></div></div><h2>اقتراحات الطلاب</h2><?php foreach ($suggestions as $s) {?><div class="card p-3 mb-2"><strong><?=e($s['suggested_name'])?></strong> — <?=e($s['full_name'])?> <span class="pill"><?=e($s['status'])?></span><p><?=e($s['details'])?></p><?php if ($s['status'] === 'pending') {?><div class="d-flex flex-wrap gap-2"><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="accept"><input type="hidden" name="id" value="<?=$s['id']?>"><select class="form-select" name="type_id"><option value="0">إنشاء نوع جديد</option><?php foreach ($types as $t) {?><option value="<?=$t['id']?>"><?=e($t['name'])?></option><?php }?></select><button class="btn btn-success">قبول</button></form><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?=$s['id']?>"><input class="form-control" name="note" placeholder="ملاحظة"><button class="btn btn-outline-danger">رفض</button></form></div><?php }?></div><?php }?><?=pagination_ui($suggestionPagination, 'اقتراحات أنواع المساعدة')?><?php footer_ui();?>
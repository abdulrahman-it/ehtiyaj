<?php
/** Participant-only conversation view with guarded sending, report submission, and read receipts. */
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/upload.php';
need_role('student');
$id = input_int('id', 'get');
// Require the current user to be a participant before loading conversation context.
$q = $pdo->prepare('SELECT c.*,r.title,r.status request_status,r.moderation_status,IF(c.owner_id=?,c.helper_id,c.owner_id) other_id FROM conversations c JOIN requests r ON r.id=c.request_id WHERE c.id=? AND (c.owner_id=? OR c.helper_id=?)');
$q->execute([me()['id'],$id,me()['id'],me()['id']]);
$c = $q->fetch();
if (!$c) {
    http_response_code(404);
    exit('المحادثة غير موجودة.');
}$back = ui_back_context('chats');
// Sending requires an active account, open conversation, in-progress request, and published moderation state.
$can = $c['status'] === 'open' && $c['request_status'] === 'in_progress' && $c['moderation_status'] === 'published' && me()['status'] === 'active';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (request_body_exceeds_post_max()) {
        http_response_code(413);
        exit('حجم الرسالة المرسلة يتجاوز الحد المسموح. قلل حجم الصورة وأعد المحاولة.');
    }
    post_only();
    active_write();
    $action = input_string('action');
    if ($action === 'send') {
        if (!$can) {
            http_response_code(409);
            exit('المحادثة للقراءة فقط.');
        }
        $img = null;
        try {
            $body = trim(input_string('body'));
            if (mb_strlen($body) > 3000) {
                throw new RuntimeException('الرسالة تتجاوز 3000 حرف.');
            }
            // Re-lock account and conversation/request state so a stale page cannot send after closure.
            $pdo->beginTransaction();
            if (!lock_active_student((int)me()['id'])) {
                throw new RuntimeException('الحساب لم يعد نشطًا.');
            }
            $lock = $pdo->prepare('SELECT c.status,c.owner_id,c.helper_id,r.status request_status,r.moderation_status FROM conversations c JOIN requests r ON r.id=c.request_id WHERE c.id=? AND (c.owner_id=? OR c.helper_id=?) FOR UPDATE');
            $lock->execute([$id, me()['id'], me()['id']]);
            $current = $lock->fetch();
            if (!$current || $current['status'] !== 'open' || $current['request_status'] !== 'in_progress' || $current['moderation_status'] !== 'published') {
                throw new RuntimeException('المحادثة للقراءة فقط.');
            }
            $otherId = (int)$current['owner_id'] === (int)me()['id'] ? (int)$current['helper_id'] : (int)$current['owner_id'];
            $img = save_image($_FILES['image'] ?? null, 'messages');
            if ($body === '' && !$img) {
                throw new RuntimeException('اكتب رسالة أو أرفق صورة.');
            }
            $pdo->prepare('INSERT INTO messages(conversation_id,sender_id,body,image_path,image_mime,image_size_bytes) VALUES(?,?,?,?,?,?)')->execute([$id,me()['id'],$body ?: null,$img['path'] ?? null,$img['mime'] ?? null,$img['size'] ?? null]);
            $pdo->prepare('UPDATE conversations SET updated_at=NOW() WHERE id=?')->execute([$id]);
            notify($otherId, 'new_message', 'رسالة جديدة', 'وصلتك رسالة في محادثة مساعدة.', '/chat/view.php?id='.$id);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!empty($img['path'])) {
                $diskPath = __DIR__.'/..'.$img['path'];
                if (is_file($diskPath)) {
                    @unlink($diskPath);
                }
            }
            if (!($e instanceof RuntimeException) || $e instanceof PDOException) {
                error_log('[Ehtiyaj] Message send failed: '.$e->getMessage());
            }
            $safeMessage = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'تعذر إرسال الرسالة.';
            flash($safeMessage, 'danger');
        }
    }if ($action === 'report') {
        if (trim(input_string('reason')) === '') {
            flash('سبب البلاغ مطلوب.', 'danger');
        } else {
            try {
                $pdo->beginTransaction();
                if (!lock_active_student((int)me()['id'])) {
                    throw new RuntimeException('الحساب لم يعد نشطًا.');
                }
                // Store the report and notify active admins together; report access does not grant admin send permission.
                $pdo->prepare('INSERT INTO conversation_reports(conversation_id,reporter_id,reason) VALUES(?,?,?)')->execute([$id,me()['id'],trim(input_string('reason'))]);
                foreach ($pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'") as $a) {
                    notify((int)$a['id'], 'new_report', 'بلاغ جديد', 'ورد بلاغ يحتاج إلى مراجعة.', '/admin/reports.php');
                }
                $pdo->commit();
                flash('تم إرسال البلاغ.');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[Ehtiyaj] Conversation report failed: '.$exception->getMessage());
                flash('تعذر إرسال البلاغ. لم تُحفظ العملية.', 'danger');
            }
        }
    }$redirectParams = ['id' => $id] + $back['forward'];
    go('/chat/view.php?' . http_build_query($redirectParams, '', '&', PHP_QUERY_RFC3986));
}
// مقصود وظيفيًا: فتح المحادثة يحدّث إيصالات قراءة رسائل الطرف الآخر.
$pdo->prepare('UPDATE messages SET is_read=1 WHERE conversation_id=? AND sender_id<>?')->execute([$id,me()['id']]);
$messageCount = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE conversation_id=?');
$messageCount->execute([$id]);
$messagePagination = pagination_state((int)$messageCount->fetchColumn(), 20);
$m = $pdo->prepare('SELECT * FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT ? OFFSET ?');
$m->bindValue(1, $id, PDO::PARAM_INT);
$m->bindValue(2, $messagePagination['per_page'], PDO::PARAM_INT);
$m->bindValue(3, $messagePagination['offset'], PDO::PARAM_INT);
$m->execute();
$messages = array_reverse($m->fetchAll());
$other = $pdo->prepare('SELECT full_name,email,phone,email_visibility,phone_visibility FROM users WHERE id=?');
$other->execute([$c['other_id']]);
$o = $other->fetch();
$contact = $pdo->prepare("SELECT COUNT(*) FROM conversations c JOIN offers f ON f.id=c.offer_id WHERE c.request_id=? AND c.status IN ('open','read_only') AND ((c.owner_id=? AND c.helper_id=?) OR (c.owner_id=? AND c.helper_id=?)) AND f.status='accepted'");
$contact->execute([$c['request_id'],me()['id'],$c['other_id'],$c['other_id'],me()['id']]);
$accepted = (bool)$contact->fetchColumn();
header_ui('المحادثة');?><nav class="ux-breadcrumb" aria-label="مسار الصفحة"><a href="<?=e(app_url('student/index.php'))?>">الرئيسية</a><i class="bi bi-chevron-left" aria-hidden="true"></i><span>المحادثة: <?=e($c['title'])?></span></nav><?= page_back_link($back['url'], $back['label']) ?><section class="chat-context-card" aria-label="سياق المحادثة"><div class="chat-context-title"><span class="conversation-card-icon"><i class="bi bi-chat-square-text"></i></span><div><span class="section-kicker">محادثة حول الطلب</span><h1><?=e($c['title'])?></h1><p>مع <strong><?=e($o['full_name'])?></strong></p></div></div><div class="chat-context-status"><div><span>حالة الطلب</span><?=status_badge($c['request_status'])?></div><div><span>حالة المحادثة</span><?=status_badge($c['status'])?></div></div><a class="chat-return-request" href="<?=e(ui_context_url('requests/view.php', ['id' => (int)$c['request_id'], 'return_to' => 'chats', 'back_page' => 1, 'back_per_page' => 20]))?>"><i class="bi bi-arrow-right"></i> عرض تفاصيل الطلب المرتبط <i class="bi bi-box-arrow-up-left" aria-hidden="true"></i></a><?php if (!$can): ?><p class="chat-state-note<?= ($c['status'] === 'read_only' && $c['read_only_reason'] === 'report') ? ' chat-admin-closed' : '' ?>" role="status"><i class="bi <?= ($c['status'] === 'read_only' && $c['read_only_reason'] === 'report') ? 'bi-shield-lock' : 'bi-info-circle' ?>" aria-hidden="true"></i> <?php if ($c['status'] === 'read_only' && $c['read_only_reason'] === 'report'): ?>تم إغلاق هذه المحادثة بواسطة الإدارة، ولا يمكن إرسال رسائل جديدة.<?php elseif (me()['status'] === 'suspended'): ?>حسابك موقوف مؤقتًا؛ يمكنك قراءة المحادثة دون إرسال رسائل.<?php elseif ($c['request_status'] === 'completed'): ?>اكتملت المساعدة؛ يمكنك الرجوع إلى الرسائل السابقة فقط.<?php elseif ($c['request_status'] === 'cancelled'): ?>أُلغي الطلب؛ أصبحت هذه المحادثة للقراءة فقط.<?php else: ?>يمكنك الاطلاع على الرسائل السابقة.<?php endif; ?></p><?php endif; ?><?php if ($accepted && (($o['email_visibility'] === 'accepted_only') || ($o['phone_visibility'] === 'accepted_only'))) {?><small class="chat-contact-note mt-2"><?php if ($o['email_visibility'] === 'accepted_only') {
    echo 'البريد: '.e($o['email']).' · ';
}if ($o['phone_visibility'] === 'accepted_only') {
    echo 'الهاتف: '.e($o['phone']);
}?></small><?php }?></section><?= pagination_ui($messagePagination, 'الرسائل') ?><div class="card p-3 mb-3 chat-messages"><div class="d-flex flex-column gap-3"><?php foreach ($messages as $msg) {?><div class="message <?=$msg['sender_id'] == me()['id'] ? 'mine' : ''?>"><div><?=nl2br(e($msg['body'] ?? ''))?></div><?php if ($msg['image_path'] && me()['status'] === 'active') {?><img class="message-image" src="<?=e(app_url('chat/image.php?message_id=' . (int)$msg['id']))?>" alt="صورة مرفقة" loading="lazy"><?php } elseif ($msg['image_path']) {?><small class="text-secondary">مرفق الصورة غير متاح للحساب الموقوف.</small><?php }?><small class="d-block text-secondary mt-1"><?=e($msg['created_at'])?></small></div><?php }if (!$messages) {?><div class="chat-empty-state"><i class="bi bi-chat-heart"></i><strong>ابدأ التواصل بشأن هذه المساعدة</strong><span>كل رسالة هنا مرتبطة بطلب «<?=e($c['title'])?>» مع <?=e($o['full_name'])?>.</span></div><?php }?></div></div><?= pagination_ui($messagePagination, 'الرسائل') ?><?php if ($can) {?><div class="card p-3 mb-3 chat-composer"><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="send"><label class="visually-hidden" for="chat-message-body">رسالتك بشأن <?=e($c['title'])?></label><textarea id="chat-message-body" name="body" class="form-control mb-2" rows="3" maxlength="3000" placeholder="اكتب رسالتك إلى <?=e($o['full_name'])?> بشأن هذه المساعدة"></textarea><div class="d-flex gap-2"><input id="message-image" type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp" data-image-preview="message-image-preview" aria-label="إرفاق صورة للرسالة"><button class="btn btn-primary"><i class="bi bi-send"></i> إرسال الرسالة</button></div><div id="message-image-preview" class="image-preview-grid mt-2" aria-live="polite"></div></form></div><?php } else { ?><div class="card chat-readonly-composer" role="status"><span><i class="bi bi-lock" aria-hidden="true"></i> <?php if ($c['status'] === 'read_only' && $c['read_only_reason'] === 'report'): ?>تم إغلاق هذه المحادثة بواسطة الإدارة، ولا يمكن إرسال رسائل جديدة.<?php else: ?>هذه المحادثة للقراءة فقط حاليًا. يمكنك مراجعة الرسائل السابقة أعلاه.<?php endif; ?></span></div><?php }?><?php if (me()['status'] === 'active') {?><div class="card p-3 chat-report"><details><summary class="text-danger">الإبلاغ عن مشكلة</summary><form method="post" class="mt-3"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="report"><textarea name="reason" class="form-control mb-2" required placeholder="اشرح المشكلة"></textarea><button class="btn btn-outline-danger">إرسال البلاغ</button></form></details></div><?php }?><?php footer_ui();?>
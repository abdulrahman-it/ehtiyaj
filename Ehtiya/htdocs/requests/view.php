<?php
/** Request detail with the existing guarded offer and request-state actions. */
require __DIR__.'/../includes/bootstrap.php';
need_role('student');
$id = input_int('id', 'get');
// Only published requests are visible; state-changing actions recheck current database state before writing.
$q = $pdo->prepare("SELECT r.*,u.full_name,t.name type_name FROM requests r JOIN users u ON u.id=r.owner_id JOIN assistance_types t ON t.id=r.assistance_type_id WHERE r.id=? AND r.moderation_status='published'");
$q->execute([$id]);
$r = $q->fetch();
if (!$r) {
    http_response_code(404);
    exit('الطلب غير موجود.');
}$mine = $r['owner_id'] == me()['id'];
$defaultReturnContext = $mine ? 'mine' : 'public';
$back = ui_back_context($defaultReturnContext);
$requestChatContext = ['return_to' => 'chats', 'back_page' => 1, 'back_per_page' => 20];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only(['offers']);
    active_write();
    if (input_string('action') === 'offer' && !$mine && $r['status'] === 'receiving_offers') {
        $msg = trim(input_string('message'));
        if (strlen($msg) < 1 || mb_strlen($msg) > 1000) {
            flash('رسالة العرض مطلوبة وبحد أقصى 1000 حرف.', 'danger');
        } else {
            try {
                $pdo->beginTransaction();
                if (!lock_active_student((int)me()['id'])) {
                    throw new RuntimeException('الحساب لم يعد نشطًا.');
                }
                $lockRequest = $pdo->prepare("SELECT owner_id,status FROM requests WHERE id=? AND moderation_status='published' FOR UPDATE");
                $lockRequest->execute([$id]);
                $currentRequest = $lockRequest->fetch();
                if (!$currentRequest || $currentRequest['status'] !== 'receiving_offers' || (int)$currentRequest['owner_id'] === (int)me()['id']) {
                    throw new RuntimeException('لم يعد الطلب متاحًا لاستقبال العروض.');
                }
                $pdo->prepare('INSERT INTO offers(request_id,student_id,message) VALUES(?,?,?)')->execute([$id,me()['id'],$msg]);
                notify($currentRequest['owner_id'], 'new_offer', 'عرض مساعدة جديد', 'وصل عرض جديد على طلبك.', '/offers/my.php?request_id='.$id);
                $pdo->commit();
                flash('تم إرسال عرضك بنجاح. سيُشعَر صاحب الطلب ليتمكن من مراجعة عرضك.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('سبق أن قدمت عرضًا لهذا الطلب أو تعذر الإرسال.', 'warning');
            }
        }
    }if (input_string('action') === 'reject' && $mine) {
        $oid = input_int('offer_id');
        $z = $pdo->prepare("UPDATE offers SET status='rejected' WHERE id=? AND request_id=? AND status='submitted' AND EXISTS (SELECT 1 FROM requests WHERE requests.id=offers.request_id AND requests.owner_id=? AND requests.status='receiving_offers' AND requests.moderation_status='published') AND EXISTS (SELECT 1 FROM users WHERE users.id=? AND users.role='student' AND users.status='active')");
        $z->execute([$oid,$id,me()['id'],me()['id']]);
        if ($z->rowCount()) {
            $h = $pdo->prepare('SELECT student_id FROM offers WHERE id=?');
            $h->execute([$oid]);
            $helper = $h->fetchColumn();
            if ($helper) {
                notify($helper, 'offer_rejected', 'لم يتم قبول العرض', 'لم يتم اختيار عرضك لهذا الطلب.', '/offers/my.php');
            }flash('تم تحديث العرض.');
        }
    }
    // Accept selected submitted offers atomically; each accepted offer receives a linked conversation and notice.
    if (input_string('action') === 'accept' && $mine && $r['status'] === 'receiving_offers') {
        $submittedOffers = $_POST['offers'] ?? [];
        $oids = [];
        if (is_array($submittedOffers)) {
            foreach ($submittedOffers as $submittedOffer) {
                if (is_int($submittedOffer) || (is_string($submittedOffer) && preg_match('/^[0-9]+$/D', $submittedOffer))) {
                    $offerValue = (int)$submittedOffer;
                    if ($offerValue > 0) {
                        $oids[] = $offerValue;
                    }
                }
            }
            $oids = array_values(array_unique($oids));
        }
        if ($oids) {
            try {
                $pdo->beginTransaction();
                if (!lock_active_student((int)me()['id'])) {
                    throw new RuntimeException('الحساب لم يعد نشطًا.');
                }
                $lockRequest = $pdo->prepare("SELECT * FROM requests WHERE id=? AND owner_id=? AND moderation_status='published' FOR UPDATE");
                $lockRequest->execute([$id, me()['id']]);
                $currentRequest = $lockRequest->fetch();
                if (!$currentRequest || $currentRequest['status'] !== 'receiving_offers') {
                    throw new RuntimeException('تغيرت حالة الطلب ولم يعد يستقبل عروضًا.');
                }
                foreach ($oids as $oid) {
                    $z = $pdo->prepare("SELECT * FROM offers WHERE id=? AND request_id=? AND status='submitted' FOR UPDATE");
                    $z->execute([$oid,$id]);
                    $o = $z->fetch();
                    if (!$o) {
                        continue;
                    }$pdo->prepare("UPDATE offers SET status='accepted',accepted_at=NOW() WHERE id=?")->execute([$oid]);
                    $pdo->prepare('INSERT INTO conversations(request_id,offer_id,owner_id,helper_id) VALUES(?,?,?,?)')->execute([$id,$oid,$r['owner_id'],$o['student_id']]);
                    $cid = $pdo->lastInsertId();
                    notify($o['student_id'], 'offer_accepted', 'تم قبول عرضك', 'تم قبول عرض المساعدة الخاص بك.', '/chat/view.php?id='.$cid);
                }
                $acceptedCount = $pdo->prepare("SELECT COUNT(*) FROM offers WHERE request_id=? AND status='accepted'");
                $acceptedCount->execute([$id]);
                if ((int) $acceptedCount->fetchColumn() > 0) {
                    $pdo->prepare("UPDATE requests SET status='in_progress' WHERE id=? AND status='receiving_offers'")->execute([$id]);
                }
                $pdo->commit();
                flash('تم قبول العروض المحددة وإنشاء المحادثات.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }flash('تعذر إتمام العملية. أعد المحاولة.', 'danger');
            }
        } else {
            flash('حدد عرضًا صالحًا واحدًا على الأقل.', 'danger');
        }
    }
    // Completion/cancellation also makes related conversations read-only and notifies affected helpers.
    if (in_array(input_string('action'), ['complete','cancel'], true) && $mine) {
        $new = input_string('action') === 'complete' ? 'completed' : 'cancelled';
        if (($new === 'completed' && $r['status'] === 'in_progress') || ($new === 'cancelled' && in_array($r['status'], ['in_progress','receiving_offers']))) {
            try {
                $pdo->beginTransaction();
                if (!lock_active_student((int)me()['id'])) {
                    throw new RuntimeException('الحساب لم يعد نشطًا.');
                }
                if ($new === 'completed') {
                    $changed = $pdo->prepare("UPDATE requests SET status='completed' WHERE id=? AND owner_id=? AND status='in_progress'");
                    $changed->execute([$id, me()['id']]);
                } else {
                    $changed = $pdo->prepare("UPDATE requests SET status='cancelled' WHERE id=? AND owner_id=? AND status IN ('in_progress','receiving_offers')");
                    $changed->execute([$id, me()['id']]);
                }
                if ($changed->rowCount() === 1) {
                    $pdo->prepare("UPDATE conversations SET status='read_only',read_only_reason=? WHERE request_id=?")->execute([$new,$id]);
                    if ($new === 'completed') {
                        $q2 = $pdo->prepare("SELECT DISTINCT student_id FROM offers WHERE request_id=? AND status='accepted'");
                        $q2->execute([$id]);
                        foreach ($q2 as $p) {
                            notify((int)$p['student_id'], 'request_completed', 'اكتمل الطلب', 'أكمل صاحب الطلب المساعدة. أصبحت المحادثة للقراءة فقط.', '/chat/index.php');
                        }
                    } else {
                        $q2 = $pdo->prepare("SELECT DISTINCT student_id FROM offers WHERE request_id=? AND status IN ('submitted','accepted')");
                        $q2->execute([$id]);
                        foreach ($q2 as $p) {
                            notify((int)$p['student_id'], 'request_cancelled', 'تم إلغاء الطلب', 'ألغى صاحب الطلب هذا الطلب.', '/requests/view.php?id='.$id);
                        }
                    }
                    $pdo->commit();
                    flash('تم تحديث حالة الطلب.');
                } else {
                    $pdo->rollBack();
                    flash('تغيرت حالة الطلب. حدّث الصفحة ثم أعد المحاولة.', 'warning');
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[Ehtiyaj] Request transition failed: '.$exception->getMessage());
                flash('تعذر تحديث حالة الطلب. لم تُحفظ العملية.', 'danger');
            }
        }
    }$redirectParams = ['id' => $id] + $back['forward'];
    go('/requests/view.php?' . http_build_query($redirectParams, '', '&', PHP_QUERY_RFC3986));
}
// Load display data only after POST transitions finish, so the page reflects the saved state.
$imgs = $pdo->prepare('SELECT * FROM request_images WHERE request_id=? ORDER BY sort_order');
$imgs->execute([$id]);
$images = $imgs->fetchAll();
$offers = [];
if ($mine) {
    $z = $pdo->prepare('SELECT o.*,u.full_name,u.major FROM offers o JOIN users u ON u.id=o.student_id WHERE o.request_id=? ORDER BY o.created_at DESC');
    $z->execute([$id]);
    $offers = $z->fetchAll();
}$mineOffer = $pdo->prepare('SELECT id FROM offers WHERE request_id=? AND student_id=?');
$mineOffer->execute([$id,me()['id']]);
$already = (bool)$mineOffer->fetch();
header_ui('تفاصيل الطلب');?>
<nav class="ux-breadcrumb" aria-label="مسار الصفحة"><a href="<?=e(app_url('student/index.php'))?>">الرئيسية</a><i class="bi bi-chevron-left" aria-hidden="true"></i><span>تفاصيل الطلب: <?=e($r['title'])?></span></nav>
<?= page_back_link($back['url'], $back['label']) ?>
<div class="request-detail-layout">
 <article class="request-detail-main">
  <div class="request-detail-meta"><span class="type-label"><i class="bi bi-bookmark"></i>نوع المساعدة: <?=e($r['type_name'])?></span><span class="ux-status-caption">حالة الطلب</span><?=status_badge($r['status'])?></div>
  <h1><?=e($r['title'])?></h1>
  <div class="request-detail-byline"><span><i class="bi bi-person"></i>صاحب الطلب: <?=e($r['full_name'])?></span><span><i class="bi bi-mortarboard"></i><?=e($r['major'])?></span><?php if ($r['subject']) {?><span><i class="bi bi-journal"></i><?=e($r['subject'])?></span><?php }?><time datetime="<?=e(date(DATE_ATOM, strtotime($r['created_at'])))?>"><i class="bi bi-clock"></i><?=e(date('Y-m-d', strtotime($r['created_at'])))?></time></div>
  <section class="request-description-section"><h2><i class="bi bi-card-text" aria-hidden="true"></i> وصف الاحتياج</h2><p><?=nl2br(e($r['description']))?></p></section>
  <?php if ($images) {?><section class="request-images-section"><h2><i class="bi bi-images" aria-hidden="true"></i> صور مرفقة</h2><div class="request-image-gallery"><?php foreach ($images as $im) {?><a href="<?=e(app_url('requests/image.php?image_id=' . (int)$im['id']))?>" target="_blank" rel="noopener" aria-label="فتح الصورة بحجم أكبر"><img src="<?=e(app_url('requests/image.php?image_id=' . (int)$im['id']))?>" alt="صورة توضيحية للطلب" loading="lazy"></a><?php }?></div></section><?php }?>
 </article>
 <aside class="request-action-panel" aria-label="إجراءات الطلب">
  <?php if ($mine && me()['status'] === 'active') {?><span class="section-kicker">طلبك</span><h2><i class="bi bi-sliders" aria-hidden="true"></i> إدارة الطلب</h2><p class="action-panel-hint"><?php if ($r['status'] === 'receiving_offers'): ?>راجع العروض المقدمة هنا، ثم اختر مساعدًا.<?php elseif ($r['status'] === 'in_progress'): ?>تم قبول عرض المساعدة؛ تابع التواصل من محادثاتك.<?php elseif ($r['status'] === 'completed'): ?>اكتملت المساعدة. هذا الطلب مغلق الآن.<?php else: ?>أُلغي الطلب ولا يمكن استئناف إجراءاته.<?php endif; ?></p><?php if ($r['status'] === 'in_progress'): ?><a class="btn btn-outline-primary request-chat-link" data-request-chat-link href="<?=e(ui_context_url('chat/index.php', $requestChatContext))?>"><i class="bi bi-chat-dots"></i> فتح محادثاتي</a><?php endif; ?><div class="action-panel-buttons">
   <?php if ($r['status'] === 'receiving_offers') {?><a class="btn btn-outline-primary" href="<?=e(app_url('requests/edit.php?id='.$id))?>"><i class="bi bi-pencil"></i> تعديل الطلب</a><?php }?>
   <?php if ($r['status'] === 'in_progress') {?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="complete"><button class="btn btn-primary" data-confirm="تأكيد إكمال الطلب؟"><i class="bi bi-check-lg"></i> إكمال الطلب</button></form><?php }?>
   <?php if (in_array($r['status'], ['receiving_offers','in_progress'])) {?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger" data-confirm="تأكيد إلغاء الطلب؟"><i class="bi bi-x-lg"></i> إلغاء الطلب</button></form><?php }?>
  </div>
  <?php } elseif ($mine && me()['status'] === 'suspended') {?><div class="offer-sent-state"><span class="muted-icon"><i class="bi bi-lock"></i></span><strong>الحساب موقوف مؤقتًا</strong><p>يمكنك مراجعة طلبك وعروضه، لكن إجراءات التعديل متوقفة.</p></div>
  <?php } elseif ($r['status'] === 'receiving_offers' && !$already && me()['status'] === 'active') {?>
   <span class="section-kicker">هل تستطيع المساعدة؟</span><h2><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i> قدّم عرض مساعدة</h2><p class="action-panel-hint">رسالة قصيرة توضّح كيف يمكنك مساعدة صاحب الطلب.</p>
   <form method="post" class="offer-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="offer"><label class="form-label" for="offer-message">رسالة العرض</label><textarea id="offer-message" name="message" class="form-control" rows="4" maxlength="1000" data-count="1000" required placeholder="أخبره بما يمكنك تقديمه"></textarea><div class="field-meta"><small>عرض واحد لكل طلب.</small><small><span data-count-output>0</span>/1000</small></div><button class="btn btn-primary w-100 mt-3">إرسال العرض <i class="bi bi-arrow-left"></i></button></form>
  <?php } elseif ($already && !$mine) {?><div class="offer-sent-state"><span><i class="bi bi-check2"></i></span><strong>أرسلت عرضك بالفعل</strong><p>تابع حالة العرض من صفحة عروضي.</p><a href="<?=e(app_url('offers/my.php'))?>">الانتقال إلى عروضي <i class="bi bi-arrow-left"></i></a></div>
  <?php } elseif (!$mine && me()['status'] === 'suspended') {?><div class="offer-sent-state"><span class="muted-icon"><i class="bi bi-lock"></i></span><strong>الإجراءات متوقفة مؤقتًا</strong><p>حسابك موقوف مؤقتًا ويمكنك تصفح الطلبات فقط.</p></div>
  <?php } else {?><div class="offer-sent-state"><span class="muted-icon"><i class="bi bi-info-circle"></i></span><strong>الطلب لا يستقبل عروضًا الآن</strong><p>تغيرت حالة الطلب ولم يعد تقديم عرض جديد متاحًا.</p></div><?php }?>
 </aside>
</div>
<?php if ($mine) {?><section class="offers-section" aria-labelledby="offers-title"><div class="section-heading"><div><span class="section-kicker">الردود على احتياجك</span><h2 id="offers-title"><i class="bi bi-chat-square-heart" aria-hidden="true"></i> العروض المقدمة على هذا الطلب</h2><p class="section-description"><?=count($offers)?> عروض مقدمة. العرض رسالة مساعدة من طالب استجاب لطلبك.</p></div><span class="count-chip" aria-label="عدد العروض"><?=count($offers)?></span></div>
 <?php if ($offers) {?>
  <?php foreach ($offers as $offer) {if ($offer['status'] === 'submitted' && $r['status'] === 'receiving_offers' && me()['status'] === 'active') {?>
   <form id="reject-offer-<?= (int)$offer['id']?>" method="post" class="visually-hidden"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="offer_id" value="<?= (int)$offer['id']?>"></form>
  <?php }}?>
  <form method="post" class="offer-review-form" data-request-id="<?= (int)$id ?>"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="accept"><div class="offer-list">
   <?php foreach ($offers as $offer) {?><article class="offer-row">
    <?php if ($offer['status'] === 'submitted' && $r['status'] === 'receiving_offers' && me()['status'] === 'active') {?><label class="offer-select"><input class="form-check-input offer-accept-checkbox" type="checkbox" name="offers[]" value="<?= (int)$offer['id']?>" data-offer-name="<?=e($offer['full_name'])?>"><span>قبول مع عروض أخرى</span></label><?php } else {?><span class="offer-select-spacer"></span><?php }?>
    <span class="offer-avatar" aria-hidden="true"><?=e(mb_substr($offer['full_name'], 0, 1))?></span><div class="offer-content"><div class="offer-person-line"><strong><?=e($offer['full_name'])?></strong><span><?=e($offer['major'])?></span></div><p class="offer-card-message"><span>رسالة العرض</span><?=e($offer['message'])?></p><div class="offer-meta-line"><span class="ux-status-caption">حالة العرض</span><?php if ($offer['status'] === 'submitted'): ?><span class="status-badge status-<?=e(status_class('submitted'))?>">بانتظار مراجعتك</span><?php else: ?><?=status_badge($offer['status'])?><?php endif; ?><time>تاريخ العرض: <?=e(date('Y-m-d', strtotime($offer['created_at'])))?></time></div><?php if ($offer['status'] === 'accepted'): ?><div class="offer-next-step"><i class="bi bi-check-circle" aria-hidden="true"></i><span><strong>تم قبول العرض.</strong> أُنشئت محادثة؛ تجدها في «محادثاتي» بجانب اسم الطالب والطلب.</span><a href="<?=e(ui_context_url('chat/index.php', $requestChatContext))?>">محادثاتي</a></div><?php endif; ?></div>
    <?php if ($offer['status'] === 'submitted' && $r['status'] === 'receiving_offers' && me()['status'] === 'active') {?><div class="offer-card-actions"><button type="submit" name="offers[]" value="<?= (int)$offer['id']?>" class="btn btn-primary offer-accept-single" data-accept-offer data-offer-name="<?=e($offer['full_name'])?>"><i class="bi bi-check-lg"></i> قبول العرض</button><button type="submit" form="reject-offer-<?= (int)$offer['id']?>" class="offer-reject-button" data-confirm="هل تريد رفض عرض <?=e($offer['full_name'])?>؟">رفض العرض</button></div><?php }?>
   </article><?php }?>
  </div>
  <?php if ($r['status'] === 'receiving_offers' && me()['status'] === 'active') {?><div class="offer-review-footer"><p><i class="bi bi-info-circle"></i> اختر عرضًا أو أكثر. قبول العرض ينشئ محادثة مع صاحبه.</p><button class="btn btn-outline-primary" type="submit" data-accept-selected><i class="bi bi-check2"></i> قبول العروض المحددة</button></div><?php }?>
  </form>
 <?php } else {?><div class="results-empty offers-empty"><span class="results-empty-icon"><i class="bi bi-inbox"></i></span><h2>لم تصل عروض بعد</h2><p>ستظهر عروض الطلاب هنا عند تقديمها.</p></div><?php }?></section><?php }?>
<?php footer_ui();?>
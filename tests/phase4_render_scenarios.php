<?php
/** Render real template fragments with fixtures and stubs, not HTTP or MySQL integration. */
$root = __DIR__ . '/../Ehtiya/htdocs/';
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function status_badge(string $s): string { return '<b>' . e($s) . '</b>'; }
function app_url(string $p): string { return '/' . ltrim($p, '/'); }
function ui_context_url(string $p, array $params): string { return app_url($p) . '?' . http_build_query($params); }
function pagination_ui($p, $label): string { return ''; }
function footer_ui(): void {}
function csrf(): string { return 'test-token'; }
function me(): array { return ['id' => 7, 'status' => 'active', 'role' => 'student']; }
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); echo 'PASS: ' . $label . "\n"; }
function render(string $source, string $start, string $end, array $vars): string {
    $a = strpos($source, $start); $b = $a === false ? false : strpos($source, $end, $a);
    if ($a === false || $b === false) throw new RuntimeException('Template marker not found: ' . $start);
    extract($vars); ob_start(); eval('?>' . substr($source, $a, $b - $a)); return ob_get_clean();
}
$pagination = ['page'=>1, 'per_page'=>20, 'total'=>1];
$returnContextParams = [];
$request = ['id'=>3,'title'=>'<خاص>', 'type_name'=>'نوع','status'=>'receiving_offers','moderation_status'=>'published','created_at'=>'2026-01-01','description'=>'وصف'];
$src = file_get_contents($root . 'requests/my.php');
$vars = compact('pagination','returnContextParams');
$html = render($src, '<?php if ($rows): ?>', '<?= pagination_ui(', $vars + ['rows'=>[$request]]);
check(str_contains($html, 'requests/view.php?id=3') && str_contains($html, 'بانتظار عروض الطلاب'), 'published receiving request links to details');
$request['moderation_status']='removed';
$html = render($src, '<?php if ($rows): ?>', '<?= pagination_ui(', $vars + ['rows'=>[$request]]);
check(!str_contains($html, 'requests/view.php') && str_contains($html, 'غير متاح للعرض') && !str_contains($html, 'بانتظار عروض الطلاب'), 'removed request has no broken detail link or waiting claim');
check(str_contains($html, '&lt;خاص&gt;') && !str_contains($html, '<خاص>'), 'request title escaped');
$src = file_get_contents($root . 'offers/my.php');
$offer = ['id'=>11,'rid'=>3,'title'=>'طلب','message'=>'عرض','created_at'=>'2026-01-01','status'=>'submitted','request_status'=>'completed','moderation_status'=>'published'];
$html = render($src, '<?php if ($rows): ?>', '<?= pagination_ui(', $vars + ['rows'=>[$offer]]);
check(str_contains($html, 'العرض مسجل، لكن الطلب لا يستقبل العروض الآن') && !str_contains($html, 'بانتظار رد صاحب الطلب'), 'pending offer on completed request not described as awaiting response');
$offer['request_status']='receiving_offers'; $offer['moderation_status']='removed';
$html = render($src, '<?php if ($rows): ?>', '<?= pagination_ui(', $vars + ['rows'=>[$offer]]);
check(!str_contains($html, 'requests/view.php') && str_contains($html, 'تفاصيل الطلب غير متاحة'), 'removed offer request has no detail link');
$offer['moderation_status']='published'; $offer['status']='rejected';
$html = render($src, '<?php if ($rows): ?>', '<?= pagination_ui(', $vars + ['rows'=>[$offer]]);
check(str_contains($html, 'لم يقبل صاحب الطلب هذا العرض') && !str_contains($html, 'عرضًا آخر'), 'rejection does not assert another offer was chosen');
$src = file_get_contents($root . 'notifications/index.php');
$a = strpos($src, '$notificationIcons ='); $b = strpos($src, "header_ui('الإشعارات');", $a);
eval(substr($src, $a, $b - $a));
$notificationContext = ['return_to'=>'notifications'];
$notification = ['link'=>'/offers/my.php?request_id=3','type'=>'new_offer','title'=>'<تنبيه>','message'=>'نص','is_read'=>0,'id'=>12,'created_at'=>'2026-01-01'];
$html = render($src, '<?php if ($loadError): ?>', '<?php if (!$loadError): ?>', compact('notificationIcons','notificationActionLabels','notificationContext','pagination') + ['loadError'=>false, 'notifications'=>[$notification]]);
check(str_contains($html, '/requests/view.php?id=3') && str_contains($html, 'مراجعة العروض'), 'new offer notification resolves to request');
check(str_contains($html, '&lt;تنبيه&gt;') && !str_contains($html, '<تنبيه>'), 'notification title escaped');
$src = file_get_contents($root . 'chat/view.php');
$start = "<?php if (\$accepted && ((\$o['email_visibility']"; $end = '</section><p class="text-secondary">';
$private = ['email_visibility'=>'private','phone_visibility'=>'private','email'=>'private@example.test','phone'=>'555-secret'];
$html = render($src, $start, $end, ['accepted'=>true,'o'=>$private]);
check(!str_contains($html, 'private@example.test') && !str_contains($html, '555-secret'), 'private contact information not rendered');
$private['email_visibility']='accepted_only';
$html = render($src, $start, $end, ['accepted'=>false,'o'=>$private]);
check(!str_contains($html, 'private@example.test'), 'accepted-only contact not rendered without accepted conversation');
$html = render($src, $start, $end, ['accepted'=>true,'o'=>$private]);
check(str_contains($html, 'private@example.test') && !str_contains($html, '555-secret'), 'only opted-in contact rendered for accepted conversation');
$source = file_get_contents($root . 'chat/view.php');
check(str_contains($source, "WHERE c.id=? AND (c.owner_id=? OR c.helper_id=?)") && str_contains($source, "need_role('student');"), 'conversation participant and role guards remain present (static)');
check(str_contains($source, "f.status='accepted'") && str_contains($source, "email_visibility'] === 'accepted_only'"), 'contact access checks remain present (static)');
check(str_contains($source, "\$pdo->commit();\n            flash('تم إرسال الرسالة.');"), 'send success feedback follows commit (static)');
check(str_contains(file_get_contents($root . 'requests/view.php'), "r.moderation_status='published'") && str_contains(file_get_contents($root . 'notifications/index.php'), 'WHERE user_id=?'), 'request visibility and notification ownership retained (static)');
echo "PASS: phase 4 template scenarios (mocked)\n";

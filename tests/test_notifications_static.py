"""Source assertions only; does not run PHP, HTTP, MySQL, or browser rendering."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1] / 'Ehtiya/htdocs'
INBOX = (ROOT / 'notifications/index.php').read_text(encoding='utf-8')
BOOT = (ROOT / 'includes/bootstrap.php').read_text(encoding='utf-8')
CHAT = (ROOT / 'chat/view.php').read_text(encoding='utf-8')
SCRIPT = (ROOT / 'assets/app.js').read_text(encoding='utf-8')


class NotificationStaticTests(unittest.TestCase):
    def test_reads_and_updates_are_scoped_and_csrf_protected(self):
        self.assertIn('need_login();', INBOX)
        post = INBOX.split("if ($_SERVER['REQUEST_METHOD'] === 'POST')", 1)[1].split('$notifications = [];', 1)[0]
        self.assertLess(post.index('post_only();'), post.index('UPDATE notifications'))
        self.assertIn('WHERE id = ? AND user_id = ? AND is_read = 0', post)
        self.assertIn("[input_int('id'), me()['id']]", post)
        self.assertIn('SELECT COUNT(*) FROM notifications WHERE user_id=?', INBOX)
        self.assertIn('SELECT * FROM notifications WHERE user_id=?', INBOX)
        self.assertIn('$count->execute([me()[\'id\']])', INBOX)
        self.assertIn("$statement->bindValue(1, (int)me()['id'], PDO::PARAM_INT)", INBOX)
        self.assertIn('name="csrf" value="<?= e(csrf()) ?>"', INBOX)
        self.assertIn('check_csrf();', BOOT.split('function post_only', 1)[1])

    def test_failure_not_empty_or_zero(self):
        self.assertIn('$loadError = true;', INBOX)
        self.assertIn('if ($loadError):', INBOX)
        self.assertIn('elseif ($notifications):', INBOX)
        self.assertIn('if (!$loadError):', INBOX)
        self.assertIn('تعذر تحميل الإشعارات', INBOX)
        self.assertIn('تعذر تحديث حالة الإشعار', INBOX)
        self.assertIn('$unreadCount = null;', BOOT)
        self.assertIn('تعذر تحميل عدد غير المقروء', BOOT)
        self.assertIn("$unreadCount > 99 ? '99+'", BOOT)

    def test_navigation_and_page_state(self):
        self.assertIn("'page' => $page, 'per_page' => $perPage", INBOX)
        self.assertIn("'return_to' => 'notifications'", INBOX)
        self.assertIn("ui_context_url('chat/view.php'", INBOX)
        self.assertIn("ui_context_url('requests/view.php'", INBOX)
        self.assertIn("'offer_rejected' => 'عرض عروضي'", INBOX)
        self.assertIn('فتح الرابط لا يغيّر حالة قراءة الإشعار', INBOX)
        self.assertIn("'notifications' => ['path' => 'notifications/index.php'", BOOT)

    def test_chat_receipt_post_scoped_and_retry(self):
        self.assertIn("WHERE c.id=? AND (c.owner_id=? OR c.helper_id=?)", CHAT)
        self.assertIn("if ($_SERVER['REQUEST_METHOD'] === 'POST')", CHAT)
        self.assertIn('post_only();', CHAT)
        self.assertIn('UPDATE messages SET is_read=1 WHERE conversation_id=? AND sender_id<>?', CHAT)
        self.assertIn('data-read-receipt-retry hidden', CHAT)
        self.assertIn("method: 'POST'", SCRIPT.split('// Mark displayed conversation as read', 1)[1])
        self.assertIn('response.status !== 204', SCRIPT)

    def test_no_inbox_creation_or_get_update(self):
        self.assertNotIn('INSERT INTO notifications', INBOX)
        self.assertEqual(INBOX.count('UPDATE notifications SET is_read'), 1)
        self.assertIn('ORDER BY is_read ASC,created_at DESC,id DESC', INBOX)
        self.assertIn('INSERT INTO notifications(user_id, type, title, message, link)', BOOT)
        self.assertIn('data-confirm', SCRIPT)


if __name__ == '__main__':
    unittest.main()

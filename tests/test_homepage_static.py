"""Static checks only; these do not execute PHP or test a database/browser."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / 'Ehtiya' / 'htdocs'
HOME = (ROOT / 'index.php').read_text(encoding='utf-8')
LISTING = (ROOT / 'requests' / 'index.php').read_text(encoding='utf-8')


class HomepageStaticTests(unittest.TestCase):
    def test_latest_query_matches_student_listing_scope(self):
        self.assertIn("if ($viewer && $viewer['role'] === 'student')", HOME)
        self.assertIn("need_role('student');", HOME)
        for source in (HOME, LISTING):
            for clause in ("r.moderation_status = 'published'", "r.status = 'receiving_offers'", 'r.owner_id <> ?', 'JOIN users u ON u.id = r.owner_id', 'JOIN assistance_types t ON t.id = r.assistance_type_id'):
                self.assertIn(clause, source)
        self.assertIn('$latestQuery->execute([(int)$viewer[\'id\']]);', HOME)
        self.assertRegex(HOME, r'ORDER BY r\.created_at DESC,\s*r\.id DESC LIMIT 3')

    def test_preview_only_selects_needed_fields_and_escapes_them(self):
        self.assertIn('SELECT r.id, r.title, r.major, r.created_at, t.name AS type_name', HOME)
        for field in ('title', 'major', 'type_name'):
            self.assertIn("e($request['" + field + "'])", HOME)
        for field in ('full_name', 'email', 'phone', 'description', 'images'):
            self.assertNotIn("$request['" + field + "']", HOME)

    def test_role_destinations_and_paths_exist(self):
        for path in ('requests/create.php', 'requests/index.php', 'requests/view.php', 'auth/register.php', 'auth/login.php', 'admin/index.php', 'admin/requests.php'):
            self.assertIn("'" + path + "'", HOME)
            self.assertTrue((ROOT / path).is_file(), path)
        self.assertIn("$viewer['role'] === 'admin'", HOME)
        self.assertIn('home_url($viewer)', HOME)

    def test_examples_steps_and_states(self):
        self.assertIn('أمثلة توضيحية', HOME)
        self.assertIn('وليست طلبات منشورة', HOME)
        for item in ('مشروع تخرج', 'برمجة', 'واجهة', 'الرياضيات', 'بحث أكاديمي', 'أنشئ احتياجك', 'استقبل المساعدة', 'أنجز احتياجك', 'بعد قبول العرض'):
            self.assertIn(item, HOME)
        self.assertIn('elseif (!$latestRequests)', HOME)
        self.assertIn('if ($latestError)', HOME)
        self.assertIn('catch (Throwable $exception)', HOME)
        self.assertNotRegex(HOME, r'(?i)\b(INSERT|UPDATE|DELETE)\s+(?:INTO\s+)?requests\b')

    def test_responsive_markup_and_accessible_structure(self):
        for marker in ('col-12 col-md-6 col-lg-4', 'text-break', 'aria-labelledby="latest-title"', 'aria-labelledby="how-title"', 'role="alert"'):
            self.assertIn(marker, HOME)
        self.assertEqual(len(re.findall(r'<h1\b', HOME)), 1)
        self.assertEqual(HOME.count('class="how-steps"'), 1)


if __name__ == '__main__':
    unittest.main()

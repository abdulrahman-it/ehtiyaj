"""Source checks only; they do not execute PHP or MySQL."""
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'Ehtiya/htdocs/admin/index.php').read_text()


class AdminStatisticsStaticTests(unittest.TestCase):
    def test_student_statuses_and_no_admins(self):
        for term in ("FROM users WHERE role = 'student'", "status = 'active'", "status = 'suspended'", "status = 'deactivated'"):
            self.assertIn(term, SOURCE)
        self.assertIn("'طلاب نشطون'", SOURCE)
        self.assertIn("'طلاب موقوفون مؤقتًا'", SOURCE)
        self.assertIn("'طلاب معطّلون ذاتيًا'", SOURCE)
        self.assertIn('user_status=', SOURCE)

    def test_requests_are_disjoint_and_publication_scope_is_explicit(self):
        for term in ("status IN ('receiving_offers', 'in_progress') AND moderation_status = 'published'", "status = 'completed'", "status = 'cancelled'", 'كل حالات النشر'):
            self.assertIn(term, SOURCE)

    def test_metrics_no_joins_and_five_groups(self):
        block = SOURCE[SOURCE.index('$metricGroups ='):SOURCE.index('$counts =')]
        for group in ('students', 'requests', 'offers', 'reports', 'conversations'):
            self.assertIn("'" + group + "' =>", block)
        self.assertEqual(block.count("'sql' =>"), 5)
        self.assertNotIn(' JOIN ', block.upper())
        self.assertIn("WHERE status = 'open'", block)

    def test_failure_does_not_render_zero_or_clear(self):
        self.assertIn("array_fill_keys($definition['keys'], null)", SOURCE)
        self.assertIn('catch (Throwable $exception)', SOURCE)
        self.assertIn("$value === null ? 'غير متاح' : $value", SOURCE)
        self.assertIn("$reportCount === null ? 'عدد البلاغات المفتوحة غير متاح'", SOURCE)
        self.assertIn("$reportCount === null ? ''", SOURCE)
        self.assertIn('role="alert"', SOURCE)

    def test_dashboard_admin_read_only_and_navigation_kept(self):
        self.assertIn("need_role('admin');", SOURCE)
        self.assertNotIn('$_POST', SOURCE)
        self.assertNotIn('method="post"', SOURCE)
        self.assertIn('جميع الطلبات', SOURCE)
        self.assertIn('جميع البلاغات', SOURCE)


if __name__ == '__main__':
    unittest.main()

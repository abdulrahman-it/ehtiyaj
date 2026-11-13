"""Static checks for the four filter-review fixes; not PHP, HTTP or SQL execution."""
import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1] / 'Ehtiya' / 'htdocs'


class FilterReviewStaticTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.requests = (ROOT / 'requests/index.php').read_text()
        cls.users = (ROOT / 'admin/users.php').read_text()

    def test_inactive_major_has_explicit_recovery_and_unknown_is_rejected(self):
        s = self.requests
        guard = s[s.index("if ($requestedMajor !== ''"):s.index('$major = $requestedMajor;')]
        self.assertIn("!$catalogMajor['active'] && $catalogMajor['name'] === $requestedMajor", guard)
        self.assertIn('لم يعد نشطًا', guard)
        self.assertIn("['q' => $term, 'per_page' => $recoveryPage['per_page']]", guard)
        self.assertIn('footer_ui();\n            exit;', guard)
        self.assertIn("exit('قيمة التخصص غير صالحة.')", guard)
        self.assertNotIn("'page' =>", guard)

    def test_search_guard_precedes_post_and_role_remains(self):
        s = self.users
        self.assertLess(s.index("need_role('admin');"), s.index("if (array_key_exists('q', $_GET)"))
        self.assertLess(s.index("if (array_key_exists('q', $_GET)"), s.index("if ($_SERVER['REQUEST_METHOD'] === 'POST')"))
        self.assertIn('post_only();', s)
        self.assertIn("'q' => input_string('q', 'get')", s)

    def test_clear_all_retains_size_only(self):
        line = next(line for line in self.requests.splitlines() if 'class="search-clear"' in line)
        self.assertIn("['per_page' => $pagination['per_page']]", line)
        self.assertNotIn("'page' =>", line)
        self.assertNotIn("'q' =>", line)
        self.assertNotIn("'major' =>", line)

    def test_count_and_result_use_same_joins_and_filters(self):
        s = self.requests
        result = s[s.index('$sql = "SELECT'):s.index('$parameters =')]
        count = s[s.index("$countSql = 'SELECT"):s.index('$countQuery =')]
        def joins(text):
            section = text[text.index('FROM requests r'):text.index('WHERE')]
            return re.sub(r'\s+', '', section)
        self.assertEqual(joins(result), joins(count))
        self.assertIn('JOINusersuONu.id=r.owner_id', joins(count))
        self.assertIn('JOINassistance_typestONt.id=r.assistance_type_id', joins(count))
        for filter_name in ('r.moderation_status', 'r.status', 'r.owner_id', 'r.major', 'r.title', 'r.description', 'r.subject'):
            self.assertIn(filter_name, s[s.index('$sql = "SELECT'):s.index('// Mirror the result filters')])
            self.assertIn(filter_name, count)
        self.assertIn('$pdo->prepare($countSql)', s)
        self.assertIn('$pdo->prepare($sql)', s)


if __name__ == '__main__':
    unittest.main()

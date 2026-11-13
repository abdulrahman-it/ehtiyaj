"""Source-only checks, not PHP execution, MySQL integration, HTTP or visual testing."""
from pathlib import Path
import unittest

WEB = Path(__file__).resolve().parents[1] / 'Ehtiya/htdocs'
LISTING = (WEB / 'requests/index.php').read_text(encoding='utf-8')
BOOTSTRAP = (WEB / 'includes/bootstrap.php').read_text(encoding='utf-8')
HOME = (WEB / 'index.php').read_text(encoding='utf-8')
CSS = (WEB / 'assets/style.css').read_text(encoding='utf-8')


class DiscoveryStaticTests(unittest.TestCase):
    def test_scope_and_parameterized_filters(self):
        self.assertIn("need_role('student')", LISTING)
        for clause in ("r.moderation_status = 'published'", "r.status = 'receiving_offers'", 'r.owner_id <> ?', "ORDER BY r.created_at DESC,r.id DESC LIMIT ? OFFSET ?"):
            self.assertIn(clause, LISTING)
        for clause in ("r.assistance_type_id = ?", "r.assistance_type_id=?", '$parameters[] = $typeId', '$countParams[] = $typeId'):
            self.assertIn(clause, LISTING)
        self.assertIn("(r.title LIKE ? OR r.description LIKE ? OR r.subject LIKE ?)", LISTING)
        self.assertIn("(string)$type['id'] === $requestedType", LISTING)
        self.assertIn("!is_string($_GET['type'])", LISTING)
        self.assertNotIn('JOIN offers', LISTING)

    def test_types_include_inactive_and_escaped_display(self):
        self.assertIn('SELECT id, name, is_active FROM assistance_types ORDER BY name, id', LISTING)
        self.assertIn('غير متاح للطلبات الجديدة', LISTING)
        for value in ("e($type['name'])", "e($type['id'])", 'e($typeName)', "e($request['title'])", "e($request['major'])"):
            self.assertIn(value, LISTING)

    def test_pagination_and_return_context(self):
        self.assertIn("'back_type' => $typeId ?? ''", LISTING)
        self.assertIn("$query = ['q' => $q, 'major' => $major, 'type' => $type, 'page' => $page, 'per_page' => $perPage]", BOOTSTRAP)
        self.assertIn("$forward['back_type'] = $type", BOOTSTRAP)
        self.assertIn("foreach ($_GET as $key => $value)", BOOTSTRAP)
        self.assertIn("['q' => $term, 'type' => $typeId ?? '', 'per_page' => $pagination['per_page']]", LISTING)
        self.assertIn("['q' => $term, 'major' => $major, 'per_page' => $pagination['per_page']]", LISTING)
        self.assertIn("$recoveryParams['type'] = $typeId", LISTING)
        form = LISTING.split('<form class="search-panel discovery-search"', 1)[1].split('</form>', 1)[0]
        self.assertNotIn('name="page"', form)
        self.assertIn('name="per_page"', form)

    def test_empty_states_and_local_suggestions(self):
        for text in ('لم نعثر على طلبات مطابقة', 'مسح البحث والفلاتر', 'لا توجد طلبات متاحة لك الآن', 'النتائج الظاهرة في هذه الصفحة فقط'):
            self.assertIn(text, LISTING)
        self.assertIn('count($suggestedTitles) >= 10', LISTING)
        self.assertIn('<option value="<?= e($title) ?>">', LISTING)
        self.assertNotIn('fetch(', LISTING)
        self.assertIn('grid-template-columns: minmax(0, 1fr)', CSS)

    def test_latest_uses_real_scoped_data(self):
        self.assertIn('r.created_at', HOME)
        self.assertIn("status_badge('receiving_offers')", HOME)
        self.assertIn("WHERE r.moderation_status = 'published'", HOME)
        self.assertIn('r.owner_id <> ?', HOME)
        self.assertNotIn('JOIN offers', HOME)


if __name__ == '__main__':
    unittest.main()

"""SEO source checks only: no HTTP, PHP execution, crawler or indexing assertions."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WEB = ROOT / 'Ehtiya/htdocs'
BOOT = (WEB / 'includes/bootstrap.php').read_text(encoding='utf-8')
EXPECTED = {'/index.php', '/about.php', '/privacy.php', '/terms.php', '/faq.php', '/contact.php', '/auth/login.php', '/auth/register.php'}


class SeoStaticTests(unittest.TestCase):
    def test_allowlist_unique_metadata_and_real_files(self):
        section = BOOT.split('function seo_public_pages(): array', 1)[1].split('/** Determine the entry script', 1)[0]
        rows = re.findall(r"'(/[^']+\.php)'\s*=>\s*\['([^']+)',\s*'([^']+)'\]", section)
        self.assertEqual({r[0] for r in rows}, EXPECTED)
        self.assertEqual(len(rows), len(EXPECTED))
        self.assertEqual(len({r[1] for r in rows}), len(rows))
        self.assertEqual(len({r[2] for r in rows}), len(rows))
        for route, title, description in rows:
            self.assertTrue((WEB / route.lstrip('/')).is_file())
            self.assertGreater(len(title), 5)
            self.assertGreater(len(description), 30)

    def test_noindex_default_and_real_access_guards_remain(self):
        self.assertIn("$seoPage = seo_public_pages()[seo_entry_path()] ?? null;", BOOT)
        self.assertIn("header('X-Robots-Tag: noindex, nofollow')", BOOT)
        self.assertIn('<meta name="robots" content="noindex, nofollow">', BOOT)
        self.assertIn("$_SERVER['SCRIPT_NAME']", BOOT)
        self.assertIn("str_starts_with($script, BASE_URL . '/')", BOOT)
        for path, role in [('requests/index.php', 'student'), ('requests/view.php', 'student'), ('chat/view.php', 'student'), ('student/profile.php', 'student'), ('admin/index.php', 'admin')]:
            self.assertIn("need_role('" + role + "')", (WEB / path).read_text(encoding='utf-8'))

    def test_metadata_escaped_and_no_unverified_addresses(self):
        header = BOOT.split('function header_ui(', 1)[1].split('function footer_ui(', 1)[0]
        self.assertIn('e($seoPage[0])', header)
        self.assertIn('e($seoPage[1])', header)
        self.assertIn('name="description"', header)
        self.assertIn('property="og:title"', header)
        self.assertIn('property="og:description"', header)
        self.assertIn('<html lang="ar" dir="rtl">', header)
        self.assertNotIn('rel="canonical"', header)
        self.assertNotIn('property="og:url"', header)
        self.assertNotIn('property="og:image"', header)
        seo = BOOT.split('function seo_public_pages(): array', 1)[1].split('// Conservative policy', 1)[0]
        self.assertNotIn('HTTP_HOST', seo)
        self.assertNotIn('SERVER_NAME', seo)
        self.assertNotIn('https://', seo)

    def test_robots_does_not_block_php_from_noindex(self):
        robots = (WEB / 'robots.txt').read_text(encoding='utf-8')
        self.assertIn('User-agent: *', robots)
        for path in ('/config/', '/includes/', '/uploads/'):
            self.assertIn('Disallow: ' + path, robots)
        for path in ('/requests/', '/chat/', '/admin/', '/student/'):
            self.assertNotIn('Disallow: ' + path, robots)
        self.assertNotIn('Sitemap:', robots)
        self.assertFalse((WEB / 'sitemap.xml').exists())
        self.assertIn('robots.txt', (ROOT / '.github/deploy-files.txt').read_text().splitlines())

    def test_public_pages_have_single_h1(self):
        for route in EXPECTED:
            source = (WEB / route.lstrip('/')).read_text(encoding='utf-8')
            self.assertEqual(len(re.findall(r'<h1\b', source)), 1, route)


if __name__ == '__main__':
    unittest.main()

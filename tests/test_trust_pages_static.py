"""Source-level trust-page checks; not a PHP, browser, privacy or legal audit."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WEB = ROOT / 'Ehtiya/htdocs'
PAGES = ('about.php', 'privacy.php', 'terms.php', 'faq.php', 'contact.php')


class TrustPagesStaticTests(unittest.TestCase):
    def test_public_pages_use_shared_layout_without_role_guards(self):
        for path in PAGES:
            source = (WEB / path).read_text(encoding='utf-8')
            with self.subTest(path=path):
                self.assertIn('includes/bootstrap.php', source)
                self.assertIn('header_ui(', source)
                self.assertIn('footer_ui()', source)
                self.assertNotRegex(source, r'\bneed_(role|login)\s*\(')
                self.assertEqual(len(re.findall(r'<h1\b', source)), 1)
                self.assertNotRegex(source, r'<form\b')

    def test_local_links_exist_and_are_in_disabled_deploy_manifest(self):
        manifest = (ROOT / '.github/deploy-files.txt').read_text().splitlines()
        for path in PAGES:
            self.assertIn(path, manifest)
        for path in (*PAGES, 'includes/bootstrap.php'):
            source = (WEB / path).read_text(encoding='utf-8')
            for target in re.findall(r"app_url\('([^'?]+\.php)'\)", source):
                self.assertTrue((WEB / target).is_file(), (path, target))

    def test_both_footer_variants_link_to_trust_pages_once(self):
        source = (WEB / 'includes/bootstrap.php').read_text(encoding='utf-8')
        footer = source.split('function footer_ui(', 1)[1]
        compact, full = footer.split('<?php else: ?>', 1)
        for path in PAGES:
            with self.subTest(path=path):
                self.assertEqual(compact.count("app_url('" + path + "')"), 1)
                self.assertEqual(full.count("app_url('" + path + "')"), 1)

    def test_privacy_discloses_scope_and_uncertainty(self):
        source = (WEB / 'privacy.php').read_text(encoding='utf-8')
        for phrase in ('عنوان IP', 'jsDelivr', 'Google Fonts', 'مرتبطة ببلاغ', 'لا يحذف', 'مدة احتفاظ عامة', 'ليست ضمانًا للأمان', 'لا تظهر وسيلة تواصل رسمية'):
            self.assertIn(phrase, source)
        self.assertNotRegex(source, r'(?i)mailto:|tel:')

    def test_report_and_deactivation_are_not_misrepresented(self):
        faq = (WEB / 'faq.php').read_text(encoding='utf-8')
        terms = (WEB / 'terms.php').read_text(encoding='utf-8')
        contact = (WEB / 'contact.php').read_text(encoding='utf-8')
        self.assertIn('الإبلاغ عن مشكلة', faq)
        self.assertIn('لا توجد حاليًا وسيلة رسمية مؤكدة', faq)
        self.assertIn('لا يؤدي إغلاق البلاغ وحده', terms)
        self.assertIn('ليست استشارة قانونية', terms)
        self.assertIn('ليست نموذج تواصل عام', contact)
        self.assertNotRegex(contact, r'(?i)mailto:|tel:|<form\b')


if __name__ == '__main__':
    unittest.main()

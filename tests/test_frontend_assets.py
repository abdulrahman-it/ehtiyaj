"""Static checks for the application's shared font loading contract.

Unknown PHP/HTML files require review unless they call the shared header. This
is deliberately conservative: a new endpoint or helper must be classified.
"""
import re
import unittest
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

ROOT = Path(__file__).resolve().parents[1] / 'Ehtiya' / 'htdocs'
FONT_URL = 'https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap'
WEIGHTS = {'400', '500', '600', '700', '800'}

# Reviewed non-page files: shared helpers/configuration, image endpoints and logout.
NON_PAGE_FILES = {
    'includes/bootstrap.php', 'includes/login_throttle.php', 'includes/majors.php',
    'includes/upload.php', 'admin/includes/navigation.php', 'config/config.php',
    'auth/logout.php', 'chat/image.php', 'requests/image.php',
}
# This entry point checks the admin role then includes the shared inbox page.
DELEGATED_PAGE = 'admin/notifications.php'
INBOX_PAGE = 'notifications/index.php'


def strip_comments(text):
    """Remove CSS/PHP comments without treating comment markers in strings as comments."""
    pattern = r'''("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')|/\*.*?\*/|//[^\n]*|\#[^\n]*'''
    return re.sub(pattern, lambda m: m.group(1) or '', text, flags=re.S)


def header_errors(bootstrap):
    try:
        header = bootstrap.split('function header_ui(', 1)[1].split('function footer_ui(', 1)[0]
    except IndexError:
        return ['shared header or footer definition is missing']
    header = re.sub(r'<!--.*?-->', '', header, flags=re.S)
    link = f'<link href="{FONT_URL}" rel="stylesheet">'
    errors = []
    if header.count(link) != 1:
        errors.append('expected exactly one shared Cairo link with the configured weights')
    elif 'assets/style.css' not in header or header.index(link) > header.index('assets/style.css'):
        errors.append('Cairo must load before style.css')
    if link in header and ('if ($authLayout):' not in header or
                           header.index(link) > header.index('if ($authLayout):')):
        errors.append('Cairo must load before the auth layout branch')
    # Independently check the requested weights, not the font's availability.
    family = parse_qs(urlsplit(FONT_URL).query).get('family', [''])[0]
    if not family.startswith('Cairo:wght@') or set(family.split('@', 1)[-1].split(';')) != WEIGHTS:
        errors.append('unexpected Cairo weights')
    return errors


def cairo_imports(css):
    # CSS comments may appear between the import keyword and its URL.
    css = re.sub(r'/\*.*?\*/', ' ', css, flags=re.S)
    imports = re.compile(r'''@import\s+(?:url\(\s*(?:"(?P<dq>[^"]*)"|'(?P<sq>[^']*)'|(?P<bare>[^\s)]*))\s*\)|"(?P<direct_dq>[^"]*)"|'(?P<direct_sq>[^']*)')''', re.I)
    result = []
    for match in imports.finditer(css):
        url = next(value for value in match.groupdict().values() if value is not None)
        parts = urlsplit(url)
        if parts.hostname and parts.hostname.lower() == 'fonts.googleapis.com':
            families = parse_qs(parts.query).get('family', [])
            if any(item.split(':', 1)[0].strip().lower() == 'cairo'
                   for family in families for item in family.split('|')):
                result.append(url)
    return result


def page_errors(files):
    errors = []
    for path, source in sorted(files.items()):
        if path in NON_PAGE_FILES:
            continue
        if path == DELEGATED_PAGE:
            # Check the existing literal delegation rather than assuming it works.
            if not re.search(r'''require\s+__DIR__\s*\.\s*['"]/\.\./notifications/index\.php['"]\s*;''', strip_comments(source)):
                errors.append(f'{path}: shared inbox delegation missing; review required')
            if INBOX_PAGE not in files:
                errors.append(f'{path}: delegated inbox missing; review required')
            continue
        # Ignore comments and string literals so a mention of header_ui is not a call.
        code = re.sub(r'''"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*' ''', '', strip_comments(source), flags=re.X)
        if not path.lower().endswith('.php') or not re.search(r'\bheader_ui\s*\(', code):
            errors.append(f'{path}: no shared header call; review required')
    return errors


class FontLoadingTests(unittest.TestCase):
    def test_shared_header_loads_cairo_before_application_styles(self):
        bootstrap = (ROOT / 'includes' / 'bootstrap.php').read_text(encoding='utf-8')
        self.assertEqual(header_errors(bootstrap), [])

    def test_stylesheets_do_not_reimport_cairo(self):
        for path in ROOT.rglob('*.css'):
            with self.subTest(stylesheet=str(path.relative_to(ROOT))):
                self.assertEqual(cairo_imports(path.read_text(encoding='utf-8')), [])

    def test_page_entries_use_shared_header(self):
        files = {str(path.relative_to(ROOT)): path.read_text(encoding='utf-8')
                 for path in ROOT.rglob('*')
                 if path.is_file() and path.suffix.lower() in {'.php', '.html', '.htm'}}
        self.assertEqual(page_errors(files), [])

    def test_negative_duplicate_import_variants(self):
        variants = [
            '@import url("https://fonts.googleapis.com/css2?family=Cairo:wght@400;500");',
            "@import url( '//fonts.googleapis.com/css2?family=Roboto&family=Cairo:wght@700' );",
            '@IMPORT  URL( https://fonts.googleapis.com/css?family=Cairo%3A700 ) screen;',
            "@import 'https://fonts.googleapis.com/css?family=Cairo:700';",
            "@import 'https://fonts.googleapis.com/css?family=Roboto%7CCairo:700';",
            '@import/**/url("https://fonts.googleapis.com/css2?family=Cairo:wght@400");',
        ]
        for css in variants:
            with self.subTest(css=css):
                self.assertTrue(cairo_imports(css), 'duplicate import must be rejected')
        self.assertEqual(cairo_imports('/* @import url("https://fonts.googleapis.com/css2?family=Cairo"); */'), [])
        self.assertEqual(cairo_imports('@import url("https://fonts.googleapis.com/css2?family=Roboto");'), [])

    def test_negative_missing_or_late_font(self):
        bootstrap = (ROOT / 'includes' / 'bootstrap.php').read_text(encoding='utf-8')
        link = f'<link href="{FONT_URL}" rel="stylesheet">'
        self.assertTrue(header_errors(bootstrap.replace(link, '', 1)))
        self.assertTrue(header_errors(bootstrap.replace(link, '<!-- ' + link + ' -->', 1)))
        style_line = next(line for line in bootstrap.splitlines() if 'assets/style.css' in line)
        late = bootstrap.replace(link, '', 1).replace(style_line, style_line + '\n        ' + link, 1)
        self.assertTrue(header_errors(late))

    def test_negative_new_page_without_shared_header(self):
        self.assertTrue(page_errors({'new/page.php': '<?php echo "hello"; ?>'}))
        self.assertTrue(page_errors({'new/page.php': '<?php // header_ui();\necho "header_ui()"; ?>'}))
        self.assertTrue(page_errors({'new/page.html': '<html>Standalone</html>'}))
        self.assertEqual(page_errors({'new/page.php': '<?php header_ui("Title"); ?>'}), [])


if __name__ == '__main__':
    unittest.main()

"""Offline tests; these never connect to FTP."""
import ftplib
import importlib.util
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('ftp_upload', ROOT / '.github/scripts/ftp_upload.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class FakeFTP:
    def __init__(self):
        self.cwd_path = ''
        self.dirs = {'/htdocs/'}
        self.calls = []

    def cwd(self, path):
        next_path = path if path.startswith('/') else self.cwd_path.rstrip('/') + '/' + path
        self.calls.append(('cwd', next_path))
        if next_path not in self.dirs:
            raise ftplib.error_perm('not found')
        self.cwd_path = next_path

    def mkd(self, name):
        path = self.cwd_path.rstrip('/') + '/' + name
        self.calls.append(('mkd', path))
        self.dirs.add(path)

    def storbinary(self, command, data):
        self.calls.append(('stor', self.cwd_path, command, data.read()))


class DeploymentTests(unittest.TestCase):
    def test_manifest_and_upload_only(self):
        files = module.read_manifest(ROOT / 'Ehtiya/htdocs', ROOT / '.github/deploy-files.txt')
        names = {str(path) for path, _ in files}
        self.assertIn('config/majors.json', names)
        self.assertIn('config/.htaccess', names)
        self.assertNotIn('config/config.php', names)
        self.assertFalse(any(name.startswith(('uploads/', 'database/')) for name in names))
        ftp = FakeFTP()
        module.upload(ftp, '/htdocs/', files)
        self.assertEqual(sum(call[0] == 'stor' for call in ftp.calls), len(files))
        self.assertTrue(all(call[0] in {'cwd', 'mkd', 'stor'} for call in ftp.calls))

    def test_existing_sensitive_files_are_rejected_anywhere(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / 'root'
            root.mkdir()
            manifest = Path(temp) / 'manifest'
            for name in ('.env', 'assets/site.env', 'assets/backup.sql', 'assets/keys.pem',
                         'assets/ehtiyaj-private.php', 'assets/credentials.php',
                         'CONFIG/config.php', 'nested/config/majors.json', 'assets/.htaccess'):
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('synthetic fixture', encoding='utf-8')
                manifest.write_text(name + '\n', encoding='utf-8')
                with self.subTest(name=name), self.assertRaises(ValueError):
                    module.read_manifest(root, manifest)

    def test_sensitive_directory_components_are_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / 'root'
            root.mkdir()
            manifest = Path(temp) / 'manifest'
            for name in ('private/db.php', 'secrets/db.php', 'credentials/db.php',
                         'passwords/db.php', 'assets/PRIVATE/db.php',
                         'assets/SecretStore/db.php', 'assets/api-credentials/settings.php',
                         'private/config/majors.json'):
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('synthetic fixture', encoding='utf-8')
                manifest.write_text(name + '\n', encoding='utf-8')
                with self.subTest(name=name), self.assertRaisesRegex(ValueError, 'sensitive file'):
                    module.read_manifest(root, manifest)

    def test_sensitive_manifest_fails_before_ftp_connection(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / 'root'
            root.mkdir()
            (root / 'public.php').write_text('public fixture', encoding='utf-8')
            (root / 'secrets').mkdir()
            (root / 'secrets' / 'db.php').write_text('synthetic fixture', encoding='utf-8')
            manifest = Path(temp) / 'manifest'
            manifest.write_text('public.php\nsecrets/db.php\n', encoding='utf-8')
            argv = ['ftp_upload.py', '--local-dir', str(root), '--server-dir', '/htdocs/',
                    '--manifest', str(manifest)]
            with mock.patch.object(module.sys, 'argv', argv), \
                 mock.patch.object(module.ftplib, 'FTP') as ftp, \
                 mock.patch.object(module.ftplib, 'FTP_TLS') as ftps:
                with self.assertRaisesRegex(ValueError, 'sensitive file'):
                    module.main()
                ftp.assert_not_called()
                ftps.assert_not_called()

    def test_forbidden_paths_and_symlinks(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / 'root'
            root.mkdir()
            (root / 'app.php').write_text('test')
            manifest = Path(temp) / 'manifest'
            for name in ('uploads/a.jpg', 'database/users.sql', 'config/config.php', '../escape', '/etc/passwd', '.git/config'):
                manifest.write_text(name + '\n')
                with self.subTest(name=name), self.assertRaises(ValueError):
                    module.read_manifest(root, manifest)
            (root / 'linked.php').symlink_to(root / 'app.php')
            manifest.write_text('linked.php\n')
            with self.assertRaises(ValueError):
                module.read_manifest(root, manifest)


if __name__ == '__main__':
    unittest.main()

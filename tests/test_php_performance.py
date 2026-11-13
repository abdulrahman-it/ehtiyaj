"""Run isolated fake-PDO render scenarios; no application database is opened.

Set EHTIYAJ_PHP_TEST_COMMAND to a PHP CLI command if php is not on PATH.
"""
import json
import os
import shlex
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMMAND = shlex.split(os.environ.get('EHTIYAJ_PHP_TEST_COMMAND', 'php'))
AVAILABLE = bool(COMMAND and shutil.which(COMMAND[0]))


@unittest.skipUnless(AVAILABLE, 'PHP CLI unavailable; set EHTIYAJ_PHP_TEST_COMMAND')
class FakeDatabasePerformanceTests(unittest.TestCase):
    def scenario(self, name, *args):
        result = subprocess.run(COMMAND + [str(ROOT / 'tests/php_performance_scenarios.php'), name, *args],
                                cwd=ROOT, check=True, text=True, capture_output=True)
        # Some wasm runtimes return success even for a PHP fatal error; require JSON.
        return json.loads(result.stdout.strip())

    def test_message_loop_output_and_queries(self):
        expected = {
            'chat-empty': (0, '3a92f483ac34e14d161c32bb91a619e002b972d39e9197ca9d3fdde81d63908a'),
            'chat-populated': (3, '97afd58d7cae512783967820862e96ff9d1fcd7f5bfe5a4b897049639b36fb07'),
        }
        for name, (queries, digest) in expected.items():
            with self.subTest(name=name):
                result = self.scenario(name)
                self.assertEqual((result['queries'], result['html_sha256']), (queries, digest))

    def test_owner_and_visitor_results_and_queries(self):
        for name, expected in [('request-owner', (3, 1)), ('request-owner-empty', (3, 1)),
                               ('request-visitor-none', (2, 2)),
                               ('request-visitor-has', (2, 2))]:
            with self.subTest(name=name):
                baseline = self.scenario(name, 'baseline')
                current = self.scenario(name)
                self.assertEqual((baseline['queries'], current['queries']), expected)
                for key in ('already', 'offers', 'panel_sha256'):
                    self.assertEqual(baseline[key], current[key])

    def test_dashboard_count_and_user_isolation(self):
        for name, queries, count in [
            ('dashboard-zero', 2, 0), ('dashboard-populated', 2, 2), ('dashboard-read', 2, 0),
            ('header-anonymous', 0, 0), ('header-mismatch', 2, 2), ('header-other-user', 1, 5),
        ]:
            with self.subTest(name=name):
                result = self.scenario(name)
                self.assertEqual((result['queries'], result['count']), (queries, count))

    def test_session_validity(self):
        for name, queries in [('anonymous', 0), ('invalid-session', 1), ('inactive-session', 1)]:
            with self.subTest(name=name):
                self.assertEqual(self.scenario(name)['queries'], queries)


if __name__ == '__main__':
    unittest.main()

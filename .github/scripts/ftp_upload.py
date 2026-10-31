#!/usr/bin/env python3
"""Upload an explicit manifest without synchronizing or deleting remote files."""
import argparse
import ftplib
import os
import ssl
import sys
from pathlib import Path, PurePosixPath

FORBIDDEN_PARTS = {'uploads', 'database', '.git', '.github', 'tests', 'htdocs'}
# Preserve the host's existing connection file until a reviewed secret strategy is deployed.
CONFIG_ALLOWED = {'config/.htaccess', 'config/majors.json'}


def read_manifest(root: Path, manifest: Path):
    root = root.resolve(strict=True)
    files = []
    seen = set()
    for line in manifest.read_text(encoding='utf-8').splitlines():
        name = line.strip()
        if not name or name.startswith('#'):
            continue
        path = PurePosixPath(name)
        if (path.is_absolute() or str(path) != name or any(p in ('.', '..') for p in path.parts)
                or any(p.lower() in FORBIDDEN_PARTS for p in path.parts)
                or '\\' in name or '\r' in name or '\n' in name):
            raise ValueError('Unsafe manifest path')
        if path.parts[0] == 'config' and name not in CONFIG_ALLOWED:
            raise ValueError('Private configuration cannot be deployed')
        if name in seen:
            raise ValueError('Duplicate manifest path')
        seen.add(name)
        local = root.joinpath(*path.parts)
        if any(p.is_symlink() for p in (local, *local.parents) if p != root.parent):
            raise ValueError('Symlinks are not permitted')
        if not local.is_file() or not local.resolve().is_relative_to(root):
            raise ValueError('Missing or unsafe manifest file')
        files.append((path, local))
    if not files:
        raise ValueError('Empty deployment manifest')
    return files


def upload(ftp, server_dir, files):
    # server_dir must already exist; never create, delete, rename or list remote files.
    for path, local in files:
        ftp.cwd(server_dir)
        for directory in path.parts[:-1]:
            try:
                ftp.cwd(directory)
            except ftplib.error_perm:
                ftp.mkd(directory)
                ftp.cwd(directory)
        with local.open('rb') as source:
            ftp.storbinary('STOR ' + path.name, source)
        print('Uploaded:', path.as_posix(), flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--local-dir', required=True)
    parser.add_argument('--server-dir', required=True)
    parser.add_argument('--manifest', required=True)
    args = parser.parse_args()
    if args.server_dir != '/htdocs/':
        raise ValueError('Unexpected destination')
    files = read_manifest(Path(args.local_dir), Path(args.manifest))
    server, username, password = (os.environ.get(k, '') for k in ('FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD'))
    if not all((server, username, password)):
        raise ValueError('Missing FTP credentials')
    # This retains the existing FTP protocol; FTP is unencrypted. Use FTPS only if the host supports it.
    protocol = os.environ.get('FTP_PROTOCOL', 'ftp').lower()
    if protocol not in ('ftp', 'ftps'):
        raise ValueError('Unsupported protocol')
    ftp = ftplib.FTP_TLS(timeout=60, context=ssl.create_default_context()) if protocol == 'ftps' else ftplib.FTP(timeout=60)
    try:
        ftp.connect(server)
        ftp.login(username, password)
        if protocol == 'ftps':
            ftp.prot_p()
        upload(ftp, args.server_dir, files)
        ftp.quit()
    finally:
        ftp.close()


if __name__ == '__main__':
    try:
        main()
    except Exception:
        # Do not print connection details or server responses which may contain credentials.
        print('Deployment failed; inspect configuration and retry only after reviewing remote files.', file=sys.stderr)
        sys.exit(1)

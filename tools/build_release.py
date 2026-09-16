"""Build the installable ZIP used by the GitHub updater (Python standard library)."""
import argparse
import hashlib
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SLUG = 'globiqnews-fresh-ai-publisher'


def build(output: Path):
    main = (ROOT / f'{SLUG}.php').read_text(encoding='utf-8')
    header = re.search(r'^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$', main, re.M)
    constant = re.search(r"define\('GNF5_VERSION', '(\d+\.\d+\.\d+)'\)", main)
    if not header or not constant or header[1] != constant[1]:
        raise SystemExit('Version header and GNF5_VERSION must match (example: 5.29.0).')
    version = header[1]
    readme = (ROOT / 'readme.txt').read_text(encoding='utf-8')
    if not re.search(r'^Stable tag:\s*' + re.escape(version) + r'\s*$', readme, re.M):
        raise SystemExit('The readme.txt Stable tag must match the plugin version.')
    paths = [ROOT / f'{SLUG}.php', ROOT / 'readme.txt', ROOT / 'CHANGELOG.txt']
    for folder in ('includes', 'assets', 'vendor'):
        paths.extend(p for p in (ROOT / folder).rglob('*') if p.is_file())
    output.mkdir(parents=True, exist_ok=True)
    target = output / f'{SLUG}.zip'
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(paths):
            if path.is_symlink():
                raise SystemExit(f'Symlinks are not allowed in releases: {path}')
            archive.write(path, f'{SLUG}/{path.relative_to(ROOT).as_posix()}')
    with zipfile.ZipFile(target) as archive:
        if archive.testzip() is not None:
            raise SystemExit('ZIP integrity check failed.')
        assert f'{SLUG}/vendor/plugin-update-checker/plugin-update-checker.php' in archive.namelist()
    (output / 'SHA256SUMS.txt').write_text(hashlib.sha256(target.read_bytes()).hexdigest() + '  ' + target.name + '\n')
    print(version)
    return version


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', type=Path, default=ROOT / 'dist')
    build(parser.parse_args().output)

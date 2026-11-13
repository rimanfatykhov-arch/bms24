"""Fail-closed STANDARD browser observations and loopback request contract."""
from urllib.parse import urlparse


def require(condition, message='Browser acceptance condition failed'):
    if not condition:
        raise RuntimeError(message)


def local_url(url):
    parsed = urlparse(url)
    require(parsed.scheme == 'http' and parsed.netloc == '127.0.0.1:8080'
            and not parsed.username and not parsed.password, 'Non-loopback browser URL')
    return url


def validate_observations(obs):
    require(obs.get('phase') == 'verify', 'Unexpected browser phase')
    require('execution_error' not in obs, 'Browser execution failed')
    require(obs.get('page_errors') == [], 'Browser page errors or missing evidence')
    steps = obs['steps']
    for step in ['compact_text_and_file', 'full_text_and_file']:
        require(steps[step].get('rendered_text') is True, step + ': missing text')
    require(steps['full_text_only'].get('rendered') is True, 'Missing text-only message')
    require(steps['server_file_only_direct'].get('http') == 303, 'Direct upload failed')
    for step, name in [('compact_text_and_file', 'p02-compact.txt'),
                       ('compact_file_only', 'p02-compact-only.txt'),
                       ('full_text_and_file', 'p02-text-and-file.txt'),
                       ('full_file_only', 'p02-file-only.txt'),
                       ('server_file_only_direct', 'p02-direct-only.txt')]:
        row = steps[step]
        require(row.get('rendered_file') is True, step + ': missing file')
        require(row.get('downloads', {}).get(name) == {'http': 200, 'sha_ok': True},
                step + ': missing or corrupt download')
    require('p02-text-and-file.txt' in steps['full_text_and_file'].get('filename_feedback', ''),
            'Missing selected-file feedback')
    require(obs.get('attachment_urls', 0) >= 5, 'Missing attachment URLs')

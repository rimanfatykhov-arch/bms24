"""Capture the prototype screens with headless Edge/Chrome (separate temporary profile; synthetic data only).

Usage: python capture_screens.py <base-url> <browser.exe>
Writes PNGs to docs/communication/ux/screens/.
"""
import subprocess
import sys
import tempfile
from pathlib import Path

base, exe = sys.argv[1].rstrip('/'), sys.argv[2]
out = Path(__file__).resolve().parent / 'screens'
out.mkdir(exist_ok=True)
SHOTS = [
    ('A-conversation-1440', '/?#A', 1440, 900),
    ('A-reply-files-1440', '/?reply=m3#A', 1440, 900),
    ('B-task-discussion-1440', '/?#B', 1440, 900),
    ('B-task-details-drawer-1440', '/?drawer=1#B', 1440, 900),
    ('C-notifications-1440', '/?#C', 1440, 900),
    ('C-notifications-expanded-1440', '/?expand=1#C', 1440, 900),
    ('D-new-conversation-1440', '/?#D', 1440, 900),
    ('E-phone-flow-1440', '/?#E', 1440, 1000),
    ('state-send-error-1440', '/?state=senderr&reply=m3#A', 1440, 900),
    ('state-no-access-1440', '/?state=noaccess#B', 1440, 900),
    ('state-long-content-1440', '/?state=long#B', 1440, 900),
    ('state-no-search-results-1440', '/?state=nosearch#A', 1440, 900),
    ('future-channels-ai-1440', '/?future=1&drawer=1#A', 1440, 900),
    ('tablet-768-notifications', '/?#C', 768, 1024),
]
profile = tempfile.mkdtemp(prefix='bms24-ux-capture-')
if True:
    for name, path, w, h in SHOTS:
        target = out / (name + '.png')
        subprocess.run([exe, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run',
                        '--user-data-dir=' + profile, '--window-size=%d,%d' % (w, h),
                        '--virtual-time-budget=3000', '--screenshot=' + str(target), base + path],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=90)
        print(target.name, target.stat().st_size)

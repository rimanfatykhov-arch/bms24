"""Build the standalone prototype page from prototype-src.html (the same source is published as a private Artifact)."""
from pathlib import Path
here = Path(__file__).resolve().parent
src = (here / 'prototype-src.html').read_text(encoding='utf-8')
head = ('<!doctype html>\n<html lang="ru">\n<head>\n<meta charset="utf-8">\n'
        '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">\n</head>\n<body>\n')
(here / 'prototype' / 'index.html').write_text(head + src + '\n</body>\n</html>\n', encoding='utf-8', newline='\n')
print('built', here / 'prototype' / 'index.html')

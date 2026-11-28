"""Strict minimal Mustache renderer for the BMS24 UI harness (#160, task B).

Covers what the render-only bms24_* templates use: variables (escaped), triple braces / &, sections over lists,
dicts and truthy values, inverted sections, partials (component/name -> templates/name.mustache), comments, dotted
names, and the Moodle helpers str (plugin lang file), pix and quote; js blocks are dropped.

Strict on purpose: an unknown partial, an unclosed or mismatched section raises, so a template change cannot pass the
harness silently. Not a general Mustache implementation (no set delimiters, no lambdas, no standalone-line trimming:
whitespace is irrelevant for HTML).
"""
import html
import json
import re
from pathlib import Path

TAG = re.compile(r'\{\{(\{|!|#|\^|/|>|&)?\s*(.*?)\s*\}?\}\}', re.S)
HELPERS = ('str', 'pix', 'js', 'quote', 'userdate', 'shortentext', 'cleanstr')


class TemplateError(Exception):
    pass


def parse(src, name):
    tokens, pos = [], 0
    for m in TAG.finditer(src):
        if m.start() > pos:
            tokens.append(('text', src[pos:m.start()]))
        kind, key = m.group(1) or '', m.group(2)
        if kind in ('{', '&'):
            tokens.append(('raw', key))
        elif kind == '!':
            pass
        elif kind in ('#', '^', '/', '>'):
            tokens.append((kind, key))
        else:
            tokens.append(('var', key))
        pos = m.end()
    tokens.append(('text', src[pos:]))
    root, stack = [], []
    current = root
    for t in tokens:
        if t[0] in ('#', '^'):
            node = [t[0], t[1], []]
            current.append(node)
            stack.append((t[1], current))
            current = node[2]
        elif t[0] == '/':
            if not stack or stack[-1][0] != t[1]:
                raise TemplateError('%s: closing {{/%s}} does not match %r' % (name, t[1], stack[-1][0] if stack else None))
            current = stack.pop()[1]
        else:
            current.append(t)
    if stack:
        raise TemplateError('%s: unclosed section {{#%s}}' % (name, stack[-1][0]))
    return root


def load_strings(langfile):
    """$string['key'] = '...' / "..." from a Moodle lang file (single- and double-quoted literals)."""
    strings = {}
    if not langfile.is_file():
        return strings
    text = langfile.read_text(encoding='utf-8')
    for m in re.finditer(r"\$string\['([^']+)'\]\s*=\s*('(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\")\s*;", text):
        raw = m.group(2)
        if raw[0] == "'":
            value = raw[1:-1].replace("\\'", "'").replace('\\\\', '\\')
        else:
            value = raw[1:-1].encode('utf-8').decode('unicode_escape').encode('latin-1').decode('utf-8')
        strings[m.group(1)] = value
    return strings


class Renderer:
    def __init__(self, tpldir, strings=None, component='local_kbacompany'):
        self.dir = Path(tpldir)
        self.cache = {}
        self.strings = strings or {}
        self.component = component
        self.missing_strings = set()
        self.used = set()

    def tree(self, name):
        base = name.split('/')[-1]
        if base not in self.cache:
            path = self.dir / (base + '.mustache')
            if not path.is_file():
                raise TemplateError('partial %r not found in %s' % (name, self.dir))
            self.cache[base] = parse(path.read_text(encoding='utf-8'), base)
        self.used.add(base)
        return self.cache[base]

    @staticmethod
    def lookup(stack, name):
        if name == '.':
            return stack[-1]
        first, *rest = name.split('.')
        for ctx in reversed(stack):
            if isinstance(ctx, dict) and first in ctx:
                value = ctx[first]
                for part in rest:
                    value = value.get(part) if isinstance(value, dict) else None
                return value
        return None

    def render(self, name, ctx):
        return self.run(self.tree(name), [ctx])

    def helper(self, name, body, stack):
        text = self.run(body, stack)
        if name == 'js':
            return ''
        if name == 'quote':
            return json.dumps(text, ensure_ascii=False)
        if name == 'pix':
            return '<span class="harness-pix" aria-hidden="true">&#9679;</span>'
        if name == 'str':
            parts = [p.strip() for p in text.split(',')]
            key, component = parts[0], (parts[1] if len(parts) > 1 and parts[1] else 'core')
            if component == self.component and key in self.strings:
                return html.escape(self.strings[key], quote=False)
            self.missing_strings.add('%s/%s' % (component, key))
            return html.escape(key)
        return html.escape(text)

    def run(self, nodes, stack):
        out = []
        for n in nodes:
            kind = n[0]
            if kind == 'text':
                out.append(n[1])
            elif kind == 'var':
                v = self.lookup(stack, n[1])
                out.append('' if v is None or v is False else html.escape(str(v), quote=True))
            elif kind == 'raw':
                v = self.lookup(stack, n[1])
                out.append('' if v is None or v is False else str(v))
            elif kind == '>':
                out.append(self.run(self.tree(n[1]), stack))
            elif kind == '#':
                if n[1] in HELPERS and self.lookup(stack, n[1]) is None:
                    out.append(self.helper(n[1], n[2], stack))
                    continue
                v = self.lookup(stack, n[1])
                if isinstance(v, list):
                    for item in v:
                        out.append(self.run(n[2], stack + [item]))
                elif v:
                    out.append(self.run(n[2], stack + [v] if isinstance(v, dict) else stack))
            elif kind == '^':
                v = self.lookup(stack, n[1])
                if not v:
                    out.append(self.run(n[2], stack))
        return ''.join(out)

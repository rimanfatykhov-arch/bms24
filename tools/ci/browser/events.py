"""RC32 BROWSER: structured browser error evidence (Codex review of PR #96, comment 6873684005).

Producer side only (no verdicts). One Recorder per browser context:
  * route(): the context route. Requests to the exact disposable origin http://127.0.0.1:8080 continue; every other request
    is aborted with the DISTINCT error code 'blockedbyclient' (net::ERR_BLOCKED_BY_CLIENT, never the generic net::ERR_FAILED)
    and recorded as {'source': 'route_abort', 'url', 'resource_type', 'reason': ABORT_REASON}.
  * attach(page): records 'requestfailed' {url, resource_type, failure}, 'pageerror' {name, message} and console errors
    {type, text, url (console location = failing resource for resource errors)}.
The verdict is contract.browser_event_violations(); this module never filters or drops anything.
This is request scoping inside the test browser, NOT OS-level network isolation.
"""
from urllib.parse import urlparse

ORIGIN = ('http', '127.0.0.1:8080')
ABORT_CODE = 'blockedbyclient'
ABORT_REASON = 'non_loopback_request_blocked_by_test_route'


def is_loopback(url):
    p = urlparse(url or '')
    return (p.scheme, p.netloc) == ORIGIN


class Recorder:
    def __init__(self):
        self.events = []

    def route(self, route):
        req = route.request
        if is_loopback(req.url):
            return route.continue_()
        self.events.append({'source': 'route_abort', 'url': req.url, 'resource_type': req.resource_type, 'reason': ABORT_REASON})
        return route.abort(ABORT_CODE)

    def attach(self, page):
        page.on('requestfailed', lambda r: self.events.append(
            {'source': 'requestfailed', 'url': r.url, 'resource_type': r.resource_type, 'failure': r.failure}))
        page.on('pageerror', lambda e: self.events.append(
            {'source': 'pageerror', 'name': type(e).__name__, 'message': str(e)[:300]}))
        page.on('console', lambda m: self.events.append(
            {'source': 'console', 'type': m.type, 'text': m.text[:300], 'url': (m.location or {}).get('url', '')}) if m.type == 'error' else None)
        return page

    def install(self, ctx):
        """Route every request of the context and attach the listeners to every page it opens."""
        ctx.route('**/*', self.route)
        ctx.on('page', self.attach)
        return self

"""Synthetic browser fixture: a subsequent reload must not abort pending page scripts."""
import json, threading, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from playwright.sync_api import sync_playwright
from events import Recorder
from contract import browser_event_violations

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_GET(self):
        if self.path.startswith('/slow.js'):
            time.sleep(0.7)
            data=b'window.fixtureScriptComplete = true;'
            kind='text/javascript'
        elif self.path=='/next':
            data=b"""<html><body>Next<script>addEventListener('load',()=>setTimeout(()=>{
                const s=document.createElement('script');s.src='/slow.js';document.head.appendChild(s);
            },20));</script></body></html>"""
            kind='text/html'
        else:
            data=b'<a href="/next">Next</a>';kind='text/html'
        self.send_response(200);self.send_header('Content-Type',kind);self.end_headers()
        try:self.wfile.write(data)
        except BrokenPipeError:pass

server=ThreadingHTTPServer(('127.0.0.1',8080),Handler)
threading.Thread(target=server.serve_forever,daemon=True).start()
rows=[]
with sync_playwright() as pw:
    b=pw.chromium.launch(headless=True,args=['--disable-dev-shm-usage'])
    for settled in (False,True):
        c=b.new_context();rec=Recorder().install(c);p=c.new_page()
        p.goto('http://127.0.0.1:8080/',wait_until='networkidle')
        if settled:
            with p.expect_navigation(wait_until='networkidle'):p.get_by_role('link',name='Next').click()
            assert p.evaluate('window.fixtureScriptComplete') is True
        else:
            # Observe the pending script before reproducing the premature follow-up reload.
            with p.expect_request('**/slow.js'):
                with p.expect_navigation():p.get_by_role('link',name='Next').click()
        p.goto('http://127.0.0.1:8080/',wait_until='networkidle')
        c.close()
        errors=browser_event_violations(rec.events)
        assert bool(errors) is (not settled), (settled,rec.events)
        if not settled: assert any('net::ERR_ABORTED' in x for x in errors),errors
        rows.append({'wait_networkidle':settled,'verdict':'FAIL' if errors else 'PASS','violations':errors})
    b.close()
server.shutdown()
print(json.dumps({'status':'PASS','scope':'synthetic navigation timing, not Moodle feature acceptance','cases':rows}))

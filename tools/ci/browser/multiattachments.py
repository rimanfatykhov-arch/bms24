"""Real Moodle retained multi-attachment regression; synthetic data only, loopback-only requests."""
import hashlib
import json
import os
import uuid
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright
from contract import local_url, require, MULTI_CONTENT, multiattachment_event_violations
from events import Recorder

require(os.environ.get('BMS_CI_BROWSER') == '1' or os.environ.get('BMS_RECOVERY_BROWSER') == '1', 'Isolated fixture required')
root = Path(os.environ['EVIDENCE'])
f = json.loads((root / 'actors.private.json').read_text())
base = 'http://127.0.0.1:8080'
company, task = f['companies']['A']['uuid'], f['tasks']['A']['uuid']
actor = f['actors']['MA']
result = {'status': 'FAIL', 'cases': []}
with sync_playwright() as pw:
    browser = pw.chromium.launch(headless=True)
    try:
        for width in (320, 1440):
            context = browser.new_context(viewport={'width': width, 'height': 900})
            recorder = Recorder().install(context)
            page = context.new_page()
            page.on('response', lambda response: recorder.events.append({'source': 'http_error',
                    'url': response.url, 'method': response.request.method, 'status': response.status})
                    if response.status >= 500 else None)
            page.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
            page.locator('#username').fill(actor['username'])
            page.locator('#password').fill(actor['password'])
            page.locator('#loginbtn').click()
            page.wait_for_load_state('networkidle')
            url = local_url(base + '/local/kbacompany/tasks.php?uuid=' + company + '&task=' + task + '&view=detail&tab=overview')
            response = page.goto(url, wait_until='networkidle')
            require(response.status == 200, 'Task HTTP error')
            form = page.locator('form[data-bms-discussion-composer]').first
            require(form.count() == 1, 'Composer missing')
            require(form.locator('input[name=action][value=discussionpost]').count() == 1,
                    'Named action control missing: form-property shadow regression not exercised')
            token = 'multi-' + uuid.uuid4().hex
            files = [{'name': token + '-' + str(n) + '.txt', 'mimeType': 'text/plain',
                      'buffer': MULTI_CONTENT[n]} for n in (1, 2, 3)]
            text = 'Multi attachment regression ' + token
            form.locator('textarea').fill(text)
            field = form.locator('input[type=file]')
            field.set_input_files(files[:2])
            selected_first = form.locator('[data-bms-attachment-list] li').count()
            require(selected_first == 2, 'One selection did not keep both files')
            field.set_input_files(files[2:])
            selected_second = form.locator('[data-bms-attachment-list] li').count()
            require(selected_second == 3, 'Second selection replaced first files')
            form.locator('[data-bms-attachment-list] li').nth(1).locator('button').click()
            require(field.evaluate('e => Array.from(e.files).map(f=>f.name)') == [files[0]['name'], files[2]['name']], 'Removal changed wrong file')
            # Negative controls: HTTP500 and redirect must not be reported as successful sends.
            controls = []
            original_action = form.get_attribute('action')
            redirect_requests = []
            page.on('request', lambda request: redirect_requests.append(request.url)
                    if '/__bms_negative_redirect_' in urlparse(request.url).path else None)
            for status in (500, 302):
                # Unique URL ties tolerated errors only to this fulfilled test request, never to
                # the whole phase or all requests sharing the production handler path.
                injected_url = local_url(original_action + '&bmsnegative=' + token + '-' + str(status))
                redirect_target = local_url(base + '/__bms_negative_redirect_' + token) if status == 302 else ''
                form.evaluate('(f, url) => f.setAttribute("action", url)', injected_url)
                def failure(route):
                    if route.request.method == 'POST' and route.request.url == injected_url:
                        recorder.events.append({'source': 'test_injected_response', 'url': injected_url,
                                                'method': 'POST', 'status': status, 'location': redirect_target})
                        route.fulfill(status=status, headers={'Location': redirect_target} if status == 302 else {}, body='Not accepted')
                    else:
                        route.fallback()
                page.route('**/local/kbacompany/tasks.php*', failure)
                form.locator('button[type=submit]').click()
                form.locator('[data-bms-discussion-error]:visible').wait_for()
                require(form.locator('textarea').input_value() == text, 'Failure lost text')
                require(field.evaluate('e => e.files.length') == 2, 'Failure lost files')
                require(not form.locator('button[type=submit]').is_disabled(), 'Failure prevents explicit retry')
                page.wait_for_load_state('networkidle')
                controls.append({'http': status, 'url': injected_url, 'text_retained': form.locator('textarea').input_value() == text,
                                 'files_retained': field.evaluate('e => e.files.length'),
                                 'location': redirect_target, 'redirect_requests': list(redirect_requests),
                                 'error_visible': form.locator('[data-bms-discussion-error]').is_visible()})
                require(not redirect_requests, 'Automatic redirect was requested')
                page.unroute('**/local/kbacompany/tasks.php*', failure)
            form.evaluate('(f, url) => f.setAttribute("action", url)', original_action)
            posts = []
            replies = []
            def capture_reply(response):
                if response.request.method != 'POST' or urlparse(response.url).path != '/local/kbacompany/tasks.php':
                    return
                row = {'status': response.status, 'content_type': response.headers.get('content-type', '')}
                try:
                    reply = response.json()
                    row.update({key: reply.get(key) for key in ('contract', 'ok', 'error')})
                    row['has_task_in_target'] = 'task=' in reply.get('url', '')
                except Exception:
                    row['json'] = False
                replies.append(row)
            page.on('response', capture_reply)
            page.on('request', lambda request: posts.append(request.url) if request.method == 'POST' and '/tasks.php' in request.url else None)
            with page.expect_navigation():
                if width == 320:
                    form.locator('textarea').press('Enter')
                else:
                    form.locator('button[type=submit]').click()
            page.wait_for_load_state('networkidle')
            require(len(posts) == 1, 'Duplicate send request')
            page.reload(wait_until='networkidle')
            require(page.get_by_text(text, exact=True).count() == 1, 'Message missing or duplicated after reload')
            downloads = []
            for file in (files[0], files[2]):
                link = page.get_by_role('link', name=file['name'], exact=True)
                require(link.count() == 1, 'Attachment name missing or duplicated')
                download = context.request.get(local_url(link.get_attribute('href')), max_redirects=0)
                require(download.status == 200, 'Download refused or redirected')
                digest = hashlib.sha256(download.body()).hexdigest()
                require(digest == hashlib.sha256(file['buffer']).hexdigest(), 'Attachment bytes differ')
                downloads.append({'name': file['name'], 'url': link.get_attribute('href'),
                                  'http': download.status, 'sha256': digest})
            require(page.get_by_role('link', name=files[1]['name'], exact=True).count() == 0, 'Removed attachment stored')
            errors = multiattachment_event_violations(recorder.events, controls)
            require(not errors, 'Unexpected browser or server error: ' + repr(errors))
            result['cases'].append({'width': width, 'status': 'PASS', 'downloads': downloads,
                                   'negative_controls': controls, 'selected_first': selected_first,
                                   'selected_second': selected_second, 'post_count': len(posts),
                                   'message_count': page.get_by_text(text, exact=True).count(),
                                   'removed_file_count': page.get_by_role('link', name=files[1]['name'], exact=True).count(),
                                   'browser_errors': errors, 'browser_events': recorder.events})
            context.close()
        result['status'] = 'PASS'
    except Exception as error:
        result['error'] = str(error)
        if 'page' in locals() and not page.is_closed():
            try:
                result['diagnostic'] = {'composer_error': page.locator('[data-bms-discussion-error]').all_text_contents(),
                                        'browser_events': recorder.events if 'recorder' in locals() else None,
                                        'form_route': form.evaluate('(f) => ({actionAttribute:f.getAttribute("action"), actionPropertyType:typeof f.action, pathname:location.pathname, origin:location.origin})') if 'form' in locals() else None,
                                        'post_replies': replies if 'replies' in locals() else [],
                                        'post_count': len(posts) if 'posts' in locals() else 0}
                page.screenshot(path=str(root / 'multiattachment-failure.png'), full_page=True)
            except Exception as diagnostic_error:
                result['diagnostic_error'] = str(diagnostic_error)
        raise
    finally:
        browser.close()
        (root / 'multiattachment-result.json').write_text(json.dumps(result, indent=2))
print(json.dumps(result))

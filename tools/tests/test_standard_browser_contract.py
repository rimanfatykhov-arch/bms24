"""Negative controls for the confirmed PR86 false-green observations."""
import unittest
from standard_browser_contract import local_url, require, validate_observations


def valid():
    pairs = [('compact_text_and_file','p02-compact.txt'), ('compact_file_only','p02-compact-only.txt'),
             ('full_text_and_file','p02-text-and-file.txt'), ('full_file_only','p02-file-only.txt'),
             ('server_file_only_direct','p02-direct-only.txt')]
    steps = {step: {'rendered_file': True, 'rendered_text': True,
                   'downloads': {name: {'http':200, 'sha_ok':True}}} for step,name in pairs}
    steps['full_text_only'] = {'rendered': True}
    steps['server_file_only_direct']['http'] = 303
    steps['full_text_and_file']['filename_feedback'] = 'p02-text-and-file.txt 36 B'
    return {'phase':'verify','steps':steps,'page_errors':[], 'attachment_urls':5}


class BrowserContractTest(unittest.TestCase):
    def test_complete_observations_pass(self):
        validate_observations(valid())

    def test_proven_text_and_direct_failures_rejected(self):
        for step,key,bad in [('compact_text_and_file','rendered_text',False),
                             ('full_text_and_file','rendered_text',False),
                             ('full_text_only','rendered',False),
                             ('server_file_only_direct','http',500)]:
            with self.subTest(step=step):
                obs=valid();obs['steps'][step][key]=bad
                with self.assertRaises(RuntimeError): validate_observations(obs)

    def test_direct_redirect_without_stored_file_is_not_success(self):
        obs=valid();obs['steps']['server_file_only_direct']['downloads']={}
        with self.assertRaises(RuntimeError): validate_observations(obs)

    def test_corruption_missing_feedback_and_page_errors_fail(self):
        for change in ['hash','feedback','error','missing']:
            obs=valid()
            if change=='hash': obs['steps']['compact_file_only']['downloads']['p02-compact-only.txt']['sha_ok']=False
            if change=='feedback': obs['steps']['full_text_and_file']['filename_feedback']=''
            if change=='error': obs['page_errors']=['pageerror']
            if change=='missing': del obs['steps']['full_text_only']
            with self.assertRaises((RuntimeError,KeyError)): validate_observations(obs)

    def test_network_origins_fail_before_request(self):
        self.assertEqual('http://127.0.0.1:8080/x?a=b', local_url('http://127.0.0.1:8080/x?a=b'))
        for url in ['https://127.0.0.1:8080/x','http://example.invalid/x','http://127.0.0.1:8080.example.invalid/x',
                    '//127.0.0.1:8080/x','http://user:pass@127.0.0.1:8080/x','file:///tmp/x','http://127.0.0.1:8081/x']:
            with self.subTest(url=url), self.assertRaises(RuntimeError): local_url(url)

    def test_require_survives_optimized_python(self):
        with self.assertRaises(RuntimeError): require(False, 'negative control')

if __name__ == '__main__': unittest.main()

"""Read-only GitHub REST collection for BMS24. No artifact bodies are fetched."""
from datetime import datetime, timezone
from http.client import HTTPSConnection, HTTPException
import json
import re
import time
from urllib.parse import urlencode

REPO = "rimanfatykhov-arch/bms24"
WEB = f"https://github.com/{REPO}"
SHA = re.compile(r"[0-9a-f]{40}\Z")
ACTORS = {"Claude", "Codex", "DeepSeek"}
ROUTE = re.compile(
    r"/(?:commits/(?:main|[0-9a-f]{40})|pulls(?:/[1-9]\d*(?:/reviews)?)?"
    r"|issues(?:/[1-9]\d*(?:/comments)?)?|actions/runs(?:/[1-9]\d*"
    r"(?:/artifacts|/attempts/[1-9]\d*/jobs)?)?)\Z"
)


class CollectionError(Exception):
    """A safe reason code for a missing or incomplete source."""


def now():
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def sha(value):
    return value if isinstance(value, str) and SHA.fullmatch(value) else None


def number(value):
    return value if type(value) is int and value > 0 else None


def choice(value, allowed):
    return value if isinstance(value, str) and value in allowed else None


def get_response(path, token):
    conn = HTTPSConnection("api.github.com", timeout=10)
    try:
        conn.request("GET", path, headers={
            "Accept": "application/vnd.github+json", "User-Agent": "BMS24-BDO-1",
            "Authorization": "Bearer " + token,
        })
        response = conn.getresponse()
        return response.status, dict((k.lower(), v) for k, v in response.getheaders()), response.read(8_000_001)
    finally:
        conn.close()


class GitHub:
    def __init__(self, token, request=get_response, sleep=time.sleep, pages=30):
        self.token = token
        self.request = request
        self.sleep = sleep
        self.pages = pages

    def get(self, route, **query):
        if not ROUTE.fullmatch(route):
            raise CollectionError("endpoint_not_allowed")
        if set(query) - {"state", "per_page", "page", "head_sha", "event"}:
            raise CollectionError("query_not_allowed")
        path = "/repos/" + REPO + route
        if query:
            path += "?" + urlencode(query)
        for attempt in range(3):
            try:
                status, headers, data = self.request(path, self.token)
            except (OSError, HTTPException):
                status, headers, data = 503, {}, b""
            limited = status == 429 or (status == 403 and (
                headers.get("x-ratelimit-remaining") == "0" or "retry-after" in headers
            ))
            if status == 200:
                try:
                    if len(data) > 8_000_000:
                        raise ValueError
                    return json.loads(data), headers
                except (ValueError, UnicodeError, RecursionError):
                    raise CollectionError("invalid_response") from None
            if not limited and status not in {500, 502, 503, 504}:
                raise CollectionError("api_denied" if status in {401, 403, 404} else "api_error")
            delay = headers.get("retry-after", str(2 ** attempt))
            if attempt == 2 or not delay.isascii() or not delay.isdecimal() or len(delay) > 2 or int(delay) > 8:
                raise CollectionError("rate_limited" if limited else "api_unavailable")
            self.sleep(int(delay))

    def list(self, route, key=None, **query):
        records = {}
        for page in range(1, self.pages + 1):
            data, headers = self.get(route, per_page=100, page=page, **query)
            if key and not isinstance(data, dict):
                raise CollectionError("invalid_page")
            items = data.get(key) if key else data
            if not isinstance(items, list):
                raise CollectionError("invalid_page")
            for item in items:
                item_id = number(item.get("id")) if isinstance(item, dict) else None
                if not item_id or item_id in records:
                    raise CollectionError("inconsistent_page")
                records[item_id] = item
            if 'rel="next"' not in headers.get("link", ""):
                if len(items) == 100 or (key and data.get("total_count") != len(records)):
                    # A full final page can be legitimate. Confirm it with the next page.
                    if len(items) == 100 and (not key or (number(data.get("total_count")) or 0) >= len(records)):
                        continue
                    raise CollectionError("incomplete_page")
                return list(records.values())
        raise CollectionError("page_limit")


def registry_assignments(body):
    """Accept only #198's active table and explicit PR/Issue references in its PR cell."""
    active = False
    mapped = {}
    for line in body.splitlines():
        if line.startswith("## "):
            active = line.startswith("## Активные линии")
        if not active or not line.startswith("|"):
            continue
        cells = [cell.strip() for cell in line.strip("|").split("|")]
        if len(cells) != 5:
            continue
        actor = choice(cells[2], ACTORS)
        refs = set()
        for start, end in re.findall(r"(?:^|[ ,])#([1-9][0-9]{0,8})(?:[–-]#([1-9][0-9]{0,8}))?(?=$|[ ,(])", cells[1]):
            first, last = int(start), int(end or start)
            if first <= last <= first + 100:
                refs.update(range(first, last + 1))
        for item in refs:
            state = "HOLD" if re.match(r"(?:Владелец \d{2}\.\d{2}: )?HOLD\b", cells[4]) else None
            # Ambiguous ownership stays unknown even if duplicate rows agree.
            mapped[item] = (actor, state) if item not in mapped else (None, None)
    return mapped


def collect(api, evidence=None):
    result = {"schema_version": 1, "mode": "github", "collected_at": now(), "main": None,
              "complete": True, "errors": [], "tasks": []}

    def observe(route, read):
        try:
            return read()
        except CollectionError as exc:
            result["complete"] = False
            result["errors"].append({"endpoint": route, "reason": str(exc)})
            return None

    def obj(route):
        def read():
            data, _ = api.get(route)
            if not isinstance(data, dict):
                raise CollectionError("invalid_object")
            return data
        return observe(route, read) or {}

    def listing(route, key=None, **query):
        return observe(route, lambda: api.list(route, key, **query)) or []

    main = sha(obj("/commits/main").get("sha"))
    result["main"] = main
    registry = obj("/issues/198")
    body = registry.get("body")
    result["registry_updated_at"] = registry.get("updated_at")
    if not isinstance(body, str):
        result["complete"] = False
    assignments = registry_assignments(body if isinstance(body, str) else "")
    pulls = listing("/pulls", state="open")
    issues = listing("/issues", state="open")
    open_numbers = {row.get("number") for row in pulls + issues if number(row.get("number"))}
    missing = set()
    for candidate in (evidence or {}).values():
        if isinstance(candidate, dict) and isinstance(candidate.get("dependencies"), list):
            missing.update(n for n in candidate["dependencies"] if number(n) and n not in open_numbers)
    for n in sorted(missing)[:50]:
        item = obj(f"/issues/{n}")
        if item.get("number") != n:
            continue
        if "pull_request" in item:
            pulls.append(item)
        else:
            issues.append(item)
    if len(missing) > 50 or len(pulls) + sum("pull_request" not in i for i in issues) > 250:
        result["complete"] = False
        result["errors"].append({"endpoint": "/pulls", "reason": "item_limit"})
    prs = [("pr", row) for row in pulls]
    plain = [("issue", row) for row in issues if "pull_request" not in row]
    commit_trees = {}
    for kind, listed in (prs + plain)[:250]:
        n = number(listed.get("number"))
        if not n:
            result["complete"] = False
            continue
        source = f"/pulls/{n}" if kind == "pr" else f"/issues/{n}"
        item = obj(source) if kind == "pr" else listed
        owner, hold = assignments.get(n, (None, None))
        task = {"number": n, "kind": kind, "executor": owner, "registry_status": hold,
                "state": item.get("state"), "draft": item.get("draft"),
                "merged": item.get("merged"), "mergeable": item.get("mergeable"),
                "comments": [c["id"] for c in listing(f"/issues/{n}/comments")]}
        if kind == "pr":
            head_obj = item.get("head") or {}
            base_obj = item.get("base") or {}
            head = sha(head_obj.get("sha")) if isinstance(head_obj, dict) else None
            base = sha(base_obj.get("sha")) if isinstance(base_obj, dict) and base_obj.get("ref") == "main" else None
            if head and head not in commit_trees:
                commit = obj(f"/commits/{head}").get("commit") or {}
                tree = commit.get("tree") if isinstance(commit, dict) else None
                commit_trees[head] = sha(tree.get("sha")) if isinstance(tree, dict) else None
            task.update(head=head, base=base, tree=commit_trees.get(head), runs=[],
                        reviews=[{"id": rr["id"], "head": rr.get("commit_id"), "state": rr.get("state")}
                                 for rr in listing(f"/pulls/{n}/reviews")])
            if head:
                runs = listing("/actions/runs", "workflow_runs", head_sha=head, event="pull_request")
                if len(runs) > 30:
                    result["complete"] = False
                    result["errors"].append({"endpoint": "/actions/runs", "reason": "run_limit"})
                for run in runs[:30]:
                    run_id, attempt = run["id"], number(run.get("run_attempt"))
                    if not attempt:
                        result["complete"] = False
                        continue
                    run_pulls = run.get("pull_requests")
                    identities = [p for p in run_pulls if isinstance(p, dict) and p.get("number") == n] \
                        if isinstance(run_pulls, list) else []
                    identity = identities[0] if len(identities) == 1 else {}
                    if run.get("event") != "pull_request":
                        continue
                    ref = identity.get("base") or {}
                    run_base = ref.get("sha") if isinstance(ref, dict) else None
                    jobs = listing(f"/actions/runs/{run_id}/attempts/{attempt}/jobs", "jobs")
                    artifacts = listing(f"/actions/runs/{run_id}/artifacts", "artifacts")
                    observed = obj(f"/actions/runs/{run_id}")
                    if any(observed.get(key) != run.get(key) for key in
                           ("run_attempt", "status", "conclusion", "head_sha")):
                        task["changed"] = True
                    task["runs"].append({
                        "id": run_id, "attempt": attempt, "workflow": run.get("path"), "head": run.get("head_sha"),
                        "base": run_base, "pr": n if identity else None, "conclusion": run.get("conclusion"),
                        "jobs": [{"id": j["id"], "name": j.get("name"), "conclusion": j.get("conclusion")} for j in jobs],
                        "artifacts": [{"id": a["id"], "expired": a.get("expired"),
                                       "package": a.get("name") == f"release-install-{run_id}-a{attempt}"}
                                      for a in artifacts],
                    })
            end = obj(source)
            if any(end.get(field) != item.get(field) for field in ("head", "base", "state", "draft", "updated_at")):
                task["changed"] = True
        result["tasks"].append(task)
    if not main or sha(obj("/commits/main").get("sha")) != main or obj("/issues/198").get("body") != body:
        result["complete"] = False
        result["errors"].append({"endpoint": "/commits/main", "reason": "main_or_registry_changed"})
    return result

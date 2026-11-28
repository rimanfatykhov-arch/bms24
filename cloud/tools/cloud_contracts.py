#!/usr/bin/env python3
"""BMS Cloud CLOUD-1a offline contract validator (v0.1, PROPOSED).

Standard library only, no network. Validates the cloud/ contracts, runs the
synthetic fixture suite and verifies the REG-002 schema package before import.

Exit codes: 0 = input valid, 1 = contract violations, 2 = bad invocation or
unreadable input.

This tool proves contract consistency only. It is not a runtime, restore (G3)
or application ACL test and must not be reported as one.
"""
import argparse
import copy
import hashlib
import json
import os
import re
import sys
import zipfile

VALIDATOR_VERSION = "0.1.0"
EXIT_OK, EXIT_VIOLATIONS, EXIT_USAGE = 0, 1, 2

CLOUD_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPO_ROOT = os.path.dirname(CLOUD_DIR)
REGISTRY = os.path.join(CLOUD_DIR, "contracts", "registry", "v0.1", "registry_mvp.json")
SCHEMA = os.path.join(CLOUD_DIR, "contracts", "registry", "v0.1", "exchange.schema.json")
CATALOG = os.path.join(CLOUD_DIR, "contracts", "entitlements", "v0.1", "catalog.json")
MAPPING = os.path.join(CLOUD_DIR, "contracts", "mapping", "v0.1", "projection_mapping.json")
MANIFEST = os.path.join(CLOUD_DIR, "contracts", "provenance", "reg002_package_manifest.json")
FIXTURES = os.path.join(CLOUD_DIR, "fixtures", "v0.1")

SUPPORTED_SCHEMA_KEYWORDS = {
    "$schema", "$id", "$defs", "$ref", "title", "description", "type", "properties",
    "required", "additionalProperties", "items", "enum", "const", "pattern", "minLength",
    "x-bms-enum", "x-moodle-enum",
}
SECRET_KEY = re.compile(r"(pass(word|wd)?|secret|token|api[_-]?key|private[_-]?key|client[_-]?secret)", re.I)
SECRET_VALUE = re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----")
INACTIVE_WS = {"suspended", "closing", "closed"}
NEEDS_PRIMARY = {"provisioning", "active", "degraded", "suspended", "closing"}


class UsageError(Exception):
    """Unreadable input or bad invocation (exit 2)."""


class Report:
    def __init__(self):
        self.items = []

    def add(self, code, scope, path, message):
        self.items.append((code, scope, path, message))

    def codes(self, scope=None):
        return sorted({c for c, s, _, _ in self.items if scope is None or s == scope})

    def lines(self):
        return ["ERROR %s scope=%s path=%s :: %s" % item for item in sorted(set(self.items))]


def load_json(path):
    try:
        with open(path, encoding="utf-8") as handle:
            return json.load(handle)
    except (OSError, ValueError) as exc:
        raise UsageError("cannot read JSON %s: %s" % (path, exc))


def sha256_file(path):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()


# --------------------------------------------------------------------------- schema

class Schema:
    """Minimal JSON Schema 2020-12 subset; unsupported keywords fail closed."""

    def __init__(self, doc):
        self.doc = doc

    def keyword_errors(self):
        errors = []

        def walk(node, path):
            if isinstance(node, dict):
                for key, value in node.items():
                    if key not in SUPPORTED_SCHEMA_KEYWORDS:
                        errors.append((path, key))
                    if key in ("properties", "$defs"):
                        for name, sub in value.items():
                            walk(sub, "%s/%s/%s" % (path, key, name))
                    elif key == "items":
                        walk(value, path + "/items")

        walk(self.doc, "#")
        return errors

    def resolve(self, ref):
        if not ref.startswith("#/$defs/"):
            raise UsageError("unsupported $ref %s" % ref)
        return self.doc["$defs"][ref[len("#/$defs/"):]]

    def validate(self, value, node=None, path=""):
        node = self.doc if node is None else node
        if "$ref" in node:
            return self.validate(value, self.resolve(node["$ref"]), path)
        out = []
        expected = node.get("type")
        if expected is not None:
            types = expected if isinstance(expected, list) else [expected]
            if not any(_is_type(value, t) for t in types):
                return [("SCHEMA_TYPE", path or "/", "expected %s" % "|".join(types))]
        if "const" in node and value != node["const"]:
            out.append(("SCHEMA_CONST", path or "/", "expected %r" % node["const"]))
        if "enum" in node and value not in node["enum"]:
            out.append(("SCHEMA_ENUM", path or "/", "%r not in %s" % (value, node["enum"])))
        if isinstance(value, str):
            if "pattern" in node and not re.search(node["pattern"], value):
                out.append(("SCHEMA_PATTERN", path or "/", "%r does not match %s" % (value, node["pattern"])))
            if "minLength" in node and len(value) < node["minLength"]:
                out.append(("SCHEMA_MIN_LENGTH", path or "/", "too short"))
        if isinstance(value, dict):
            props = node.get("properties", {})
            for name in node.get("required", []):
                if name not in value:
                    out.append(("SCHEMA_REQUIRED", "%s/%s" % (path, name), "missing required property"))
            for name, sub in value.items():
                if name in props:
                    out.extend(self.validate(sub, props[name], "%s/%s" % (path, name)))
                elif node.get("additionalProperties") is False:
                    out.append(("SCHEMA_ADDITIONAL_PROPERTY", "%s/%s" % (path, name), "property not allowed"))
        if isinstance(value, list) and "items" in node:
            for index, item in enumerate(value):
                out.extend(self.validate(item, node["items"], "%s/%d" % (path, index)))
        return out

    def tagged_enums(self, tag):
        found = []

        def walk(node, path):
            if isinstance(node, dict):
                if tag in node:
                    found.append((path, node[tag], node.get("enum")))
                for key, value in node.items():
                    if isinstance(value, (dict, list)):
                        walk(value, path + "/" + key)
            elif isinstance(node, list):
                for index, value in enumerate(node):
                    walk(value, "%s/%d" % (path, index))

        walk(self.doc, "#")
        return found


def _is_type(value, name):
    if name == "object":
        return isinstance(value, dict)
    if name == "array":
        return isinstance(value, list)
    if name == "string":
        return isinstance(value, str)
    if name == "integer":
        return isinstance(value, int) and not isinstance(value, bool)
    if name == "null":
        return value is None
    if name == "boolean":
        return isinstance(value, bool)
    return False


# --------------------------------------------------------------------------- moodle sources

def _php_list(text):
    return re.findall(r"'([a-z_]+)'", text)


def read_moodle_sources(mapping, repo_root):
    """Extract the projection value sets the current Moodle code accepts (read-only)."""
    paths = {k: os.path.join(repo_root, v) for k, v in mapping["moodle_sources"].items()}
    def read(key):
        try:
            with open(paths[key], encoding="utf-8") as handle:
                return handle.read()
        except OSError as exc:
            raise UsageError("MOODLE_SOURCE_UNREADABLE: %s" % exc)

    wms = read("workspace_mapping_service")
    snap = read("entitlement_snapshot_service")
    tariff = read("bms_tariff_catalog_service")

    def body(name):
        match = re.search(r"function %s\(.*?\{(.*?)\n    \}" % name, wms, re.S)
        if not match:
            raise UsageError("MOODLE_SOURCE_UNREADABLE: %s() not found" % name)
        return match.group(1)

    constants = dict(re.findall(r"const (MODE_[A-Z]+) = '([a-z_]+)'", wms))
    modes = [constants[c] for c in re.findall(r"self::(MODE_[A-Z]+)", body("validate_mode"))]
    products = re.search(r"const PRODUCTS = \[(.*?)\];", tariff, re.S)
    limits = re.search(r"const LIMITS = \[(.*?)\];", tariff, re.S)
    if not products or not limits:
        raise UsageError("MOODLE_SOURCE_UNREADABLE: tariff PRODUCTS/LIMITS not found")
    product_keys = re.findall(r"'([a-z]+)' => \['title'", products.group(1))
    features = re.findall(r"'([a-z]+\.[a-z_]+)'", products.group(1))
    return {
        "cloud_status": _php_list(body("validate_status")),
        "entitlement_mode": modes,
        "erp_application_state": _php_list(body("validate_erp_state")),
        "integration_health": _php_list(body("validate_integration_health")),
        "snapshot_namespace_rule": "strpos($code, 'mgmt.') !== 0" in snap,
        "legacy_codes": product_keys + features + re.findall(r"'([a-z]+\.[a-z_]+)'", limits.group(1)),
    }


# --------------------------------------------------------------------------- contracts

def check_catalog(catalog, report, scope):
    namespaces = set(catalog.get("namespaces", []))
    defs, seen = {}, set()
    for index, item in enumerate(catalog.get("definitions", [])):
        path = "/definitions/%d" % index
        code = item.get("code", "")
        if code in seen:
            report.add("INV_DUPLICATE_CODE", scope, path, "duplicate entitlement code %s" % code)
        seen.add(code)
        defs[code] = item
        if code.split(".")[0] not in namespaces or not re.match(r"^[a-z]+(\.[a-z0-9_]+)+$", code):
            report.add("INV_NAMESPACE", scope, path, "code %s outside declared namespaces" % code)
        for field in ("owner", "layer", "type", "capability_class"):
            if not item.get(field):
                report.add("INV_CATALOG_FIELD", scope, path, "missing %s" % field)
        if item.get("provisioning_effect", "none") != "none" and not item.get("handler"):
            report.add("INV_UNKNOWN_HANDLER", scope, path, "provisioning code %s has no handler" % code)
        if item.get("status") == "deprecated" and not (item.get("replacement_code") and item.get("deprecated_in")):
            report.add("INV_DEPRECATION_METADATA", scope, path, "deprecated %s lacks migration metadata" % code)
    for index, item in enumerate(catalog.get("definitions", [])):
        for dep in item.get("depends_on", []):
            if dep not in defs:
                report.add("INV_UNKNOWN_CODE", scope, "/definitions/%d/depends_on" % index, "unknown dependency %s" % dep)
    graph = {c: [d for d in v.get("depends_on", []) if d in defs] for c, v in defs.items()}
    for node in _cycle_nodes(graph):
        report.add("INV_DEPENDENCY_CYCLE", scope, "/definitions", "dependency cycle through %s" % node)

    bundles, seen = {}, set()
    for index, bundle in enumerate(catalog.get("bundles", [])):
        code = bundle.get("code", "")
        if code in seen:
            report.add("INV_DUPLICATE_CODE", scope, "/bundles/%d" % index, "duplicate bundle %s" % code)
        seen.add(code)
        bundles[code] = bundle
        for item in bundle.get("items", []):
            if item not in defs:
                report.add("INV_UNKNOWN_CODE", scope, "/bundles/%d/items" % index, "unknown code %s" % item)
    for index, bundle in enumerate(catalog.get("bundles", [])):
        for parent in bundle.get("inherits", []):
            if parent not in bundles:
                report.add("INV_UNKNOWN_CODE", scope, "/bundles/%d/inherits" % index, "unknown bundle %s" % parent)
    graph = {c: [p for p in b.get("inherits", []) if p in bundles] for c, b in bundles.items()}
    for node in _cycle_nodes(graph):
        report.add("INV_BUNDLE_CYCLE", scope, "/bundles", "bundle inheritance cycle through %s" % node)

    mapped = {}
    for index, alias in enumerate(catalog.get("legacy_aliases", [])):
        path = "/legacy_aliases/%d" % index
        legacy, canonical = alias.get("legacy_code"), alias.get("canonical")
        if legacy in mapped:
            report.add("INV_LEGACY_MAPPING_AMBIGUOUS", scope, path, "legacy code %s mapped more than once" % legacy)
        mapped[legacy] = canonical
        if canonical is None:
            if alias.get("state") != "open":
                report.add("INV_LEGACY_MAPPING_AMBIGUOUS", scope, path, "%s has no target and is not marked open" % legacy)
        elif alias.get("kind") == "product":
            continue
        elif canonical not in defs:
            report.add("INV_UNKNOWN_CODE", scope, path, "alias target %s is not a catalog code" % canonical)
    return defs, bundles, mapped


def _cycle_nodes(graph):
    state, found = {}, set()

    def visit(node, stack):
        state[node] = 1
        stack.append(node)
        for nxt in graph.get(node, []):
            if state.get(nxt) == 1:
                found.update(stack[stack.index(nxt):])
            elif state.get(nxt) is None:
                visit(nxt, stack)
        stack.pop()
        state[node] = 2

    for node in sorted(graph):
        if state.get(node) is None:
            visit(node, [])
    return sorted(found)


def expand_bundle(code, bundles, seen=None):
    seen = set() if seen is None else seen
    if code in seen or code not in bundles:
        return set()
    seen.add(code)
    result = set(bundles[code].get("items", []))
    for parent in bundles[code].get("inherits", []):
        result |= expand_bundle(parent, bundles, seen)
    return result


def check_contracts(ctx, report):
    registry, schema, catalog, mapping = ctx["registry"], ctx["schema"], ctx["catalog"], ctx["mapping"]
    for path, key in schema.keyword_errors():
        report.add("SCHEMA_UNSUPPORTED_KEYWORD", "contract:schema", path, "keyword %s not supported by validator" % key)
    if schema.doc.get("$schema") != "https://json-schema.org/draft/2020-12/schema":
        report.add("SCHEMA_DIALECT", "contract:schema", "/$schema", "expected JSON Schema Draft 2020-12")

    enums = registry["enums"]
    for path, name, values in schema.tagged_enums("x-bms-enum"):
        if name not in enums:
            report.add("MODEL_ENUM_UNKNOWN", "contract:schema", path, "x-bms-enum %s not in registry" % name)
        elif values != enums[name]:
            report.add("MODEL_ENUM_DRIFT", "contract:schema", path, "schema enum differs from registry enum %s" % name)
    for name in enums:
        if name not in registry.get("enum_provenance", {}):
            report.add("MODEL_PROVENANCE_MISSING", "contract:registry", "/enum_provenance/" + name, "enum without provenance")
    for name, entity in registry["entities"].items():
        target = schema.doc["$defs"].get(name)
        if target is None:
            report.add("MODEL_ENTITY_MISSING", "contract:schema", "#/$defs/" + name, "registry entity not in schema")
        elif entity["id"] not in target.get("required", []):
            report.add("MODEL_ENTITY_ID", "contract:schema", "#/$defs/" + name, "entity id %s not required" % entity["id"])
    if len(registry["entities"]) != 9:
        report.add("MODEL_ENTITY_COUNT", "contract:registry", "/entities", "REG-002 MVP has nine entities")

    check_catalog(catalog, report, "contract:catalog")
    handlers = set(registry["provisioning_handlers"])
    for index, item in enumerate(catalog["definitions"]):
        if item.get("handler") and item["handler"] not in handlers:
            report.add("INV_UNKNOWN_HANDLER", "contract:catalog", "/definitions/%d/handler" % index, "handler not declared")

    moodle = ctx["moodle"]
    for field in ("cloud_status", "erp_application_state", "integration_health"):
        targets = {v for k, v in mapping[field].items()}
        targets |= set(mapping.get(field + "_local_only", []))
        for value in sorted(targets - set(moodle[field])):
            report.add("MAPPING_TARGET_UNKNOWN", "contract:mapping", "/" + field, "%s not accepted by Moodle" % value)
    for field in ("cloud_status",):
        missing = set(enums["workspace_status"]) - set(mapping[field])
        for value in sorted(missing):
            report.add("MAPPING_SOURCE_UNMAPPED", "contract:mapping", "/" + field, "registry value %s unmapped" % value)
    for value in sorted(set(enums["application_instance_state"]) - set(mapping["erp_application_state"])):
        report.add("MAPPING_SOURCE_UNMAPPED", "contract:mapping", "/erp_application_state", "registry value %s unmapped" % value)
    for value in sorted(set(enums["integration_connection_state"]) - set(mapping["integration_health"])):
        report.add("MAPPING_SOURCE_UNMAPPED", "contract:mapping", "/integration_health", "registry value %s unmapped" % value)
    if sorted(mapping["entitlement_mode"]["values"]) != sorted(moodle["entitlement_mode"]):
        report.add("MAPPING_TARGET_UNKNOWN", "contract:mapping", "/entitlement_mode", "modes differ from Moodle")
    for path, name, values in schema.tagged_enums("x-moodle-enum"):
        if sorted(values or []) != sorted(moodle.get(name, [])):
            report.add("MODEL_ENUM_DRIFT", "contract:schema", path, "projection enum %s differs from Moodle" % name)
    if not moodle["snapshot_namespace_rule"] or mapping["snapshot"]["accepted_namespace"] != "mgmt":
        report.add("MAPPING_SNAPSHOT_NAMESPACE", "contract:mapping", "/snapshot", "Moodle snapshot namespace rule mgmt.* not confirmed")
    aliased = {a["legacy_code"] for a in catalog["legacy_aliases"]}
    for code in sorted(set(moodle["legacy_codes"]) - aliased):
        report.add("INV_LEGACY_MAPPING_MISSING", "contract:catalog", "/legacy_aliases", "Moodle code %s has no mapping" % code)


# --------------------------------------------------------------------------- payload semantics

def check_payload(payload, ctx, report, scope, catalog=None):
    errors = ctx["schema"].validate(payload)
    for code, path, message in errors:
        report.add(code, scope, path, message)
    if errors:
        return
    registry = ctx["registry"]
    catalog = catalog or ctx["catalog"]
    sub = Report()
    defs, bundles, _ = check_catalog(catalog, sub, scope)
    report.items.extend(sub.items)
    state = payload["state"]
    entities = registry["entities"]
    index = {}

    for name, entity in entities.items():
        coll = entity["collection"]
        index[name] = {}
        for pos, record in enumerate(state[coll]):
            rid = record[entity["id"]]
            path = "/state/%s/%d" % (coll, pos)
            if rid in index[name]:
                report.add("INV_DUPLICATE_ID", scope, path, "duplicate %s %s" % (entity["id"], rid))
            index[name][rid] = (path, record)
        for fields in entity["unique"]:
            seen = {}
            for pos, record in enumerate(state[coll]):
                key = tuple(record.get(f) for f in fields)
                if key in seen and name != "provisioning_job":
                    report.add("INV_DUPLICATE_UNIQUE_KEY", scope, "/state/%s/%d" % (coll, pos), "duplicate %s" % "+".join(fields))
                seen[key] = pos

    def ref_ok(entity, value):
        return value in index.get(entity, {})

    for name, entity in entities.items():
        coll = entity["collection"]
        for field, target in entity["references"].items():
            for pos, record in enumerate(state[coll]):
                value = record.get(field)
                if value is not None and not ref_ok(target, value):
                    report.add("INV_DANGLING_REFERENCE", scope, "/state/%s/%d/%s" % (coll, pos, field), "unknown %s %s" % (target, value))

    workspaces = {k: v[1] for k, v in index["workspace"].items()}
    slugs = {}
    for wid, (path, ws) in index["workspace"].items():
        if ws["status"] != "closed":
            if ws["slug"] in slugs:
                report.add("INV_DUPLICATE_SLUG", scope, path + "/slug", "slug %s already used" % ws["slug"])
            slugs[ws["slug"]] = wid

    # links: primary rules and cross-workspace company ownership
    active_links = {}
    primary_owner = {}
    for lid, (path, link) in index["company_link"].items():
        if link["status"] != "active":
            continue
        active_links.setdefault(link["workspace_uuid"], set()).add(link["company_uuid"])
        if link["role"] == "primary":
            if link["company_uuid"] in primary_owner and primary_owner[link["company_uuid"]] != link["workspace_uuid"]:
                report.add("INV_CROSS_WORKSPACE_REFERENCE", scope, path, "company already primary in another workspace")
            primary_owner.setdefault(link["company_uuid"], link["workspace_uuid"])
    primaries = {}
    for lid, (path, link) in index["company_link"].items():
        if link["status"] == "active" and link["role"] == "primary":
            primaries.setdefault(link["workspace_uuid"], []).append(link["company_uuid"])
    for wid, (path, ws) in index["workspace"].items():
        count = len(primaries.get(wid, []))
        if count > 1 or (ws["status"] in NEEDS_PRIMARY and count != 1):
            report.add("INV_PRIMARY_LINK", scope, path, "workspace %s has %d active primary links" % (ws["status"], count))
        if ws["status"] in INACTIVE_WS:
            has_links = any(l["workspace_uuid"] == wid for _, l in index["company_link"].values())
            has_audit = any(a.get("workspace_uuid") == wid for _, a in index["audit_event"].values())
            if not has_links or not has_audit:
                report.add("INV_HISTORY_REQUIRED", scope, path, "inactive workspace lost links or audit history")

    def linked(wid, company):
        return company in active_links.get(wid, set())

    # application instances
    definitions = registry["application_definitions"]
    per_ws_app = {}
    provider_envs = {}
    for iid, (path, inst) in index["application_instance"].items():
        allowed = definitions[inst["application_code"]]["isolation_allowed"]
        if inst["isolation"] not in allowed:
            report.add("INV_ISOLATION_RULE", scope, path, "%s cannot be %s" % (inst["application_code"], inst["isolation"]))
        if inst["isolation"] == "shared" and inst["workspace_uuid"] is not None:
            report.add("INV_ISOLATION_RULE", scope, path + "/workspace_uuid", "shared instance must not belong to a workspace")
        if inst["isolation"] == "isolated":
            if inst["workspace_uuid"] is None:
                report.add("INV_ISOLATION_RULE", scope, path + "/workspace_uuid", "isolated instance needs a workspace")
            elif not ref_ok("workspace", inst["workspace_uuid"]):
                report.add("INV_DANGLING_REFERENCE", scope, path + "/workspace_uuid", "unknown workspace")
            env = inst["provider_environment_id"]
            if env in provider_envs:
                report.add("INV_DUPLICATE_PROVIDER_ENV", scope, path + "/provider_environment_id", "provider environment reused")
            provider_envs[env] = iid
            if inst["state"] != "retired":
                key = (inst["workspace_uuid"], inst["application_code"])
                if key in per_ws_app:
                    report.add("INV_DUPLICATE_INSTANCE", scope, path, "second live %s instance" % inst["application_code"])
                per_ws_app[key] = iid
        company = inst.get("company_uuid")
        if company and inst["workspace_uuid"] and not linked(inst["workspace_uuid"], company):
            report.add("INV_CROSS_WORKSPACE_REFERENCE", scope, path + "/company_uuid", "company not linked to workspace")
        if inst["application_code"] == "erp" and inst["state"] != "draft":
            wid = inst["workspace_uuid"]
            prim = primaries.get(wid, [])
            if len(prim) != 1 or company != prim[0]:
                report.add("INV_ERP_REQUIRES_COMPANY_LINK", scope, path, "ERP beyond draft needs the active primary company link")

    # connections
    for cid, (path, conn) in index["integration_connection"].items():
        if not linked(conn["workspace_uuid"], conn["company_uuid"]):
            report.add("INV_CROSS_WORKSPACE_REFERENCE", scope, path + "/company_uuid", "company not linked to workspace")

    # subscriptions and provenance
    audit_targets = {}
    for aid, (path, event) in index["audit_event"].items():
        audit_targets.setdefault(event["target_id"], []).append(event)
    for sid, (path, subs) in index["subscription"].items():
        prov = subs.get("provenance")
        if prov is None:
            report.add("INV_PROVENANCE_MISSING", scope, path, "subscription reference without provenance")
        else:
            event = index["audit_event"].get(prov["audit_uuid"])
            if event is None or event[1]["target_id"] != sid:
                report.add("INV_PROVENANCE_MISSING", scope, path + "/provenance/audit_uuid", "provenance audit event missing or not about this subscription")
            if subs["commercial_provider"] == "manual_beta" and prov["source_type"] != "manual_registration":
                report.add("INV_PROVENANCE_MISSING", scope, path + "/provenance/source_type", "manual_beta requires manual_registration")
        if subs["bundle_code"] not in bundles:
            report.add("INV_UNKNOWN_CODE", scope, path + "/bundle_code", "unknown bundle %s" % subs["bundle_code"])

    # assignments
    for aid, (path, asg) in index["entitlement_assignment"].items():
        has_code, has_bundle = "entitlement_code" in asg, "bundle_code" in asg
        if has_code == has_bundle:
            report.add("INV_ASSIGNMENT_TARGET", scope, path, "exactly one of entitlement_code or bundle_code")
        if has_code and asg["entitlement_code"] not in defs:
            report.add("INV_UNKNOWN_CODE", scope, path + "/entitlement_code", "unknown code (fail-closed)")
        if has_bundle and asg["bundle_code"] not in bundles:
            report.add("INV_UNKNOWN_CODE", scope, path + "/bundle_code", "unknown bundle (fail-closed)")
        if asg["source_type"] in ("manual_admin_grant", "support_override") and not asg.get("reason"):
            report.add("INV_MANUAL_GRANT_REASON", scope, path, "manual grant without reason")
        if asg["source_type"] == "commercial_subscription":
            ref = index["subscription"].get(asg["source_reference"])
            if ref is None:
                report.add("INV_DANGLING_REFERENCE", scope, path + "/source_reference", "unknown subscription")
            elif ref[1]["workspace_uuid"] != asg["workspace_uuid"]:
                report.add("INV_CROSS_WORKSPACE_REFERENCE", scope, path + "/source_reference", "subscription of another workspace")

    effective = {}
    for wid, ws in workspaces.items():
        codes = set()
        if ws["status"] not in INACTIVE_WS:
            for _, asg in index["entitlement_assignment"].values():
                if asg["workspace_uuid"] != wid or asg["state"] != "enabled":
                    continue
                if asg["effective_from"] > payload["as_of"] or asg.get("effective_to", "9999") <= payload["as_of"]:
                    continue
                if "bundle_code" in asg:
                    codes |= expand_bundle(asg["bundle_code"], bundles)
                elif "entitlement_code" in asg:
                    codes.add(asg["entitlement_code"])
        codes &= set(defs)
        effective[wid] = codes
        for code in sorted(codes):
            for dep in defs[code].get("depends_on", []):
                if dep not in codes:
                    report.add("INV_DEPENDENCY_MISSING", scope, index["workspace"][wid][0], "%s requires %s" % (code, dep))

    # provisioning jobs, idempotency and closed workspaces
    handlers = set(registry["provisioning_handlers"])
    scopes = {}
    for jid, (path, job) in index["provisioning_job"].items():
        if job["handler"] not in handlers:
            report.add("INV_UNKNOWN_HANDLER", scope, path + "/handler", "undeclared handler %s" % job["handler"])
        target_entity = job["target_type"]
        if not ref_ok(target_entity, job["target_id"]):
            report.add("INV_DANGLING_REFERENCE", scope, path + "/target_id", "unknown %s" % target_entity)
        key = (job["workspace_uuid"], job["operation"], job["idempotency_key"])
        scopes.setdefault(key, []).append((path, job))
        ws = workspaces.get(job["workspace_uuid"])
        if ws and ws.get("closed_at") and job["created_at"] > ws["closed_at"]:
            reopened = ws.get("reactivated_at")
            reaudited = any(e["action"] == "cloud.workspace.reactivated" and e["target_id"] == job["workspace_uuid"]
                            for e in audit_targets.get(job["workspace_uuid"], []))
            if ws["status"] == "closed" or not reopened or job["created_at"] < reopened or not reaudited:
                report.add("INV_CLOSED_WORKSPACE_JOB", scope, path, "job created after closure without audited reactivation")
    for key, jobs in scopes.items():
        if len(jobs) > 1:
            hashes = {j["request_hash"] for _, j in jobs}
            code = "INV_IDEMPOTENCY_CONFLICT" if len(hashes) > 1 else "INV_IDEMPOTENT_DUPLICATE_RESULT"
            for path, _ in jobs[1:]:
                report.add(code, scope, path, "idempotency scope %s/%s/%s repeated" % key)
    deliveries = {}
    for pos, event in enumerate(payload.get("commercial_events", [])):
        key = (event["workspace_uuid"], event["operation"], event["idempotency_key"])
        deliveries.setdefault(key, []).append(("/commercial_events/%d" % pos, event))
        entity = event["result_type"]
        if not ref_ok(entity, event["result_uuid"]):
            report.add("INV_DANGLING_REFERENCE", scope, "/commercial_events/%d/result_uuid" % pos, "unknown result %s" % entity)
    for key, events in deliveries.items():
        if len({e["request_hash"] for _, e in events}) > 1:
            for path, _ in events[1:]:
                report.add("INV_IDEMPOTENCY_CONFLICT", scope, path, "same idempotency key, different content")
        elif len({e["result_uuid"] for _, e in events}) > 1:
            for path, _ in events[1:]:
                report.add("INV_IDEMPOTENT_DUPLICATE_RESULT", scope, path, "replay produced a second result")

    # audit coverage and audit workspace scope
    for name, entity in entities.items():
        if name == "audit_event":
            continue
        for rid, (path, record) in index[name].items():
            if rid not in audit_targets:
                report.add("INV_AUDIT_MISSING", scope, path, "no audit event for %s" % rid)
    owner_ws = {}
    for name, entity in entities.items():
        for rid, (_, record) in index[name].items():
            if name == "workspace":
                owner_ws[rid] = rid
            elif record.get("workspace_uuid"):
                owner_ws[rid] = record["workspace_uuid"]
    for aid, (path, event) in index["audit_event"].items():
        if event["target_id"] not in owner_ws and not any(event["target_id"] in index[n] for n in index):
            report.add("INV_DANGLING_REFERENCE", scope, path + "/target_id", "audit target unknown")
        ws_of_target = owner_ws.get(event["target_id"])
        if event.get("workspace_uuid") and ws_of_target and event["workspace_uuid"] != ws_of_target:
            report.add("INV_CROSS_WORKSPACE_REFERENCE", scope, path + "/workspace_uuid", "audit event scoped to another workspace")

    # mutations: immutability and soft delete
    for pos, mutation in enumerate(payload.get("mutations", [])):
        path = "/mutations/%d" % pos
        entity = entities[mutation["entity"]]
        if mutation["id"] not in index[mutation["entity"]]:
            report.add("INV_DANGLING_REFERENCE", scope, path + "/id", "mutation target unknown")
        if mutation["op"] == "delete":
            report.add("INV_SOFT_DELETE_ONLY", scope, path, "registry records are archived, not deleted")
            continue
        if entity.get("append_only"):
            report.add("INV_AUDIT_APPEND_ONLY", scope, path, "audit events are append-only")
            continue
        for field in sorted(mutation.get("set", {})):
            if field in entity["immutable"]:
                report.add("INV_IMMUTABLE_FIELD", scope, "%s/set/%s" % (path, field), "%s is immutable" % field)

    # Moodle projections (cloudmap) and snapshots
    mapping = ctx["mapping"]
    for pos, proj in enumerate(payload.get("moodle_projections", [])):
        path = "/moodle_projections/%d" % pos
        wid = proj["workspace_uuid"]
        ws = workspaces.get(wid)
        prim = primaries.get(wid, [])
        if ws is None or prim != [proj["company_uuid"]]:
            report.add("INV_PROJECTION_MISMATCH", scope, path, "cloudmap binding does not match the active primary link")
            continue
        if proj["cloud_status"] != mapping["cloud_status"][ws["status"]]:
            report.add("INV_PROJECTION_MISMATCH", scope, path + "/cloud_status", "expected %s" % mapping["cloud_status"][ws["status"]])
        runtime = index["application_instance"].get(proj["runtime_instance_uuid"])
        if runtime is None or runtime[1]["application_code"] != "mgmt" or runtime[1]["workspace_uuid"] not in (None, wid):
            report.add("INV_PROJECTION_MISMATCH", scope, path + "/runtime_instance_uuid", "runtime must be the shared or own mgmt instance")
        erp = [i for _, i in index["application_instance"].values() if i["application_code"] == "erp" and i["workspace_uuid"] == wid and i["state"] != "retired"]
        expected_erp = mapping["erp_application_state"][erp[0]["state"]] if erp else mapping["erp_application_state"]["_none"]
        if proj["erp_application_state"] != expected_erp:
            report.add("INV_PROJECTION_MISMATCH", scope, path + "/erp_application_state", "expected %s" % expected_erp)
    for pos, snap in enumerate(payload.get("entitlement_snapshots", [])):
        path = "/entitlement_snapshots/%d" % pos
        wid = snap["workspace_uuid"]
        if primaries.get(wid, []) != [snap["company_uuid"]]:
            report.add("INV_CROSS_WORKSPACE_REFERENCE", scope, path + "/company_uuid", "snapshot company is not the workspace primary company")
        codes = snap["codes"]
        if any(not c.startswith("mgmt.") for c in codes):
            report.add("INV_SNAPSHOT_MISMATCH", scope, path + "/codes", "Moodle snapshot v1 accepts only mgmt.* codes")
        expected = sorted(c for c in effective.get(wid, set()) if c.startswith("mgmt."))
        if sorted(set(c for c in codes if c.startswith("mgmt."))) != expected:
            report.add("INV_SNAPSHOT_MISMATCH", scope, path + "/codes", "snapshot differs from effective set %s" % expected)
        if snap["catalog_version"] != catalog["catalog_version"]:
            report.add("INV_SNAPSHOT_MISMATCH", scope, path + "/catalog_version", "catalog version mismatch")

    _scan_secrets(payload, "", report, scope)


def _scan_secrets(node, path, report, scope):
    if isinstance(node, dict):
        for key, value in node.items():
            sub = "%s/%s" % (path, key)
            if key != "credential_reference" and SECRET_KEY.search(key) and value not in (None, ""):
                report.add("INV_RAW_SECRET", scope, sub, "secret-like field %s" % key)
            _scan_secrets(value, sub, report, scope)
    elif isinstance(node, list):
        for index, value in enumerate(node):
            _scan_secrets(value, "%s/%d" % (path, index), report, scope)
    elif isinstance(node, str) and SECRET_VALUE.search(node):
        report.add("INV_RAW_SECRET", scope, path, "private key material")


# --------------------------------------------------------------------------- fixtures

def _pointer(path):
    if not path.startswith("/"):
        raise UsageError("patch path must start with /: %s" % path)
    return [p.replace("~1", "/").replace("~0", "~") for p in path[1:].split("/")]


def apply_patch(doc, ops):
    doc = copy.deepcopy(doc)
    for op in ops:
        parts = _pointer(op["path"])
        parent = doc
        for part in parts[:-1]:
            parent = parent[int(part)] if isinstance(parent, list) else parent[part]
        last = parts[-1]
        kind = op["op"]
        if isinstance(parent, list):
            pos = len(parent) if last == "-" else int(last)
            if kind == "add":
                parent.insert(pos, op["value"])
            elif kind == "replace":
                parent[pos] = op["value"]
            elif kind == "remove":
                del parent[pos]
            else:
                raise UsageError("unsupported patch op %s" % kind)
        else:
            if kind in ("add", "replace"):
                if kind == "replace" and last not in parent:
                    raise UsageError("replace of missing key %s" % op["path"])
                parent[last] = op["value"]
            elif kind == "remove":
                del parent[last]
            else:
                raise UsageError("unsupported patch op %s" % kind)
    return doc


def load_fixtures(root):
    fixtures = {}
    for sub in ("positive", "negative"):
        folder = os.path.join(root, sub)
        if not os.path.isdir(folder):
            raise UsageError("fixture folder missing: %s" % folder)
        for name in sorted(os.listdir(folder)):
            if name.endswith(".json"):
                data = load_json(os.path.join(folder, name))
                for key in ("fixture_id", "fixture_version", "description", "expect"):
                    if key not in data:
                        raise UsageError("fixture %s lacks %s" % (name, key))
                if data["fixture_id"] in fixtures:
                    raise UsageError("duplicate fixture_id %s" % data["fixture_id"])
                data["_kind"] = sub
                fixtures[data["fixture_id"]] = data
    def resolve(fid, chain):
        data = fixtures[fid]
        if "payload" not in data:
            base_id = data.get("base", "")
            if base_id not in fixtures or base_id in chain:
                raise UsageError("fixture %s has no payload and no valid base" % fid)
            base = resolve(base_id, chain + [fid])
            data["payload"] = apply_patch(base["payload"], data.get("patch", []))
        return data

    for fid in sorted(fixtures):
        resolve(fid, [])
        if "catalog_patch" in fixtures[fid]:
            fixtures[fid]["catalog"] = fixtures[fid]["catalog_patch"]
    return fixtures


def run_fixtures(ctx, fixtures, report):
    results = []
    for fid in sorted(fixtures):
        data = fixtures[fid]
        scope = "fixture:" + fid
        sub = Report()
        catalog = apply_patch(ctx["catalog"], data["catalog"]) if "catalog" in data else None
        check_payload(data["payload"], ctx, sub, scope, catalog)
        got = sub.codes()
        expect = data["expect"]
        want = sorted(expect.get("violations", []))
        ok = (not got) if expect.get("valid") else (got == want)
        if data["_kind"] == "positive" and not expect.get("valid"):
            raise UsageError("positive fixture %s must expect valid" % fid)
        if data["_kind"] == "negative" and (expect.get("valid") or not want):
            raise UsageError("negative fixture %s must list expected violations" % fid)
        results.append({"fixture_id": fid, "kind": data["_kind"], "expected": want, "actual": got, "pass": ok})
        if not ok:
            report.add("FIXTURE_EXPECTATION", scope, "/expect", "expected %s, got %s" % (want or "valid", got or "valid"))
            if data["_kind"] == "positive":
                report.items.extend(sub.items)
    return results


# --------------------------------------------------------------------------- REG-002 package

def verify_reg002(zip_path, manifest, extract_to=None):
    if not os.path.isfile(zip_path):
        raise UsageError("ZIP not found: %s" % zip_path)
    lines, ok = [], True
    package = manifest["package"]
    actual = sha256_file(zip_path)
    size = os.path.getsize(zip_path)
    if actual != package["sha256"]:
        ok = False
        lines.append("FAIL zip sha256 %s != %s" % (actual, package["sha256"]))
    else:
        lines.append("PASS zip sha256 %s" % actual)
    if size != package["size_bytes"]:
        ok = False
        lines.append("FAIL zip size %d != %d" % (size, package["size_bytes"]))
    try:
        archive = zipfile.ZipFile(zip_path)
    except zipfile.BadZipFile as exc:
        raise UsageError("not a ZIP: %s" % exc)
    with archive:
        names = [n for n in archive.namelist() if not n.endswith("/")]
        selected = {}
        for member in manifest["selected_members"]:
            hits = [n for n in names if os.path.basename(n) == member["name"]]
            if len(hits) != 1:
                ok = False
                lines.append("FAIL member %s found %d times" % (member["name"], len(hits)))
                continue
            data = archive.read(hits[0])
            digest = hashlib.sha256(data).hexdigest()
            if digest != member["sha256"]:
                ok = False
                lines.append("FAIL member %s sha256 %s != %s" % (member["name"], digest, member["sha256"]))
            else:
                lines.append("PASS member %s sha256 %s" % (member["name"], digest))
                selected[member["name"]] = data
        if ok and extract_to:
            os.makedirs(extract_to, exist_ok=True)
            for name, data in sorted(selected.items()):
                with open(os.path.join(extract_to, name), "wb") as handle:
                    handle.write(data)
            lines.append("EXTRACTED %d members to %s" % (len(selected), extract_to))
        elif extract_to:
            lines.append("NOT EXTRACTED: verification failed")
    return ok, lines


# --------------------------------------------------------------------------- cli

def build_context(repo_root):
    ctx = {
        "registry": load_json(REGISTRY),
        "schema": Schema(load_json(SCHEMA)),
        "catalog": load_json(CATALOG),
        "mapping": load_json(MAPPING),
        "manifest": load_json(MANIFEST),
    }
    ctx["moodle"] = read_moodle_sources(ctx["mapping"], repo_root)
    return ctx


def main(argv=None):
    parser = argparse.ArgumentParser(description="BMS Cloud CLOUD-1a offline contract validator")
    parser.add_argument("--repo-root", default=REPO_ROOT)
    parser.add_argument("--json", action="store_true", help="machine-readable output")
    sub = parser.add_subparsers(dest="command")
    p_val = sub.add_parser("validate", help="validate contracts and the fixture suite")
    p_val.add_argument("--fixtures", default=FIXTURES)
    p_chk = sub.add_parser("check", help="validate one exchange payload")
    p_chk.add_argument("payload")
    p_zip = sub.add_parser("verify-reg002", help="verify the REG-002 ZIP and selected members")
    p_zip.add_argument("--zip", required=True)
    p_zip.add_argument("--extract")
    try:
        args = parser.parse_args(argv)
    except SystemExit as exc:
        return EXIT_USAGE if exc.code else EXIT_OK
    if not args.command:
        parser.print_usage(sys.stderr)
        return EXIT_USAGE
    try:
        if args.command == "verify-reg002":
            ok, lines = verify_reg002(args.zip, load_json(MANIFEST), args.extract)
            print("\n".join(lines))
            return EXIT_OK if ok else EXIT_VIOLATIONS
        ctx = build_context(args.repo_root)
        report = Report()
        results = []
        if args.command == "validate":
            check_contracts(ctx, report)
            results = run_fixtures(ctx, load_fixtures(args.fixtures), report)
        else:
            check_payload(load_json(args.payload), ctx, report, "payload:" + os.path.basename(args.payload))
    except UsageError as exc:
        print("USAGE_ERROR %s" % exc, file=sys.stderr)
        return EXIT_USAGE
    code = EXIT_VIOLATIONS if report.items else EXIT_OK
    if args.json:
        print(json.dumps({"validator_version": VALIDATOR_VERSION, "exit_code": code,
                          "violations": [dict(zip(("code", "scope", "path", "message"), i)) for i in sorted(set(report.items))],
                          "fixtures": results}, ensure_ascii=False, indent=2, sort_keys=True))
    else:
        for row in results:
            print("%s %s %s" % ("PASS" if row["pass"] else "FAIL", row["kind"], row["fixture_id"]))
        for line in report.lines():
            print(line)
        print("RESULT %s (validator %s, %d fixtures, %d violations)" % (
            "OK" if code == EXIT_OK else "VIOLATIONS", VALIDATOR_VERSION, len(results), len(set(report.items))))
    return code


if __name__ == "__main__":
    sys.exit(main())

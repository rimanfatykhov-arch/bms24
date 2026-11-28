#!/usr/bin/env python3
"""BMS Cloud CLOUD-1a offline contract validator (v0.1.1, PROPOSED).

Standard library only, no network. Validates the cloud/ contracts against the
imported REG-002 package, runs the synthetic fixture suite and verifies the
REG-002 schema package before import.

Exit codes: 0 = input valid, 1 = contract violations, 2 = bad invocation or
unreadable input.

This tool proves contract consistency only. It is not a runtime, restore (G3)
or application ACL test and must not be reported as one.
"""
import argparse
import copy
import datetime
import hashlib
import json
import os
import re
import sys
import zipfile

VALIDATOR_VERSION = "0.1.1"
EXIT_OK, EXIT_VIOLATIONS, EXIT_USAGE = 0, 1, 2

CLOUD_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPO_ROOT = os.path.dirname(CLOUD_DIR)
REG_DIR = os.path.join(CLOUD_DIR, "contracts", "registry", "v0.1")
IMPORTED = os.path.join(REG_DIR, "imported")
OVERLAY = os.path.join(REG_DIR, "registry_overlay.json")
SCHEMA = os.path.join(REG_DIR, "exchange.schema.json")
RECONCILIATION = os.path.join(REG_DIR, "reg002_reconciliation.json")
PKG_MODEL = os.path.join(IMPORTED, "bms_cloud_registry_mvp_v0.1.json")
PKG_SCHEMA = os.path.join(IMPORTED, "bms_cloud_registry_mvp_exchange_v0.1.schema.json")
CATALOG = os.path.join(CLOUD_DIR, "contracts", "entitlements", "v0.1", "catalog.json")
MAPPING = os.path.join(CLOUD_DIR, "contracts", "mapping", "v0.1", "projection_mapping.json")
MANIFEST = os.path.join(CLOUD_DIR, "contracts", "provenance", "reg002_package_manifest.json")
FIXTURES = os.path.join(CLOUD_DIR, "fixtures", "v0.1")
DIALECT = "https://json-schema.org/draft/2020-12/schema"

SUPPORTED_SCHEMA_KEYWORDS = {
    "$schema", "$id", "$defs", "$ref", "title", "description", "type", "properties",
    "required", "additionalProperties", "items", "enum", "const", "pattern", "minLength",
    "minimum", "maximum", "anyOf", "format",
    "x-bms-vocabulary", "x-moodle-enum", "x-bms-external-schema",
}
FORMATS = {
    "uuid": re.compile(r"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"),
    "date-time": re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$"),
    "uri": re.compile(r"^[A-Za-z][A-Za-z0-9+.-]*:\S+$"),
}
SECRET_KEY = re.compile(r"(passw(or)?d|secret|token|api[_-]?key|private[_-]?key)", re.I)
SECRET_VALUE = re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----")
SECRETREF = re.compile(r"^secretref://[a-z0-9][a-z0-9/_.-]*$")
INACTIVE_WS = {"suspended", "closing", "closed"}
NEEDS_PRIMARY = {"provisioning", "active", "degraded", "suspended", "closing"}
RESOLUTIONS = {"aligned_to_package", "moved_to_extension", "derived", "cloud1a_extension", "open_clarification"}


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


def ts(value):
    """Parse an RFC 3339 timestamp (already format-checked) to an aware datetime."""
    match = re.match(r"^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(Z|([+-])(\d{2}):(\d{2}))$", value)
    year, month, day, hour, minute, second = (int(match.group(i)) for i in range(1, 7))
    offset = datetime.timedelta(0)
    if match.group(7) != "Z":
        offset = datetime.timedelta(hours=int(match.group(9)), minutes=int(match.group(10)))
        if match.group(8) == "-":
            offset = -offset
    return datetime.datetime(year, month, day, hour, minute, second, tzinfo=datetime.timezone(offset))


# --------------------------------------------------------------------------- schema

class Schema:
    """JSON Schema 2020-12 subset; unsupported keywords and formats fail closed."""

    def __init__(self, doc, base_dir=None):
        self.doc = doc
        self.externals = {}
        for name in self._external_names():
            path = os.path.join(base_dir or REG_DIR, name)
            self.externals[name] = Schema(load_json(path), os.path.dirname(path))

    def _external_names(self):
        names = []

        def walk(node):
            if isinstance(node, dict):
                if "x-bms-external-schema" in node:
                    names.append(node["x-bms-external-schema"])
                for value in node.values():
                    walk(value)
            elif isinstance(node, list):
                for value in node:
                    walk(value)

        walk(self.doc)
        return names

    def keyword_errors(self):
        errors = []

        def walk(node, path):
            if not isinstance(node, dict):
                return
            for key, value in node.items():
                if key not in SUPPORTED_SCHEMA_KEYWORDS:
                    errors.append((path, key))
                if key == "format" and value not in FORMATS:
                    errors.append((path, "format:%s" % value))
                if key in ("properties", "$defs"):
                    for name, sub in value.items():
                        walk(sub, "%s/%s/%s" % (path, key, name))
                elif key in ("items", "additionalProperties") and isinstance(value, dict):
                    walk(value, "%s/%s" % (path, key))
                elif key == "anyOf":
                    for index, sub in enumerate(value):
                        walk(sub, "%s/anyOf/%d" % (path, index))

        walk(self.doc, "#")
        for name, external in sorted(self.externals.items()):
            errors.extend(("%s#%s" % (name, p[1:]), k) for p, k in external.keyword_errors())
        return errors

    def resolve(self, ref):
        if not ref.startswith("#/$defs/"):
            raise UsageError("unsupported $ref %s" % ref)
        return self.doc["$defs"][ref[len("#/$defs/"):]]

    def validate(self, value, node=None, path=""):
        node = self.doc if node is None else node
        if "x-bms-external-schema" in node:
            return self.externals[node["x-bms-external-schema"]].validate(value, None, path)
        if "$ref" in node:
            return self.validate(value, self.resolve(node["$ref"]), path)
        where = path or "/"
        if "anyOf" in node:
            if not any(not self.validate(value, sub, path) for sub in node["anyOf"]):
                return [("SCHEMA_ANY_OF", where, "matches no alternative")]
        out = []
        expected = node.get("type")
        if expected is not None:
            types = expected if isinstance(expected, list) else [expected]
            if not any(_is_type(value, t) for t in types):
                return [("SCHEMA_TYPE", where, "expected %s" % "|".join(types))]
        if "const" in node and value != node["const"]:
            out.append(("SCHEMA_CONST", where, "expected %r" % node["const"]))
        if "enum" in node and value not in node["enum"]:
            out.append(("SCHEMA_ENUM", where, "%r not in %s" % (value, node["enum"])))
        if isinstance(value, str):
            if "pattern" in node and not re.search(node["pattern"], value):
                out.append(("SCHEMA_PATTERN", where, "%r does not match %s" % (value, node["pattern"])))
            if "minLength" in node and len(value) < node["minLength"]:
                out.append(("SCHEMA_MIN_LENGTH", where, "too short"))
            if "format" in node and not FORMATS[node["format"]].match(value):
                out.append(("SCHEMA_FORMAT", where, "%r is not %s" % (value, node["format"])))
        if _is_type(value, "number"):
            if "minimum" in node and value < node["minimum"]:
                out.append(("SCHEMA_MINIMUM", where, "below %s" % node["minimum"]))
            if "maximum" in node and value > node["maximum"]:
                out.append(("SCHEMA_MAXIMUM", where, "above %s" % node["maximum"]))
        if isinstance(value, dict):
            props = node.get("properties", {})
            extra = node.get("additionalProperties")
            for name in node.get("required", []):
                if name not in value:
                    out.append(("SCHEMA_REQUIRED", "%s/%s" % (path, name), "missing required property"))
            for name, sub in value.items():
                sub_path = "%s/%s" % (path, name)
                if name in props:
                    out.extend(self.validate(sub, props[name], sub_path))
                elif extra is False:
                    out.append(("SCHEMA_ADDITIONAL_PROPERTY", sub_path, "property not allowed"))
                elif isinstance(extra, dict):
                    out.extend(self.validate(sub, extra, sub_path))
        if isinstance(value, list) and "items" in node:
            for index, item in enumerate(value):
                out.extend(self.validate(item, node["items"], "%s/%d" % (path, index)))
        return out

    def tagged(self, tag):
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
    if name == "number":
        return isinstance(value, (int, float)) and not isinstance(value, bool)
    if name == "null":
        return value is None
    if name == "boolean":
        return isinstance(value, bool)
    return False


# --------------------------------------------------------------------------- REG-002 package model

class Model:
    """Entity metadata derived from the imported REG-002 package plus the CLOUD-1a overlay."""

    def __init__(self, package, package_schema, overlay):
        self.package = package
        self.overlay = overlay
        self.enums = package["enums"]
        by_entity = {v["reg002_entity"]: k for k, v in overlay["entities"].items()}
        collections = {}
        for coll, node in package_schema.get("properties", {}).items():
            ref = node.get("items", {}).get("$ref", "")
            if ref:
                name = ref.split("/")[-1]
                collections[name[0].upper() + name[1:]] = coll
        self.entities = {}
        for short, info in overlay["entities"].items():
            pkg = package["entities"].get(info["reg002_entity"])
            if pkg is None:
                continue
            refs = {}
            for fk in pkg.get("foreign_keys", []):
                refs[fk["field"]] = by_entity.get(fk["entity"])
            immutable = [f for f, d in pkg["fields"].items() if d.get("immutable")]
            immutable += [f for f in info.get("immutable_extension", []) if f != "*"]
            self.entities[short] = {
                "reg002_entity": info["reg002_entity"],
                "collection": collections.get(info["reg002_entity"]),
                "id": pkg["primary_key"],
                "unique": pkg.get("unique_constraints", []),
                "references": refs,
                "immutable": sorted(set(immutable)),
                "append_only": bool(info.get("append_only")),
                "fields": pkg["fields"],
            }


def check_package(ctx, report):
    """Package integrity and internal consistency (YAML equality is checked by the tests with PyYAML)."""
    scope = "contract:reg002"
    manifest = ctx["manifest"]
    for member in manifest["selected_members"]:
        path = os.path.join(REPO_ROOT, member["import_target"])
        if not os.path.isfile(path):
            raise UsageError("REG-002 member missing: %s" % member["import_target"])
        if sha256_file(path) != member["sha256"]:
            report.add("PKG_HASH_MISMATCH", scope, member["import_target"], "imported bytes differ from the verified package")
    package, schema = ctx["package"], ctx["package_schema"]
    if schema.get("$schema") != DIALECT:
        report.add("SCHEMA_DIALECT", scope, "/$schema", "package schema is not Draft 2020-12")
    for coll, node in schema.get("properties", {}).items():
        ref = node.get("items", {}).get("$ref")
        if not ref:
            continue
        def_name = ref.split("/")[-1]
        entity = package["entities"].get(def_name[0].upper() + def_name[1:])
        sdef = schema["$defs"][def_name]
        path = "/entities/" + def_name
        if entity is None:
            report.add("PKG_MODEL_DRIFT", scope, path, "schema collection %s has no logical entity" % coll)
            continue
        if set(entity["fields"]) != set(sdef["properties"]):
            report.add("PKG_MODEL_DRIFT", scope, path, "field sets differ between model and schema")
        required = sorted(f for f, d in entity["fields"].items() if d.get("required"))
        if required != sorted(sdef.get("required", [])):
            report.add("PKG_MODEL_DRIFT", scope, path, "required fields differ between model and schema")
        for field, spec in entity["fields"].items():
            prop = sdef["properties"].get(field, {})
            if spec.get("enum") and prop.get("enum") != package["enums"].get(spec["enum"]):
                report.add("PKG_MODEL_DRIFT", scope, "%s/%s" % (path, field), "enum differs between model and schema")
            if spec.get("pattern") and prop.get("pattern") != spec["pattern"]:
                report.add("PKG_MODEL_DRIFT", scope, "%s/%s" % (path, field), "pattern differs between model and schema")


def check_reconciliation(ctx, report):
    scope = "contract:reconciliation"
    recon = ctx["reconciliation"]
    manifest = ctx["manifest"]
    pinned = {m["name"]: m["sha256"] for m in manifest["selected_members"]}
    if recon["package"]["sha256"] != manifest["package"]["sha256"] or recon["package"]["members"] != pinned:
        report.add("RECON_PROVENANCE", scope, "/package", "reconciliation does not pin the manifest hashes")
    extensions = {x["id"] for x in recon["extensions"]}
    for index, diff in enumerate(recon["transcription_differences"]):
        path = "/transcription_differences/%d" % index
        if diff.get("resolution") not in RESOLUTIONS:
            report.add("RECON_RESOLUTION", scope, path, "unknown resolution %r" % diff.get("resolution"))
        if diff.get("resolution") in ("moved_to_extension", "cloud1a_extension") and diff.get("extension") not in extensions:
            report.add("RECON_RESOLUTION", scope, path, "extension id %r not declared" % diff.get("extension"))
    clar = {c["id"] for c in ctx["overlay"]["clarifications"]}
    for index, item in enumerate(recon["extensions"]):
        for ref in item.get("clarifications", []):
            if ref not in clar:
                report.add("RECON_RESOLUTION", scope, "/extensions/%d" % index, "unknown clarification %s" % ref)


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
    overlay, schema, catalog, mapping, model = ctx["overlay"], ctx["schema"], ctx["catalog"], ctx["mapping"], ctx["model"]
    for path, key in schema.keyword_errors():
        report.add("SCHEMA_UNSUPPORTED_KEYWORD", "contract:schema", path, "keyword %s not supported by validator" % key)
    if schema.doc.get("$schema") != DIALECT:
        report.add("SCHEMA_DIALECT", "contract:schema", "/$schema", "expected JSON Schema Draft 2020-12")
    check_package(ctx, report)
    check_reconciliation(ctx, report)

    package_entities = set(ctx["package"]["entities"])
    mapped = [v["reg002_entity"] for v in overlay["entities"].values()]
    if sorted(mapped) != sorted(package_entities) or len(mapped) != 9:
        report.add("MODEL_ENTITY_MAPPING", "contract:overlay", "/entities", "overlay must map the nine package entities exactly once")
    for short, info in model.entities.items():
        if info["collection"] is None:
            report.add("MODEL_ENTITY_MAPPING", "contract:overlay", "/entities/" + short, "no package collection")
        for field in overlay["entities"].get(short, {}).get("immutable_extension", []):
            if field != "*" and field not in info["fields"]:
                report.add("MODEL_FIELD_UNKNOWN", "contract:overlay", "/entities/%s/immutable_extension" % short, "%s not a package field" % field)
        for field, target in info["references"].items():
            if target is None:
                report.add("MODEL_ENTITY_MAPPING", "contract:overlay", "/entities/" + short, "foreign key %s targets an unmapped entity" % field)
    vocab = overlay["vocabularies"]
    for path, name, values in schema.tagged("x-bms-vocabulary"):
        if name not in vocab:
            report.add("MODEL_ENUM_UNKNOWN", "contract:schema", path, "vocabulary %s not in overlay" % name)
        elif values != vocab[name]:
            report.add("MODEL_ENUM_DRIFT", "contract:schema", path, "schema enum differs from overlay vocabulary %s" % name)
    for name in vocab:
        if name not in overlay.get("vocabulary_provenance", {}):
            report.add("MODEL_PROVENANCE_MISSING", "contract:overlay", "/vocabulary_provenance/" + name, "vocabulary without provenance")
    for code in overlay["application_definitions"]:
        if code not in model.enums["application_code"]:
            report.add("MODEL_ENUM_DRIFT", "contract:overlay", "/application_definitions/" + code, "not a package application_code")

    check_catalog(catalog, report, "contract:catalog")
    handlers = set(overlay["provisioning_handlers"])
    for index, item in enumerate(catalog["definitions"]):
        if item.get("handler") and item["handler"] not in handlers:
            report.add("INV_UNKNOWN_HANDLER", "contract:catalog", "/definitions/%d/handler" % index, "handler not declared")

    moodle = ctx["moodle"]
    sources = {"cloud_status": "workspace_status", "erp_application_state": "application_instance_state",
               "integration_health": "integration_state"}
    for field, enum_name in sorted(sources.items()):
        targets = set(mapping[field].values()) | set(mapping.get(field + "_local_only", []))
        for value in sorted(targets - set(moodle[field])):
            report.add("MAPPING_TARGET_UNKNOWN", "contract:mapping", "/" + field, "%s not accepted by Moodle" % value)
        keys = set(k for k in mapping[field] if not k.startswith("_"))
        for value in sorted(set(model.enums[enum_name]) - keys):
            report.add("MAPPING_SOURCE_UNMAPPED", "contract:mapping", "/" + field, "package value %s unmapped" % value)
        for value in sorted(keys - set(model.enums[enum_name])):
            report.add("MAPPING_SOURCE_UNKNOWN", "contract:mapping", "/" + field, "%s is not a package %s value" % (value, enum_name))
    if sorted(mapping["entitlement_mode"]["values"]) != sorted(moodle["entitlement_mode"]):
        report.add("MAPPING_TARGET_UNKNOWN", "contract:mapping", "/entitlement_mode", "modes differ from Moodle")
    for path, name, values in schema.tagged("x-moodle-enum"):
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
    model, overlay = ctx["model"], ctx["overlay"]
    catalog = catalog or ctx["catalog"]
    sub = Report()
    defs, bundles, _ = check_catalog(catalog, sub, scope)
    report.items.extend(sub.items)
    reg = payload["registry"]
    ext = payload["extensions"]
    vocab = overlay["vocabularies"]
    as_of = ts(payload["as_of"])
    index = {}

    for name, entity in model.entities.items():
        coll = entity["collection"]
        index[name] = {}
        records = reg.get(coll, [])
        for pos, record in enumerate(records):
            rid = record[entity["id"]]
            path = "/registry/%s/%d" % (coll, pos)
            if rid in index[name]:
                report.add("INV_DUPLICATE_ID", scope, path, "duplicate %s %s" % (entity["id"], rid))
            index[name][rid] = (path, record)
        if name == "provisioning_job":
            continue  # job scope keys are reported as idempotency findings below
        for fields in entity["unique"]:
            seen = set()
            for pos, record in enumerate(records):
                key = tuple(record.get(f) for f in fields)
                if key in seen:
                    report.add("INV_DUPLICATE_UNIQUE_KEY", scope, "/registry/%s/%d" % (coll, pos), "duplicate %s" % "+".join(fields))
                seen.add(key)

    def ref_ok(entity, value):
        return value in index.get(entity, {})

    for name, entity in model.entities.items():
        for field, target in entity["references"].items():
            for rid, (path, record) in index[name].items():
                value = record.get(field)
                if value is not None and not ref_ok(target, value):
                    report.add("INV_DANGLING_REFERENCE", scope, "%s/%s" % (path, field), "unknown %s %s" % (target, value))

    # extensions: keys resolve, every record covered
    ext_targets = {"subscriptions": "subscription", "entitlements": "entitlement_assignment", "provisioning_jobs": "provisioning_job"}
    for key, entity in sorted(ext_targets.items()):
        for rid in sorted(ext[key]):
            if not ref_ok(entity, rid):
                report.add("INV_DANGLING_REFERENCE", scope, "/extensions/%s/%s" % (key, rid), "extension for unknown %s" % entity)
    for key, entity in (("entitlements", "entitlement_assignment"), ("provisioning_jobs", "provisioning_job")):
        for rid, (path, _) in index[entity].items():
            if rid not in ext[key]:
                report.add("INV_EXTENSION_MISSING", scope, path, "no extensions/%s entry" % key)

    workspaces = {k: v[1] for k, v in index["workspace"].items()}
    audits_by_target = {}
    for aid, (path, event) in index["audit_event"].items():
        audits_by_target.setdefault(event["target_id"], []).append(event)

    # links: primary rules and cross-workspace company ownership
    active_links, primaries, primary_owner = {}, {}, {}
    for lid, (path, link) in index["company_link"].items():
        if link["status"] != "active":
            continue
        active_links.setdefault(link["workspace_uuid"], set()).add(link["company_uuid"])
        if link["role"] == "primary":
            primaries.setdefault(link["workspace_uuid"], []).append(link["company_uuid"])
            owner = primary_owner.setdefault(link["company_uuid"], link["workspace_uuid"])
            if owner != link["workspace_uuid"]:
                report.add("INV_CROSS_WORKSPACE_REFERENCE", scope, path, "company already primary in another workspace")
    for wid, (path, ws) in index["workspace"].items():
        prim = primaries.get(wid, [])
        if len(prim) > 1 or (ws["status"] in NEEDS_PRIMARY and len(prim) != 1):
            report.add("INV_PRIMARY_LINK", scope, path, "workspace %s has %d active primary links" % (ws["status"], len(prim)))
        pointer = ws.get("primary_company_uuid")
        if pointer is not None and prim != [pointer]:
            report.add("INV_PRIMARY_LINK", scope, path + "/primary_company_uuid", "denormalized pointer differs from the primary link")
        if ws["status"] in INACTIVE_WS:
            has_links = any(l["workspace_uuid"] == wid for _, l in index["company_link"].values())
            if not has_links or not audits_by_target.get(wid):
                report.add("INV_HISTORY_REQUIRED", scope, path, "inactive workspace lost links or audit history")

    def linked(wid, company):
        return company in active_links.get(wid, set())

    # application instances (isolation derived from workspace_uuid)
    definitions = overlay["application_definitions"]
    per_ws_app, provider_envs = {}, {}
    for iid, (path, inst) in index["application_instance"].items():
        isolation = "shared" if inst.get("workspace_uuid") is None else "isolated"
        code = inst["application_code"]
        if isolation not in definitions[code]["isolation_allowed"]:
            report.add("INV_ISOLATION_RULE", scope, path, "%s cannot be %s (workspace_uuid %s)" % (code, isolation, "null" if isolation == "shared" else "set"))
        company = inst.get("company_uuid")
        if isolation == "isolated":
            env = inst.get("provider_environment_id")
            if env is not None:
                key = (inst["provider_type"], env)
                if key in provider_envs:
                    report.add("INV_DUPLICATE_PROVIDER_ENV", scope, path + "/provider_environment_id", "provider environment reused")
                provider_envs[key] = iid
            if inst["state"] != "retired":
                key = (inst["workspace_uuid"], code)
                if key in per_ws_app:
                    report.add("INV_DUPLICATE_INSTANCE", scope, path, "second live %s instance" % code)
                per_ws_app[key] = iid
            if company and not linked(inst["workspace_uuid"], company):
                report.add("INV_CROSS_WORKSPACE_REFERENCE", scope, path + "/company_uuid", "company not linked to workspace")
        if code == "erp" and inst["state"] != "draft":
            prim = primaries.get(inst.get("workspace_uuid"), [])
            if isolation != "isolated" or len(prim) != 1 or company != prim[0]:
                report.add("INV_ERP_REQUIRES_COMPANY_LINK", scope, path, "ERP beyond draft needs a workspace and its primary company link")

    # connections
    for cid, (path, conn) in index["integration_connection"].items():
        company = conn.get("company_uuid")
        if company is not None and not linked(conn["workspace_uuid"], company):
            report.add("INV_CROSS_WORKSPACE_REFERENCE", scope, path + "/company_uuid", "company not linked to workspace")
        if not SECRETREF.match(conn["credential_reference"]):
            report.add("INV_RAW_SECRET", scope, path + "/credential_reference", "credential_reference must be a secretref:// reference")

    # customers and subscriptions: vocabulary and provenance
    for cid, (path, cust) in index["customer"].items():
        if cust["commercial_provider"] not in vocab["commercial_provider"]:
            report.add("INV_VOCABULARY", scope, path + "/commercial_provider", "unknown commercial provider")
    for sid, (path, subs) in index["subscription"].items():
        if subs["commercial_provider"] not in vocab["commercial_provider"]:
            report.add("INV_VOCABULARY", scope, path + "/commercial_provider", "unknown commercial provider")
        entry = ext["subscriptions"].get(sid)
        if entry is None:
            report.add("INV_PROVENANCE_MISSING", scope, path, "SubscriptionReference without provenance")
        else:
            prov = entry["provenance"]
            event = index["audit_event"].get(prov["audit_uuid"])
            if event is None or event[1]["target_id"] != sid:
                report.add("INV_PROVENANCE_MISSING", scope, "/extensions/subscriptions/%s/provenance/audit_uuid" % sid, "provenance audit event missing or not about this subscription")
            if subs["commercial_provider"] == "manual_beta" and prov["source_type"] != "manual_registration":
                report.add("INV_PROVENANCE_MISSING", scope, "/extensions/subscriptions/%s/provenance/source_type" % sid, "manual_beta requires manual_registration")
        if subs["product_bundle_code"] not in bundles:
            report.add("INV_UNKNOWN_CODE", scope, path + "/product_bundle_code", "unknown bundle %s" % subs["product_bundle_code"])

    # assignments
    for aid, (path, asg) in index["entitlement_assignment"].items():
        code = asg["entitlement_code"]
        source_type = ext["entitlements"].get(aid, {}).get("source_type")
        if code not in defs:
            report.add("INV_UNKNOWN_CODE", scope, path + "/entitlement_code", "unknown code (fail-closed)")
        if source_type in ("manual_admin_grant", "support_override"):
            if not any((e.get("reason") or "").strip() for e in audits_by_target.get(aid, [])):
                report.add("INV_MANUAL_GRANT_REASON", scope, path, "manual grant without an audited reason")
        if source_type == "commercial_subscription":
            ref = index["subscription"].get(asg["source_reference"])
            if ref is None:
                report.add("INV_DANGLING_REFERENCE", scope, path + "/source_reference", "unknown subscription")
            elif ref[1]["workspace_uuid"] != asg["workspace_uuid"]:
                report.add("INV_CROSS_WORKSPACE_REFERENCE", scope, path + "/source_reference", "subscription of another workspace")
            elif code not in expand_bundle(ref[1]["product_bundle_code"], bundles):
                report.add("INV_BUNDLE_SCOPE", scope, path + "/entitlement_code", "%s not in bundle %s" % (code, ref[1]["product_bundle_code"]))

    effective = {}
    for wid, ws in workspaces.items():
        codes = set()
        if ws["status"] not in INACTIVE_WS:
            for _, asg in index["entitlement_assignment"].values():
                if asg["workspace_uuid"] != wid or asg["state"] != "enabled":
                    continue
                start, end = asg.get("effective_from"), asg.get("effective_to")
                if (start and ts(start) > as_of) or (end and ts(end) <= as_of):
                    continue
                codes.add(asg["entitlement_code"])
        codes &= set(defs)
        effective[wid] = codes
        for code in sorted(codes):
            for dep in defs[code].get("depends_on", []):
                if dep not in codes:
                    report.add("INV_DEPENDENCY_MISSING", scope, index["workspace"][wid][0], "%s requires %s" % (code, dep))

    # provisioning jobs, idempotency and closed workspaces
    handlers = set(overlay["provisioning_handlers"])
    scopes = {}
    for jid, (path, job) in index["provisioning_job"].items():
        job_ext = ext["provisioning_jobs"].get(jid, {})
        if job_ext and job_ext["handler"] not in handlers:
            report.add("INV_UNKNOWN_HANDLER", scope, "/extensions/provisioning_jobs/%s/handler" % jid, "undeclared handler %s" % job_ext["handler"])
        target = job.get("target_id")
        if target is not None and job["target_type"] in model.entities and not ref_ok(job["target_type"], target):
            report.add("INV_DANGLING_REFERENCE", scope, path + "/target_id", "unknown %s" % job["target_type"])
        key = (job["workspace_uuid"], job["operation"], job["idempotency_key"])
        scopes.setdefault(key, []).append((path, job_ext.get("request_hash")))
        ws = workspaces.get(job["workspace_uuid"])
        if ws and ws.get("closed_at") and ts(job["created_at"]) > ts(ws["closed_at"]):
            closed = ts(ws["closed_at"])
            reopen = [ts(e["occurred_at"]) for e in audits_by_target.get(job["workspace_uuid"], [])
                      if e["action"] == "cloud.workspace.reactivated" and closed < ts(e["occurred_at"]) <= ts(job["created_at"])]
            if ws["status"] == "closed" or not reopen:
                report.add("INV_CLOSED_WORKSPACE_JOB", scope, path, "job created after closure without audited reactivation")
    for key, jobs in scopes.items():
        if len(jobs) > 1:
            hashes = {h for _, h in jobs}
            code = "INV_IDEMPOTENCY_CONFLICT" if len(hashes) > 1 else "INV_IDEMPOTENT_DUPLICATE_RESULT"
            for path, _ in jobs[1:]:
                report.add(code, scope, path, "idempotency scope %s/%s/%s repeated" % key)
    deliveries = {}
    for pos, event in enumerate(payload.get("commercial_events", [])):
        key = (event["workspace_uuid"], event["operation"], event["idempotency_key"])
        deliveries.setdefault(key, []).append(("/commercial_events/%d" % pos, event))
        if not ref_ok(event["result_type"], event["result_uuid"]):
            report.add("INV_DANGLING_REFERENCE", scope, "/commercial_events/%d/result_uuid" % pos, "unknown result %s" % event["result_type"])
    for key, events in deliveries.items():
        if len({e["request_hash"] for _, e in events}) > 1:
            for path, _ in events[1:]:
                report.add("INV_IDEMPOTENCY_CONFLICT", scope, path, "same idempotency key, different content")
        elif len({e["result_uuid"] for _, e in events}) > 1:
            for path, _ in events[1:]:
                report.add("INV_IDEMPOTENT_DUPLICATE_RESULT", scope, path, "replay produced a second result")

    # audit coverage and audit workspace scope
    owner_ws = {}
    for name, entity in model.entities.items():
        for rid, (path, record) in index[name].items():
            if name == "workspace":
                owner_ws[rid] = rid
            elif record.get("workspace_uuid"):
                owner_ws[rid] = record["workspace_uuid"]
            if name != "audit_event" and rid not in audits_by_target:
                report.add("INV_AUDIT_MISSING", scope, path, "no audit event for %s" % rid)
    known = set()
    for name in index:
        known |= set(index[name])
    for aid, (path, event) in index["audit_event"].items():
        if event["target_id"] not in known:
            report.add("INV_DANGLING_REFERENCE", scope, path + "/target_id", "audit target unknown")
        target_ws = owner_ws.get(event["target_id"])
        if event.get("workspace_uuid") and target_ws and event["workspace_uuid"] != target_ws:
            report.add("INV_CROSS_WORKSPACE_REFERENCE", scope, path + "/workspace_uuid", "audit event scoped to another workspace")

    # mutations: immutability and soft delete
    for pos, mutation in enumerate(payload.get("mutations", [])):
        path = "/mutations/%d" % pos
        entity = model.entities[mutation["entity"]]
        if mutation["id"] not in index[mutation["entity"]]:
            report.add("INV_DANGLING_REFERENCE", scope, path + "/id", "mutation target unknown")
        if mutation["op"] == "delete":
            report.add("INV_SOFT_DELETE_ONLY", scope, path, "registry records are never deleted")
            continue
        if entity["append_only"]:
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
        if ws is None or primaries.get(wid, []) != [proj["company_uuid"]]:
            report.add("INV_PROJECTION_MISMATCH", scope, path, "cloudmap binding does not match the active primary link")
            continue
        if proj["cloud_status"] != mapping["cloud_status"][ws["status"]]:
            report.add("INV_PROJECTION_MISMATCH", scope, path + "/cloud_status", "expected %s" % mapping["cloud_status"][ws["status"]])
        runtime = index["application_instance"].get(proj["runtime_instance_uuid"])
        if runtime is None or runtime[1]["application_code"] != "mgmt" or runtime[1].get("workspace_uuid") not in (None, wid):
            report.add("INV_PROJECTION_MISMATCH", scope, path + "/runtime_instance_uuid", "runtime must be the shared or own mgmt instance")
        erp = [i for _, i in index["application_instance"].values()
               if i["application_code"] == "erp" and i.get("workspace_uuid") == wid and i["state"] != "retired"]
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
        try:
            for part in parts[:-1]:
                parent = parent[int(part)] if isinstance(parent, list) else parent[part]
        except (KeyError, IndexError, ValueError):
            raise UsageError("patch path not found: %s" % op["path"])
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
                if last not in parent:
                    raise UsageError("remove of missing key %s" % op["path"])
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
        "overlay": load_json(OVERLAY),
        "schema": Schema(load_json(SCHEMA), REG_DIR),
        "package": load_json(PKG_MODEL),
        "package_schema": load_json(PKG_SCHEMA),
        "reconciliation": load_json(RECONCILIATION),
        "catalog": load_json(CATALOG),
        "mapping": load_json(MAPPING),
        "manifest": load_json(MANIFEST),
    }
    ctx["model"] = Model(ctx["package"], ctx["package_schema"], ctx["overlay"])
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

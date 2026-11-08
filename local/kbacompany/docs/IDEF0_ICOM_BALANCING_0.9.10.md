# IDEF0 ICOM Balancing — local_kbacompany 0.9.10

## Canonical rule
A sibling interconnection remains the existing Semantic Core relation:

`Process(source) -> supplies -> Process(target)`

The interconnection is **balanced** when:
1. the source Process has a canonical primary Product (`process.productuuid`); and
2. the target Process visible model contains an active Input (`procelem.elementtype=input`) bound to the same canonical Product through the already existing `procelem.productuuid` field.

No IDEF0-specific balancing table or second edge store is introduced.

## Visibility
- Manager: target draft model when present, otherwise the latest published target model.
- Reader: latest published target model only.
- Every Process, procmodel and procelem lookup is Company UUID scoped before fetch.

## States
- `balanced` — target Input references the source primary Product.
- `target_input_missing` — source Product exists, but the target visible model has no Input bound to it.
- `source_product_missing` — source Process has no primary Product, so product-level balancing cannot be established yet.

## Editing
- Input ICOM elements may now retain a canonical Product reference in the existing `procelem.productuuid` field.
- Output behavior is unchanged; Control and Mechanism/Resource cannot retain a Product binding.
- Manager quick-fix does not write automatically. It opens the existing target ICOM Input form and preselects the source Product after validating that Product against the target company context.
- If the target has no draft, the user is sent to the existing target IDEF0 editor to explicitly create a draft first.

## Non-goals
- no automatic Input creation;
- no hidden draft creation;
- no parent/child boundary tunneling;
- no automatic ICOM inheritance;
- no SIPOC supplier/customer mutation;
- no BPMN/procflow/layout mutation;
- no execution semantics;
- no cross-parent interconnections;
- no schema, ACL or privacy changes.

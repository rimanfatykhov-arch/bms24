# AI Decision Log — v5.0.36.1.2

Catalog section creation/update and product-to-section binding are distinct API concerns.
After fixing section `iblockId`, product update must also carry the catalog `iblockId`
and target `iblockSectionId` so existing products move into the mapped section.

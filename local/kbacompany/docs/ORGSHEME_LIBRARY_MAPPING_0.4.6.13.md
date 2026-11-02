# Organization methodology library mapping — BMS 0.4.6.13

Source folder: `1BOq7KnPp7hHeO-LvWHwCT5LcR_MLFLs5`

**Preservation rule:** source files are read-only methodology assets. The 0.4.6.13 implementation does not rename, delete, move or overwrite any source file.

| BMS template key | Normalized template | Source reference(s) |
|---|---|---|
| `tpl_distribution` | Дистрибуция | `Оргсхема_Дистрибуция.drawio`, `Оргсхема_Дистрибуция.drawio.pdf`, `Пример Оргсхемы Дистрибуция.pdf` |
| `tpl_dental` | Стоматологическая клиника | `Оргсхема_Стоматологическая клиника .drawio`, `Оргсхема_Стоматологическая клиника .drawio.pdf`, `Стоматологическая клиника.pdf` |
| `tpl_custombuild` | Строительство по индивидуальным проектам | `19.2_Оргсхема_Строительство по индивидуальным проектам.drawio`, `Строительство домов по индивидуальным проектам.pdf` |
| `tpl_meatretail` | Мясокомбинат и розница | `Мясокомбинат и розница.pdf` |
| `tpl_boat` | Производство лодок | `Производство лодок.pdf` |
| `tpl_apparel` | Сеть магазинов одежды | `Сеть магазинов одежды.pdf` |
| `tpl_electrical` | Электромонтажные услуги | `Электромонтажные услуги.pdf` |
| `tpl_cafe` | Сеть кафе | `Сеть кафе.pdf` |

`ОС-ВХЗ-01.02-2023.drawio.pdf` remains inventoried but is not yet normalized into the first built-in library set because its business-type mapping requires manual methodology review.

## Normalization rule

The source layouts are not copied as images. Each normalized BMS template is rebuilt as semantic OrgDesign data using the common visual tiers:

`Investment Office → Strategic Office → Management Company / directions → Executive Director → Departments → Department VFPs → Company VFP`.

The user can then adapt the draft, approve it, publish/apply it, and export the same BMS model to SVG/PDF/Draw.io.

# Test Report 0.34.3.1

Build: `2026091002`

- PHP syntax: все файлы пакета успешно разобраны PHP grammar parser.
- `db/install.xml`: корректный XML.
- `db/upgrade.php`: безопасный savepoint, destructive migrations отсутствуют.
- RU/EN: новые ключи присутствуют в обеих локализациях.
- ZIP: один корневой каталог `kbacorporate/`.
- ZIP integrity и SHA256 проверены после сборки.

Проверенные сценарии интерфейса: широкая страница, узкий контейнер, мобильная ширина, длинное название компании.

# 0.9.0.5 — Файлы и версии управляемых документов

## Состав среза
Загрузка одного основного файла на существующую редакцию документа; multipart
BMS CRUD create/edit; файл и защищённое скачивание в карточке; вкладка «Версии»
с файлами, статусами, датами и причинами изменения. Текст и файл можно хранить
вместе. Загрузка сама по себе не утверждает и не вводит документ в действие.

## Хранилище и границы
Добавлено одно nullable TEXT-поле local_kbacompany_doc.filemanifest. Оно хранит
только server-generated имя, MIME, число байт, SHA256 и время загрузки.
itemid File API = ID точной строки редакции; filearea = document_revision;
filepath включает Company UUID и SHA256. UUID/код документа и номер редакции
по-прежнему принадлежат existing document_service. Нового реестра версий нет.

Сначала company-context и SQL ACL по точной редакции, потом File API.
Знание itemid/UUID/хеша не даёт доступа. pluginfile всегда forced download,
без public cache и inline-render. Реальная сессия/роль перепроверяется на сервере.
Новые запросы не получают все компании для последующей фильтрации в PHP.
Прежний task attachment callback не изменён.

## Жизненный цикл
Создание файла/manifest/документа и audit входят в существующую транзакцию;
замены выполняются под existing document lock. Устаревшая форма блокируется.
Отсутствие нового upload не очищает файл. Повтор тех же байт/имени переиспользует
blob. Замена другого файла в черновике требует подтверждения; после выхода
из черновика save запрещён. Возврат с согласования в черновик остаётся прежним
явным действием и меняет review round. Выпущенные файлы не подменяются.

Новая редакция использует новый File API itemid, а не перемещение файла.
Файлы старых редакций сохраняются. Исторические draft blobs сохраняются
append-only, но не являются отдельными пользовательскими редакциями и
не выдаются по устаревшей ссылке. Их очистка/квоты хранения не добавлены.
Новый STP snapshot не получает устаревшее вложение предыдущего snapshot.
Отсутствие прикреплённого файла блокирует согласование, утверждение,
публикацию, ввод в действие и копирование в новую редакцию.

Действующая редакция и рабочий черновик различены. Согласования и ознакомления
не переносятся на другой номер версии. Задачи, показатели и learning не меняются.
Session replay receipts сохраняют прежние 2 часа / 30 записей; это не durable
exactly-once. Хеш загрузки входит в receipt digest, временный путь — нет.

## Проверка загрузки
PHP native is_uploaded_file, проверка реального объёма, finfo/подпись формата,
allowlist расширений, серверный SHA256, ограниченное чтение ZIP metadata без
распаковки, отказ макропакетов/зашифрованных Office entries/чрезмерного объёма.
Это не полная проверка безопасности содержимого PDF/Office. Вызывается
существующий core antivirus manager: реальное сканирование зависит от
настроенных в Moodle антивирусов. Без настроенного сканера антивирусная
безопасность не заявляется. Файлы не отправляются внешним AI/Drive-провайдерам.

## Privacy
Расширен существующий provider: metadata filemanifest, core_files disclosure,
поиск контекста по uploader, экспорт и удаление своих uploaded blobs, включая
унаследованные копии. Разрешённое privacy-удаление — явное исключение из
пользовательской неизменяемости. Другой uploader/новый файл той же редакции
не удаляется. Экспорт различает company/document/revision/contenthash и не
перезаписывает разные файлы с одинаковым именем. Это не новый privacy engine.

## Совместимость
Schema upgrade additive и guarded; старые строки получают NULL без фиктивных
файлов/переписывания content или history. Base contracts ACL, services, cron,
KBA Theme, AI, integration и остальные domains сохранены.
Live upgrade/install и реальные SQL/locking/filesystem/Privacy API acceptance
должны быть выполнены отдельно. Реальная миграция production не выполнялась.

## Не входит
Онлайн-редактор Word, автоматическое распознавание/извлечение текста, сравнение
версий по содержимому, электронная подпись, внешнее хранилище, массовая загрузка,
несколько основных файлов, Odoo adapter и production acceptance.

## Технические первоисточники
Moodle File API: https://moodledev.io/docs/4.1/apis/subsystems/files
Moodle 4.1 branch FileStorage: https://raw.githubusercontent.com/moodle/moodle/MOODLE_401_STABLE/lib/filestorage/file_storage.php
Moodle 4.1 branch Antivirus: https://raw.githubusercontent.com/moodle/moodle/MOODLE_401_STABLE/lib/classes/antivirus/manager.php
Moodle Privacy writer: https://raw.githubusercontent.com/moodle/moodle/MOODLE_401_STABLE/privacy/classes/local/request/content_writer.php
Это проверка контрактов по исходникам, не запуск Moodle 4.1.22.

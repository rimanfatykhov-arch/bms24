# RC29 build2026101473 — Фото без перехода в Moodle

В карточке личного профиля ссылка «Изменить фото в BMS24» ведёт на новый self-service файл employee_avatar.php. Авторизация company-scoped + own Moodle user, пользовательский контекст и sesskey через Moodleform. Проверка размеров, содержимого картинки, сохранение стандартным Moodle process_new_icon. Никаких новых БД-таблиц, фото общее с Moodle. Live acceptance NOT RUN.

"""Synthetic Communication Center render models for the UI harness (#160, task B).

Shapes follow communication_center_ui_service::model() as rendered by the bms24_comm_* templates. Every name, text and
file is invented (prototype #122 style); no production data, no real users, no secrets. Each scenario is one page:
the three sections, plus empty and long-content states that the visual checks must survive.
"""

HIDDEN = [{'name': 'sesskey', 'value': 'harness'}, {'name': 'uuid', 'value': 'company-a'}]


def tabs(active, counts=(2, 1, 3)):
    labels = ['Разговоры', 'Обсуждения', 'Уведомления']
    keys = ['messages', 'discussions', 'notifications']
    out = []
    for key, label, count in zip(keys, labels, counts):
        badge = False
        if count:
            badge = {'value': count, 'variant': 'blue' if key == 'notifications' else 'unread', 'title': 'Непрочитанных: %d' % count}
        out.append({'key': key, 'label': label, 'url': '?tab=' + key, 'active': key == active, 'badge': badge})
    return out


NEW_CONVERSATION = {
    'label': 'Новый разговор', 'hidden': HIDDEN, 'typelabel': 'Тип',
    'types': [{'value': 'direct', 'label': 'Личный', 'selected': True}, {'value': 'group', 'label': 'Группа'}],
    'titlelabel': 'Название', 'titlehint': 'Для группы', 'peoplelabel': 'Участники',
    'people': [{'value': 1, 'label': 'Анна Смирнова'}, {'value': 2, 'label': 'Илья Котов'}],
    'peoplehelp': 'Ctrl — выбрать несколько', 'createlabel': 'Создать'}


def item(uuid, title, initials, variant, preview, time, unread=0, kind=None, active=False, mention=False):
    return {'url': '?thread=' + uuid, 'uuid': uuid, 'active': active, 'unread': unread or False, 'avatarvariant': variant,
            'initials': initials, 'title': title, 'kind': kind or False, 'preview': preview, 'time': time,
            'mention': mention, 'mentiontitle': 'Вас упомянули', 'unreadtitle': 'Непрочитанные'}


def conversation_items(long=False):
    items = [
        item('a', 'Анна Смирнова', 'АС', 'person', 'Если удобнее, обсудим голосом после 14:00.', '10:42', 2, active=True),
        item('b', 'Отдел продаж — планёрка', 'ОП', 'group',
             'Олег: @Ирина Соколова, добавьте, пожалуйста, итоги звонков в общий файл до вечера', '09:15', 1,
             {'variant': 'mint', 'label': 'Группа · 6'}, mention=True),
        item('c', 'Илья Котов', 'ИК', 'person', 'Спасибо, получил.', 'Пн')]
    if long:
        items.append(item('d', 'Очень длинное название группового разговора о согласовании бюджета отдела продаж на IV '
                               'квартал 2026 года с приложениями', 'ОБ', 'group', 'Сверхдлинноесловобезпробеловкотороене'
                               'должноломатьвёрсткусписка ' * 3, '14 нояб.', 128, {'variant': 'mint', 'label': 'Группа · 42'}))
    return items


def discussion_items():
    return [
        item('t', 'Подготовить коммерческое предложение для «Стройресурса» с расчётом скидок по объёму', 'ЗД', 'object',
             'Мария: приложила новую версию расчёта', '11:05', 1, {'variant': 'sky', 'label': 'Задача'}, active=True),
        item('p', 'Боевой план 16–22 ноября · Синтетический сотрудник', 'БП', 'object', 'Руководитель: согласовано, спасибо',
             'Вчера', 0, {'variant': 'mint', 'label': 'Боевой план'})]


def chips():
    return [{'url': '?filter=all', 'title': 'Все', 'label': 'Все', 'active': True},
            {'url': '?filter=unread', 'title': 'Непрочитанные', 'label': 'Непрочитанные', 'active': False},
            {'url': '?filter=mentions', 'title': 'Упоминания', 'label': '@ Упоминания', 'active': False}]


def side(section, items, empty=False):
    placeholder = 'Поиск по имени или названию' if section == 'messages' else 'Поиск по задаче или плану'
    return {'label': 'Список', 'filterslabel': 'Отборы', 'unreadtotal': 3, 'unreadlabel': 'Непрочитанные',
            'search': {'hidden': HIDDEN, 'value': '', 'label': 'Поиск', 'placeholder': placeholder},
            'chips': chips(), 'items': [] if empty else items,
            'empty': {'title': 'Пока нет разговоров', 'text': 'Начните новый разговор кнопкой вверху.'} if empty else False}


def message(uuid, author, time, body, own=False, files=None, edit=False):
    return {'ismessage': True, 'uuid': uuid, 'own': own, 'author': author, 'time': time, 'fulltime': time, 'bodyhtml': body,
            'quote': False, 'hasfiles': bool(files), 'files': files or [], 'hasactions': True,
            'actions': [{'url': '?reply=' + uuid, 'label': 'Ответить'}],
            'edit': {'editlabel': 'Изменить', 'edithidden': HIDDEN, 'bodylabel': 'Текст', 'body': body, 'savelabel': 'Сохранить',
                     'deletelabel': 'Удалить', 'deletehidden': HIDDEN, 'deletenotice': 'Сообщение будет скрыто у всех участников.',
                     'confirmlabel': 'Подтверждаю', 'uuid': uuid} if edit else False}


def thread(section):
    entries = [
        {'isday': True, 'label': 'Вчера'},
        message('m1', 'Анна Смирнова', '17:05', 'Ирина, пришлите, пожалуйста, итоговый план закупок на неделю.'),
        message('m2', 'Вы', '17:20', 'Пришлю завтра до обеда, ждём цены от поставщика.', own=True, edit=True),
        {'isday': True, 'label': 'Сегодня'}, {'isnew': True, 'label': 'Новые сообщения'},
        message('m3', 'Анна Смирнова', '10:40', 'Поставщик прислал цены. Прикладываю оба файла.', files=[
            {'url': '#file1', 'name': 'Цены_поставщика_ноябрь.xlsx', 'size': '84 КБ'},
            {'url': '#file2', 'name': 'Регламент_согласования_бюджета_отдела_продаж_на_IV_квартал_2026_года_с_приложениями.pdf',
             'size': '1,2 МБ'}])]
    if section == 'messages':
        kind = {'text': 'Личный разговор'}
        title = 'Анна Смирнова'
    else:
        kind = {'pill': {'variant': 'sky', 'label': 'Задача'}, 'status': 'В работе', 'due': 'до 25.11.2026'}
        title = 'Подготовить коммерческое предложение для «Стройресурса»'
    return {'feedlabel': 'Сообщения', 'backurl': '?', 'backlabel': 'Назад к списку', 'title': title, 'kindline': kind,
            'searchlabel': 'Поиск в разговоре', 'detailstitle': 'Участники и подробности', 'detailslabel': 'Подробнее',
            'openlink': {'url': '#task', 'label': 'Открыть задачу'} if section == 'discussions' else False,
            'messagesearch': {'hidden': HIDDEN, 'value': '', 'searchlabel': 'Поиск в разговоре', 'hasquery': False},
            'entries': entries, 'nomessages': False,
            'compose': {'hidden': HIDDEN, 'maxbytes': 104857600, 'msgempty': 'Введите сообщение', 'msgtoolarge': 'Файл слишком большой',
                        'reply': False, 'bodylabel': 'Сообщение', 'placeholder': 'Сообщение… Упомянуть: @Имя Фамилия',
                        'filetitle': 'Прикрепить файлы', 'fileslabel': 'Файлы', 'fileremove': 'Убрать файл',
                        'mentions': {'label': 'Упомянуть', 'options': [{'value': 1, 'label': 'Анна Смирнова'}],
                                     'help': 'Ctrl — выбрать несколько', 'inlinehelp': 'Или напишите @Имя Фамилия'},
                        'audience': False, 'sendlabel': 'Отправить'}}


def context():
    return {'title': 'Подробности', 'closelabel': 'Закрыть', 'object': False,
            'people': {'label': 'Участники', 'count': 2, 'items': [
                {'initials': 'АС', 'name': 'Анна Смирнова', 'owner': 'владелец', 'remove': False},
                {'initials': 'ВЫ', 'name': 'Вы', 'owner': False, 'remove': False}]},
            'addperson': False, 'leave': False, 'taskform': False}


def notification(variant, icon, typ, title, subtitle, link, time, read=False):
    return {'variant': variant, 'icon': icon, 'type': typ, 'title': title, 'subtitle': subtitle,
            'links': [{'url': '#open', 'label': link}], 'time': time, 'read': read, 'readlabel': 'Прочитано',
            'markread': False if read else {'hidden': HIDDEN, 'label': 'Отметить прочитанным', 'unreadlabel': 'Не прочитано'}}


def notifications(empty=False):
    groups = [] if empty else [
        {'label': 'Сегодня', 'items': [
            notification('sky', '@', 'Упоминание', 'Олег Демин упомянул вас', 'Отдел продаж — планёрка', 'Открыть разговор', '09:15'),
            notification('mint', '✓', 'Задача', 'Вам назначена проверка результата',
                         'Подготовить коммерческое предложение для «Стройресурса»', 'Открыть задачу', '08:30'),
            notification('sand', '◷', 'План дня', 'Напоминание о плане дня',
                         'Сегодня: задач со сроком — 2, встреч — 1, завтра контрольных точек — 0', 'Открыть календарь', '08:00')]},
        {'label': 'Предыдущие дни', 'items': [
            notification('mint', '⚑', 'Решение по плану', 'Боевой план согласован', 'Боевой план 9–15 ноября · Мария Лебедева',
                         'Открыть план', '14 нояб., 17:20', read=True)]}]
    return {'title': 'Уведомления', 'unread': 0 if empty else 3,
            'onlyunread': {'url': '?filter=unread', 'label': 'Только непрочитанные', 'active': False},
            'settings': {'url': '#settings', 'label': 'Настройки уведомлений'}, 'feedlabel': 'Уведомления',
            'groups': groups, 'empty': 'Уведомлений пока нет.' if empty else False}


def model(section, withthread=True, empty=False, long=False):
    out = {'title': 'Коммуникационный центр', 'sectionslabel': 'Разделы Коммуникационного центра',
           'tabs': tabs(section, (0, 0, 0) if empty else (2, 1, 3)), 'section': section,
           'isnotifications': section == 'notifications', 'newconversation': NEW_CONVERSATION}
    if section == 'notifications':
        out['notifications'] = notifications(empty)
        return out
    items = conversation_items(long) if section == 'messages' else discussion_items()
    withthread = withthread and not empty
    out.update(layout=True, hasthread=withthread, list=side(section, items, empty),
               thread=thread(section) if withthread else False,
               emptythread=False if withthread else {'title': 'Выберите разговор', 'text': 'Слева — ваши разговоры и обсуждения.'},
               context=context() if withthread else False)
    return out


# name -> (section, keyword arguments). Pages are rendered in this order.
SCENARIOS = {
    'messages': ('messages', {}),
    'messages-list': ('messages', {'withthread': False}),
    'messages-long': ('messages', {'withthread': False, 'long': True}),
    'messages-empty': ('messages', {'empty': True}),
    'discussions': ('discussions', {}),
    'notifications': ('notifications', {}),
    'notifications-empty': ('notifications', {'empty': True}),
}

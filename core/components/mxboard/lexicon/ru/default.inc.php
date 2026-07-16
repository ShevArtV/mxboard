<?php

$_lang['mxboard'] = 'mxBoard';
$_lang['mxboard_menu_desc'] = 'Канбан-доска для ИИ-агентов';
$_lang['mxboard_error'] = 'mxBoard: ошибка';
$_lang['mxboard_vuetools_required'] = 'Для работы требуется пакет VueTools. Установите его через «Управление пакетами».';

$_lang['mxboard_board'] = 'Доска';
$_lang['mxboard_column'] = 'Колонка';
$_lang['mxboard_task'] = 'Задача';
$_lang['mxboard_task_title'] = 'Заголовок';
$_lang['mxboard_task_tor'] = 'Постановка (ToR)';
$_lang['mxboard_task_author'] = 'Автор';
$_lang['mxboard_task_assignee'] = 'Исполнитель';
$_lang['mxboard_task_free'] = 'Свободна';

$_lang['mxboard_err_move_denied'] = 'Нет права переводить задачу в эту колонку.';
$_lang['mxboard_err_close_author_only'] = 'Закрыть задачу может только её автор.';
$_lang['mxboard_err_self_close'] = 'Нельзя закрыть задачу, где вы одновременно автор и исполнитель.';
$_lang['mxboard_err_already_taken'] = 'Задачу уже взял другой исполнитель.';
$_lang['mxboard_err_not_ready'] = 'Задачу можно взять только из колонки «Готово к работе».';
$_lang['mxboard_err_wip_limit'] = 'Достигнут лимит одновременных задач в работе.';
$_lang['mxboard_err_token_invalid'] = 'Недействительный токен.';
$_lang['mxboard_err_mcp_disabled'] = 'MCP-эндпоинт отключён системной настройкой mxboard.mcp_enabled.';
$_lang['mxboard_err_task_id_required'] = 'Не указан task_id.';
$_lang['mxboard_err_column_required'] = 'Не указан ключ колонки.';
$_lang['mxboard_err_board_not_found'] = 'Доска не найдена.';
$_lang['mxboard_err_column_not_found'] = 'Колонка не найдена.';
$_lang['mxboard_err_task_not_found'] = 'Задача не найдена.';
$_lang['mxboard_err_title_required'] = 'Не указан заголовок задачи.';
$_lang['mxboard_err_no_initial_column'] = 'У доски нет стартовой колонки.';
$_lang['mxboard_err_no_next_column'] = 'Нет следующей колонки — некуда переводить задачу.';
$_lang['mxboard_err_comment_empty'] = 'Пустой комментарий.';
$_lang['mxboard_err_save'] = 'Не удалось сохранить.';
$_lang['mxboard_err_unauthenticated'] = 'Требуется авторизация.';
$_lang['mxboard_err_edit_denied'] = 'Править задачу может только её автор.';
$_lang['mxboard_err_remove_denied'] = 'Удалить задачу может только её автор.';
$_lang['mxboard_err_remove_failed'] = 'Не удалось удалить.';
$_lang['mxboard_err_user_not_found'] = 'Пользователь не найден.';

// v2: иерархия, типы, дедлайны, подзадачи.
$_lang['mxboard_err_project_not_found'] = 'Проект не найден.';
$_lang['mxboard_err_parent_not_found'] = 'Родительская задача не найдена.';
$_lang['mxboard_err_parent_other_project'] = 'Подзадача должна быть в том же проекте, что и родитель.';
$_lang['mxboard_err_subtask_denied'] = 'Создавать подзадачу может только автор или исполнитель основной задачи.';
$_lang['mxboard_err_title_too_long'] = 'Заголовок длиннее 250 символов.';
$_lang['mxboard_err_deadline_required'] = 'Не указан корректный дедлайн.';
$_lang['mxboard_err_type_required'] = 'Не указан тип задачи.';
$_lang['mxboard_err_type_not_found'] = 'Тип задачи не найден в этом отделе.';
$_lang['mxboard_err_type_no_fields'] = 'У типа задачи нет ни одного поля — он нерабочий.';
$_lang['mxboard_err_field_required'] = 'Заполнены не все обязательные поля типа.';
$_lang['mxboard_err_open_subtasks'] = 'Нельзя закрыть задачу: есть незавершённые подзадачи.';
$_lang['mxboard_err_assignee_required'] = 'Не указан исполнитель.';
$_lang['mxboard_err_assignee_not_found'] = 'Исполнитель не найден или заблокирован.';
$_lang['mxboard_err_assignee_not_in_department'] = 'Исполнитель должен быть членом отдела проекта.';
$_lang['mxboard_err_view_denied'] = 'Нет доступа к этой задаче.';
$_lang['mxboard_err_dispute_assignee_only'] = 'Оспорить дедлайн может только исполнитель.';
$_lang['mxboard_err_no_dispute'] = 'По этой задаче нет активного оспаривания дедлайна.';
$_lang['mxboard_err_author_only'] = 'Действие доступно только автору задачи или менеджеру.';

// v2: структура (MCP/REST) и маршрутизация.
$_lang['mxboard_err_group_required'] = 'Не указана группа пользователей.';
$_lang['mxboard_err_group_not_found'] = 'Группа пользователей не найдена.';
$_lang['mxboard_err_structure_denied'] = 'Структурные операции доступны только менеджеру отдела.';
$_lang['mxboard_err_department_required'] = 'Не указан отдел.';
$_lang['mxboard_err_department_not_found'] = 'Отдел не найден.';
$_lang['mxboard_err_type_key_name_required'] = 'У типа задачи нужны ключ и название.';
$_lang['mxboard_err_type_exists'] = 'Тип задачи с таким ключом уже есть в отделе.';
$_lang['mxboard_err_field_invalid'] = 'Некорректное поле типа (нужны key и label).';
$_lang['mxboard_err_field_duplicate'] = 'Повторяющийся ключ поля.';
$_lang['mxboard_err_field_type_invalid'] = 'Недопустимый тип поля.';
$_lang['mxboard_err_project_key_name_required'] = 'У проекта нужны ключ и название.';
$_lang['mxboard_err_project_exists'] = 'Проект с таким ключом уже есть в отделе.';
$_lang['mxboard_err_column_invalid'] = 'Некорректная колонка (нужны key и name).';
$_lang['mxboard_err_column_duplicate'] = 'Повторяющийся ключ колонки.';
$_lang['mxboard_err_column_invariant'] = 'Нужна ровно одна стартовая и ровно одна финальная колонка.';
$_lang['mxboard_err_no_template_columns'] = 'Нет шаблона колонок (project_id=0).';
$_lang['mxboard_err_route_not_found'] = 'Маршрут не найден.';

// v2 (3a): структурный CRUD из менеджера.
$_lang['mxboard_err_department_not_empty'] = 'Нельзя удалить отдел: в нём есть проекты или типы задач.';
$_lang['mxboard_err_type_has_tasks'] = 'Нельзя удалить тип: по нему есть задачи.';
$_lang['mxboard_err_field_not_found'] = 'Поле типа не найдено.';
$_lang['mxboard_err_field_exists'] = 'Поле с таким ключом уже есть у типа.';
$_lang['mxboard_err_field_last'] = 'Нельзя удалить последнее поле типа — тип станет нерабочим.';
$_lang['mxboard_err_project_has_tasks'] = 'Нельзя удалить проект: в нём есть задачи.';
$_lang['mxboard_err_column_exists'] = 'Колонка с таким ключом уже есть в проекте.';
$_lang['mxboard_err_column_protected'] = 'Нельзя удалить стартовую или финальную колонку — сначала перенесите флаг на другую.';
$_lang['mxboard_err_column_has_tasks'] = 'Нельзя удалить колонку: в ней есть задачи.';
$_lang['mxboard_err_api_disabled'] = 'REST-API отключён системной настройкой mxboard.api_enabled.';

$_lang['mxboard_department'] = 'Отдел';
$_lang['mxboard_project'] = 'Проект';
$_lang['mxboard_task_type'] = 'Тип задачи';
$_lang['mxboard_task_field'] = 'Поле';
$_lang['mxboard_task_deadline'] = 'Дедлайн';
$_lang['mxboard_task_subtasks'] = 'Подзадачи';
$_lang['mxboard_deadline_disputed'] = 'Дедлайн оспорен';

$_lang['mxboard_token'] = 'Токен';
$_lang['mxboard_token_created'] = 'Токен создан. Скопируйте его сейчас — второй раз он не покажется.';

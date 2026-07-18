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
$_lang['mxboard_err_comment_not_found'] = 'Комментарий не найден.';
$_lang['mxboard_err_comment_author_only'] = 'Редактировать/удалить комментарий может только его автор.';
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
$_lang['mxboard_err_ai_incomplete'] = 'ИИ-проверка: в постановке не хватает данных для работы.';

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

// Подзадача A: вложения задач и сообщений чата.
$_lang['mxboard_err_upload_no_file'] = 'Файл не передан.';
$_lang['mxboard_err_upload_failed'] = 'Не удалось загрузить файл.';
$_lang['mxboard_err_upload_ext'] = 'Недопустимое расширение файла.';
$_lang['mxboard_err_upload_size'] = 'Файл превышает допустимый размер.';
$_lang['mxboard_err_source_unavailable'] = 'Media source для вложений недоступен — проверьте настройку mxboard.media_source.';
$_lang['mxboard_err_attachment_not_found'] = 'Вложение не найдено.';
$_lang['mxboard_err_attachment_denied'] = 'Удалить вложение может автор файла, автор задачи или менеджер.';

$_lang['mxboard_department'] = 'Отдел';
$_lang['mxboard_project'] = 'Проект';
$_lang['mxboard_task_type'] = 'Тип задачи';
$_lang['mxboard_task_field'] = 'Поле';
$_lang['mxboard_task_deadline'] = 'Дедлайн';
$_lang['mxboard_task_subtasks'] = 'Подзадачи';
$_lang['mxboard_deadline_disputed'] = 'Дедлайн оспорен';

$_lang['mxboard_token'] = 'Токен';
$_lang['mxboard_token_created'] = 'Токен создан. Скопируйте его сейчас — второй раз он не покажется.';

// v2 (3b): интерфейс доски и страницы задачи (грузятся в window.MODx.lang контроллером CMP).
$_lang['mxboard_ui_board'] = 'Доска';
$_lang['mxboard_ui_tokens'] = 'Токены агентов';
$_lang['mxboard_ui_structure'] = 'Структура';
$_lang['mxboard_ui_refresh'] = 'Обновить';
$_lang['mxboard_ui_cancel'] = 'Отмена';
$_lang['mxboard_ui_create'] = 'Создать';
$_lang['mxboard_ui_save'] = 'Сохранить';
$_lang['mxboard_ui_delete'] = 'Удалить';
$_lang['mxboard_ui_edit'] = 'Редактировать';
$_lang['mxboard_ui_send'] = 'Отправить';
$_lang['mxboard_ui_empty'] = 'Пусто';
$_lang['mxboard_ui_loading'] = 'Загрузка…';

$_lang['mxboard_ui_department'] = 'Отдел';
$_lang['mxboard_ui_project'] = 'Проект';
$_lang['mxboard_ui_filter_all'] = 'Все';
$_lang['mxboard_ui_filter_author'] = 'Я автор';
$_lang['mxboard_ui_filter_assignee'] = 'Я исполнитель';
$_lang['mxboard_ui_filter_all_priorities'] = 'Все приоритеты';
$_lang['mxboard_ui_new_task'] = 'Новая задача';
$_lang['mxboard_ui_no_projects'] = 'Нет проектов в этом отделе. Создайте проект на вкладке «Структура».';

$_lang['mxboard_ui_subtask'] = 'Подзадача';
$_lang['mxboard_ui_deadline_disputed_hint'] = 'Дедлайн оспорен';

$_lang['mxboard_ui_new_subtask'] = 'Новая подзадача';
$_lang['mxboard_ui_subtask_for'] = 'Подзадача для';
$_lang['mxboard_ui_task_type'] = 'Тип задачи';
$_lang['mxboard_ui_select_type'] = 'Выберите тип';
$_lang['mxboard_ui_no_types'] = 'В отделе нет типов задач — создайте их на вкладке «Структура».';
$_lang['mxboard_ui_title'] = 'Заголовок';
$_lang['mxboard_ui_deadline'] = 'Дедлайн';
$_lang['mxboard_ui_priority'] = 'Приоритет';
$_lang['mxboard_ui_assignee'] = 'Исполнитель';
$_lang['mxboard_ui_assignee_placeholder'] = 'Из отдела проекта';
$_lang['mxboard_ui_tor'] = 'Постановка (ToR, markdown)';
$_lang['mxboard_ui_loading_fields'] = 'Загрузка полей типа…';

$_lang['mxboard_ui_to_board'] = 'На доску';
$_lang['mxboard_ui_cancel_edit'] = 'Отменить правку';
$_lang['mxboard_ui_parent'] = 'Родитель';
$_lang['mxboard_ui_author_label'] = 'Автор';
$_lang['mxboard_ui_assignee_label'] = 'Исполнитель';
$_lang['mxboard_ui_deadline_label'] = 'Дедлайн';
$_lang['mxboard_ui_overdue'] = 'просрочен';
$_lang['mxboard_ui_disputed_to'] = 'оспорен';
$_lang['mxboard_ui_accept'] = 'Принять';
$_lang['mxboard_ui_reject'] = 'Отклонить';
$_lang['mxboard_ui_dispute'] = 'Оспорить';
$_lang['mxboard_ui_proposed_date'] = 'Предлагаемая дата';
$_lang['mxboard_ui_reason'] = 'Причина';
$_lang['mxboard_ui_reason_placeholder'] = 'Почему нужен перенос';
$_lang['mxboard_ui_stage'] = 'Стадия';
$_lang['mxboard_ui_tor_section'] = 'Постановка';
$_lang['mxboard_ui_tor_empty'] = 'Постановка не заполнена';
$_lang['mxboard_ui_type_fields'] = 'Поля типа';
$_lang['mxboard_ui_ai_verdict'] = 'ИИ-проверка полноты';
$_lang['mxboard_ui_ai_ok'] = 'полная';
$_lang['mxboard_ui_ai_incomplete_short'] = 'неполная';
$_lang['mxboard_ui_ai_overridden'] = 'создано в обход';
$_lang['mxboard_ui_ai_incomplete'] = 'ИИ-проверка: постановка неполная — не хватает данных для работы';
$_lang['mxboard_ui_ai_create_anyway'] = 'Всё равно создать';
$_lang['mxboard_ui_subtasks'] = 'Подзадачи';
$_lang['mxboard_ui_no_subtasks'] = 'Подзадач нет';
$_lang['mxboard_ui_comments'] = 'Комментарии';
$_lang['mxboard_ui_comment_placeholder'] = 'Комментарий…';
$_lang['mxboard_ui_comment_edited'] = 'редактировано';
$_lang['mxboard_ui_no_comments'] = 'Комментариев нет';
$_lang['mxboard_ui_log'] = 'Журнал';
$_lang['mxboard_ui_no_log'] = 'Записей нет';

// Карточка задачи (двухколоночная + чат).
$_lang['mxboard_ui_chat'] = 'Чат задачи';
$_lang['mxboard_ui_setter'] = 'Постановщик';
$_lang['mxboard_ui_created'] = 'Дата создания';
$_lang['mxboard_ui_task_id'] = 'ID';
$_lang['mxboard_ui_attach_soon'] = 'Прикрепление файлов — скоро';

// Подзадача C: вложения во фронте (композер, блок файлов задачи, поле file).
$_lang['mxboard_ui_task_files'] = 'Файлы задачи';
$_lang['mxboard_ui_attach_file'] = 'Прикрепить файл';
$_lang['mxboard_ui_no_files'] = 'Файлов нет';
$_lang['mxboard_ui_download'] = 'Скачать';
$_lang['mxboard_ui_clear'] = 'Очистить';
$_lang['mxboard_ui_file_replace'] = 'Заменить файл';
$_lang['mxboard_ui_file_after_save'] = 'Файл можно прикрепить после сохранения задачи';
$_lang['mxboard_ui_files_message'] = 'Прикреплённые файлы';

// Действия журнала (человеческие названия).
$_lang['mxboard_act_create'] = 'создана';
$_lang['mxboard_act_move'] = 'перемещена';
$_lang['mxboard_act_close'] = 'закрыта';
$_lang['mxboard_act_comment'] = 'комментарий';
$_lang['mxboard_act_update'] = 'изменена';
$_lang['mxboard_act_subtask_add'] = 'добавлена подзадача';
$_lang['mxboard_act_deadline_dispute'] = 'дедлайн оспорен';
$_lang['mxboard_act_deadline_accepted'] = 'новый дедлайн принят';
$_lang['mxboard_act_deadline_rejected'] = 'оспаривание отклонено';
$_lang['mxboard_act_ai_check'] = 'ИИ-проверка';

// Тосты и подтверждения.
$_lang['mxboard_msg_refs_load'] = 'Справочники не загружены';
$_lang['mxboard_msg_board_load'] = 'Доска не загружена';
$_lang['mxboard_msg_move_rejected'] = 'Перемещение отклонено';
$_lang['mxboard_msg_task_created'] = 'Задача создана';
$_lang['mxboard_msg_task_not_created'] = 'Задача не создана';
$_lang['mxboard_msg_schema_load'] = 'Схема типа не загружена';
$_lang['mxboard_msg_warn_no_type'] = 'Не выбран тип задачи';
$_lang['mxboard_msg_warn_no_title'] = 'Не указан заголовок';
$_lang['mxboard_msg_warn_no_deadline'] = 'Не указан дедлайн';
$_lang['mxboard_msg_warn_no_assignee'] = 'Не выбран исполнитель';
$_lang['mxboard_msg_task_load'] = 'Задача не загружена';
$_lang['mxboard_msg_rejected'] = 'Отказано';
$_lang['mxboard_msg_stage_changed'] = 'Стадия изменена';
$_lang['mxboard_msg_comment_added'] = 'Комментарий добавлен';
$_lang['mxboard_msg_comment_updated'] = 'Комментарий обновлён';
$_lang['mxboard_msg_comment_deleted'] = 'Комментарий удалён';
$_lang['mxboard_msg_confirm_delete_comment'] = 'Удалить комментарий?';
$_lang['mxboard_msg_file_uploaded'] = 'Файл загружен';
$_lang['mxboard_msg_file_deleted'] = 'Файл удалён';
$_lang['mxboard_msg_confirm_delete_file'] = 'Удалить файл?';
$_lang['mxboard_msg_upload_partial'] = 'Часть файлов не загружена';
$_lang['mxboard_msg_id_copied'] = 'ID скопирован';
$_lang['mxboard_msg_saved'] = 'Сохранено';
$_lang['mxboard_msg_deadline_disputed'] = 'Дедлайн оспорен';
$_lang['mxboard_msg_deadline_accepted'] = 'Новый дедлайн принят';
$_lang['mxboard_msg_deadline_rejected'] = 'Оспаривание отклонено';
$_lang['mxboard_msg_task_deleted'] = 'Задача удалена';
$_lang['mxboard_msg_warn_proposed_date'] = 'Укажите предлагаемую дату';
$_lang['mxboard_msg_confirm_delete_task'] = 'Удалить задачу? Подзадачи будут откреплены, а не удалены.';

// Токены агентов (вкладка).
$_lang['mxboard_ui_new_token'] = 'Новый токен';
$_lang['mxboard_ui_new_token_agent'] = 'Новый токен агента';
$_lang['mxboard_ui_token_created_banner'] = 'Токен создан.';
$_lang['mxboard_ui_token_created_hint'] = 'Сохраните его сейчас — он показывается один раз и больше не будет доступен: в базе хранится только хэш.';
$_lang['mxboard_ui_copy'] = 'Скопировать';
$_lang['mxboard_ui_col_name'] = 'Название';
$_lang['mxboard_ui_col_user'] = 'Пользователь';
$_lang['mxboard_ui_col_status'] = 'Статус';
$_lang['mxboard_ui_col_created'] = 'Создан';
$_lang['mxboard_ui_col_used'] = 'Использован';
$_lang['mxboard_ui_status_active'] = 'активен';
$_lang['mxboard_ui_status_revoked'] = 'отозван';
$_lang['mxboard_ui_no_tokens'] = 'Токенов нет';
$_lang['mxboard_ui_token_name'] = 'Название';
$_lang['mxboard_ui_token_user'] = 'ID пользователя MODX';
$_lang['mxboard_ui_token_user_hint'] = 'Права агента — это права его пользователя MODX. По умолчанию подставлен ваш ID.';
$_lang['mxboard_ui_token_name_placeholder'] = 'Например: jarvis-worker';
$_lang['mxboard_msg_tokens_load'] = 'Токены не загружены';
$_lang['mxboard_msg_token_name_required'] = 'Не указано название токена';
$_lang['mxboard_msg_token_created'] = 'Токен создан';
$_lang['mxboard_msg_token_not_created'] = 'Токен не создан';
$_lang['mxboard_msg_token_no_value'] = 'Сервер не вернул значение токена — проверьте процессор Token\\Create.';
$_lang['mxboard_msg_token_copied'] = 'Токен скопирован';
$_lang['mxboard_msg_token_copy_manual'] = 'Скопируйте вручную';
$_lang['mxboard_msg_token_revoke_fail'] = 'Не удалось отозвать';
$_lang['mxboard_msg_token_revoked'] = 'Токен отозван';
$_lang['mxboard_msg_confirm_revoke'] = 'Отозвать токен «[[+name]]»? Агент с ним перестанет работать.';

// v2 (3c): экран «Структура» (только менеджер).
$_lang['mxboard_ui_struct_departments'] = 'Отделы';
$_lang['mxboard_ui_struct_types'] = 'Типы задач';
$_lang['mxboard_ui_struct_projects'] = 'Проекты';
$_lang['mxboard_ui_struct_columns'] = 'Колонки/стадии';

$_lang['mxboard_ui_struct_name'] = 'Название';
$_lang['mxboard_ui_struct_key'] = 'Ключ';
$_lang['mxboard_ui_struct_description'] = 'Описание';
$_lang['mxboard_ui_struct_active'] = 'Активен';
$_lang['mxboard_ui_struct_position'] = 'Позиция';
$_lang['mxboard_ui_struct_actions'] = 'Действия';
$_lang['mxboard_ui_struct_add'] = 'Добавить';
$_lang['mxboard_ui_struct_edit'] = 'Изменить';
$_lang['mxboard_ui_struct_saved'] = 'Сохранено';
$_lang['mxboard_ui_struct_removed'] = 'Удалено';
$_lang['mxboard_ui_struct_created'] = 'Создано';
$_lang['mxboard_ui_struct_empty'] = 'Пусто';

// Отделы.
$_lang['mxboard_ui_struct_register_dept'] = 'Зарегистрировать отдел';
$_lang['mxboard_ui_struct_usergroup'] = 'Группа пользователей MODX';
$_lang['mxboard_ui_struct_usergroup_hint'] = 'Отдел = группа MODX. Членство и роли берутся из MODX, здесь только пометка.';
$_lang['mxboard_ui_struct_already_dept'] = 'уже отдел';
$_lang['mxboard_ui_struct_confirm_remove_dept'] = 'Снять пометку «отдел» с «[[+name]]»? Только если в нём нет проектов и типов.';

// Типы задач.
$_lang['mxboard_ui_struct_new_type'] = 'Новый тип задачи';
$_lang['mxboard_ui_struct_type_fields'] = 'Поля типа';
$_lang['mxboard_ui_struct_add_field'] = 'Добавить поле';
$_lang['mxboard_ui_struct_field_key'] = 'Ключ поля';
$_lang['mxboard_ui_struct_field_label'] = 'Подпись';
$_lang['mxboard_ui_struct_field_type'] = 'Тип поля';
$_lang['mxboard_ui_struct_field_required'] = 'Обязательное';
$_lang['mxboard_ui_struct_field_min'] = 'У типа должно остаться хотя бы одно поле.';
$_lang['mxboard_ui_struct_confirm_remove_type'] = 'Удалить тип «[[+name]]»? Только если по нему нет задач.';
$_lang['mxboard_ui_struct_ai_check'] = 'ИИ-проверка полноты постановки';
$_lang['mxboard_ui_struct_ai_prompt'] = 'Промпт ИИ-проверки (необязательно)';
$_lang['mxboard_ui_struct_ai_prompt_hint'] = 'Пусто — используется глобальный промпт из системной настройки mxboard.ai_check_prompt.';
$_lang['mxboard_ui_struct_confirm_remove_field'] = 'Удалить поле «[[+name]]»?';
$_lang['mxboard_ui_struct_pick_department'] = 'Выберите отдел';

// Проекты.
$_lang['mxboard_ui_struct_new_project'] = 'Новый проект';
$_lang['mxboard_ui_struct_confirm_remove_project'] = 'Удалить проект «[[+name]]»? Только если в нём нет задач.';
$_lang['mxboard_ui_struct_columns_from_template'] = 'Колонки будут созданы из шаблона (project_id=0).';

// Колонки/стадии.
$_lang['mxboard_ui_struct_pick_project'] = 'Выберите проект';
$_lang['mxboard_ui_struct_template'] = 'Шаблон новых проектов';
$_lang['mxboard_ui_struct_new_column'] = 'Новая колонка';
$_lang['mxboard_ui_struct_move_roles'] = 'Роли перехода (CSV)';
$_lang['mxboard_ui_struct_move_roles_hint'] = 'author, assignee, any или group:Имя — кто вправе перевести карточку В эту колонку.';
$_lang['mxboard_ui_struct_stage_key'] = 'Ключ стадии';
$_lang['mxboard_ui_struct_is_initial'] = 'Стартовая';
$_lang['mxboard_ui_struct_is_final'] = 'Финальная';
$_lang['mxboard_ui_struct_color'] = 'Цвет';
$_lang['mxboard_ui_struct_flag_transfer'] = 'Отметка «стартовая»/«финальная» переносится с прежней колонки — их всегда ровно по одной.';
$_lang['mxboard_ui_struct_confirm_remove_column'] = 'Удалить колонку «[[+name]]»? Нельзя для стартовой/финальной и непустой.';

// v2 (3d): виджет «Токен агента» на странице профиля пользователя (только sudo).
$_lang['mxboard_ui_profile_section'] = 'Токен агента mxBoard';
$_lang['mxboard_ui_profile_hint'] = 'Один токен на пользователя для доступа агента к REST/MCP. Права агента = права этого пользователя.';
$_lang['mxboard_ui_profile_generate'] = 'Сгенерировать';
$_lang['mxboard_ui_profile_regenerate'] = 'Перевыпустить';
$_lang['mxboard_ui_profile_none'] = 'Токен не выдан';
$_lang['mxboard_ui_profile_created'] = 'Выдан';
$_lang['mxboard_ui_profile_confirm_regen'] = 'Перевыпустить токен? Прежний перестанет работать.';
$_lang['mxboard_ui_profile_save_first'] = 'Сначала сохраните пользователя, затем выдайте токен.';
$_lang['mxboard_ui_profile_copied'] = 'Токен скопирован';

<?php

/**
 * Resolver: стартовый сид v2 — чтобы после установки было куда и чем класть задачи.
 *
 * Создаёт (идемпотентно):
 *   - группу пользователей MODX «mxBoard» и помечает её как отдел (mxboard_department);
 *   - типы задач bugfix и feature с обязательными полями (тип «рабочий» = ≥1 поле);
 *   - проект default с пятью колонками (инвариант: ровно одна initial, ровно одна final);
 *   - глобальный шаблон колонок (project_id = 0) — дефолт для проектов без своих колонок
 *     (fallback при показе доски) и источник для ручного копирования.
 *
 * Идемпотентен: сущности создаются по ключу один раз, при upgrade не трогаются
 * (пользователь мог переименовать колонки, поля или поменять права переходов).
 *
 * Модель прав переходов (move_roles) — CSV ролей относительно карточки:
 *   author   — тот, кто поставил задачу
 *   assignee — тот, кто её взял
 * Плюс глобальное право mxboard_move_any и «супер группы отдела» (authority роли).
 *
 * @var \xPDO\Transport\xPDOTransport $transport
 * @var array $options
 */

use MODX\Revolution\modUserGroup;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTaskType;
use xPDO\Transport\xPDOTransport;

if (!$transport->xpdo) {
    return true;
}

/** @var modX $modx */
$modx = $transport->xpdo;
$action = $options[xPDOTransport::PACKAGE_ACTION] ?? '';

if ($action === xPDOTransport::ACTION_UNINSTALL) {
    return true;
}

$corePath = $modx->getOption('core_path') . 'components/mxboard/';

$autoload = $corePath . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (!isset($modx->packages['MxBoard\\Model'])) {
    $modx->addPackage('MxBoard\\Model', $corePath . 'src/', null, 'MxBoard\\');
}

$now = time();

/* --- Группа MODX + отдел ---------------------------------------------------- */

$groupName = 'mxBoard';

/** @var modUserGroup|null $group */
$group = $modx->getObject(modUserGroup::class, ['name' => $groupName]);
if (!$group) {
    /** @var modUserGroup $group */
    $group = $modx->newObject(modUserGroup::class);
    $group->set('name', $groupName);
    if (!$group->save()) {
        $modx->log(modX::LOG_LEVEL_ERROR, '[mxBoard] Не удалось создать группу пользователей mxBoard.');
        return true;
    }
    $modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Создана группа пользователей mxBoard.');
}
$usergroupId = (int) $group->get('id');

/** @var MxBoardDepartment|null $department */
$department = $modx->getObject(MxBoardDepartment::class, ['usergroup_id' => $usergroupId]);
if (!$department) {
    /** @var MxBoardDepartment $department */
    $department = $modx->newObject(MxBoardDepartment::class);
    $department->fromArray([
        'usergroup_id' => $usergroupId,
        'name' => 'Отдел разработки',
        'active' => true,
        'position' => 0,
        'createdon' => $now,
    ]);
    $department->save();
    $modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Отдел зарегистрирован.');
}
$departmentId = (int) $department->get('id');

/* --- Типы задач с полями ---------------------------------------------------- */

$types = [
    [
        'key' => 'bugfix',
        'name' => 'Багфикс',
        'description' => 'Исправление ошибки: где, что, как воспроизвести и как должно быть.',
        'fields' => [
            ['key' => 'where', 'label' => 'Где сломалось', 'type' => 'text', 'required' => true],
            ['key' => 'what', 'label' => 'Что сломалось', 'type' => 'textarea', 'required' => true],
            ['key' => 'steps', 'label' => 'Как воспроизвести', 'type' => 'textarea', 'required' => true],
            ['key' => 'expected', 'label' => 'Как должно быть', 'type' => 'textarea', 'required' => true],
        ],
    ],
    [
        'key' => 'feature',
        'name' => 'Фича',
        'description' => 'Новая функциональность: цель и критерии приёмки.',
        'fields' => [
            ['key' => 'goal', 'label' => 'Цель', 'type' => 'textarea', 'required' => true],
            ['key' => 'criteria', 'label' => 'Критерии приёмки', 'type' => 'textarea', 'required' => true],
        ],
    ],
    [
        'key' => 'research',
        'name' => 'Ресёрч',
        'description' => 'Изучить вопрос и вернуть ответ: что выяснить и в каком виде отдать результат.',
        'fields' => [
            ['key' => 'prompt', 'label' => 'Что выяснить', 'type' => 'textarea', 'required' => true],
            ['key' => 'result_format', 'label' => 'Формат результата', 'type' => 'textarea', 'required' => false],
        ],
    ],
];

foreach ($types as $tPos => $typeData) {
    /** @var MxBoardTaskType|null $type */
    $type = $modx->getObject(MxBoardTaskType::class, ['department_id' => $departmentId, 'key' => $typeData['key']]);
    if ($type) {
        continue;
    }

    /** @var MxBoardTaskType $type */
    $type = $modx->newObject(MxBoardTaskType::class);
    $type->fromArray([
        'department_id' => $departmentId,
        'key' => $typeData['key'],
        'name' => $typeData['name'],
        'description' => $typeData['description'],
        'active' => true,
        'position' => $tPos,
        'createdon' => $now,
    ]);
    if (!$type->save()) {
        continue;
    }

    foreach ($typeData['fields'] as $fPos => $fieldData) {
        /** @var MxBoardField $field */
        $field = $modx->newObject(MxBoardField::class);
        $field->fromArray([
            'task_type_id' => (int) $type->get('id'),
            'key' => $fieldData['key'],
            'label' => $fieldData['label'],
            'type' => $fieldData['type'],
            'required' => $fieldData['required'],
            'position' => $fPos,
            'options' => null,
            'createdon' => $now,
        ]);
        $field->save();
    }
}

/* --- Колонки: общий шаблон и конкретный проект ------------------------------ */

// Восемь стадий, заточенных под работу с агентами: постановка вызревает в бэклоге,
// автор открывает работу переводом в «Старт», исполнитель выносит план на подтверждение
// и только после одобрения приступает к реализации.
// Инвариант: ровно одна initial (backlog) и ровно одна final (done).
// Исполнитель назначается при создании — карточка сразу именная, «свободного пула» нет.
$columns = [
    // Бэклог: новая карточка падает сюда (исполнитель уже назначен). Постановка ещё
    // дорабатывается — работа НЕ начата, внешние интеграции агента отсюда не будят.
    ['key' => 'backlog', 'name' => 'Бэклог', 'position' => 0, 'move_roles' => 'assignee,author', 'is_initial' => true],
    // Старт: ручной гейт запуска. Только автор (или менеджер) даёт исполнителю зелёный
    // свет — важно, когда исполнитель агент: он может быть занят или без токенов.
    ['key' => 'to_start', 'name' => 'Старт', 'position' => 1, 'move_roles' => 'author'],
    // План: исполнитель изучил задачу и вынес план работ на подтверждение. Двигает исполнитель.
    ['key' => 'plan', 'name' => 'План', 'position' => 2, 'move_roles' => 'assignee'],
    // Согласование: только автор одобряет план переводом сюда — второй ручной гейт, теперь
    // на реализацию. Не согласен — комментирует, стадию не меняет; исполнитель правит план.
    ['key' => 'confirm', 'name' => 'Согласование', 'position' => 3, 'move_roles' => 'author'],
    // В работе: исполнитель приступил после подтверждения плана.
    ['key' => 'in_progress', 'name' => 'В работе', 'position' => 4, 'move_roles' => 'assignee'],
    // На проверке: исполнитель сдал результат; автор может вернуть карточку сюда с ревью.
    ['key' => 'review', 'name' => 'На проверке', 'position' => 5, 'move_roles' => 'author,assignee'],
    // Готово: закрывает ТОЛЬКО автор задачи (или менеджер). Исполнитель сюда не дотянется.
    ['key' => 'done', 'name' => 'Готово', 'position' => 6, 'move_roles' => 'author', 'is_final' => true],
    // Отменена: тупик для снятых задач. Не финальная — закрытием считается только «Готово».
    ['key' => 'cancel', 'name' => 'Отменена', 'position' => 7, 'move_roles' => 'author', 'color' => '#ff8080'],
];

// Идемпотентно: недостающие колонки создаём, у существующих подтягиваем position и
// move_roles. Позицию обновлять обязательно — при добавлении стадии в середину набора
// (`to_start`) старые колонки иначе остаются со сдвинутыми номерами и порядок на доске
// едет. Имя и цвет не трогаем: их могли осознанно переименовать под свой процесс.
$seedColumns = static function (int $projectId) use ($modx, $columns, $now): void {
    foreach ($columns as $data) {
        /** @var MxBoardColumn|null $existing */
        $existing = $modx->getObject(MxBoardColumn::class, ['project_id' => $projectId, 'key' => $data['key']]);
        if ($existing) {
            $existing->set('position', $data['position']);
            $existing->set('move_roles', $data['move_roles']);
            $existing->save();
            continue;
        }
        /** @var MxBoardColumn $column */
        $column = $modx->newObject(MxBoardColumn::class);
        $column->fromArray(array_merge([
            'project_id' => $projectId,
            'is_initial' => false,
            'is_final' => false,
            'createdon' => $now,
        ], $data));
        $column->save();
    }
};

// Глобальный шаблон колонок (project_id = 0).
$seedColumns(0);

/* --- Проект по умолчанию ---------------------------------------------------- */

/** @var MxBoardProject|null $project */
$project = $modx->getObject(MxBoardProject::class, ['department_id' => $departmentId, 'key' => 'default']);
if (!$project) {
    /** @var MxBoardProject $project */
    $project = $modx->newObject(MxBoardProject::class);
    $project->fromArray([
        'department_id' => $departmentId,
        'key' => 'default',
        'name' => 'Основной проект',
        'description' => 'Проект по умолчанию, создан при установке mxBoard.',
        'active' => true,
        'position' => 0,
        'createdon' => $now,
        'updatedon' => $now,
    ]);
    $project->save();
    $modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Проект default создан.');
}
$seedColumns((int) $project->get('id'));

$modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Сид v2 готов: отдел, типы (bugfix, feature), проект default с колонками.');

return true;

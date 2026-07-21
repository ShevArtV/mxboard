<?php

/**
 * Resolver: стартовый сид v2 — чтобы после установки было куда и чем класть задачи.
 *
 * Создаёт (идемпотентно):
 *   - группу пользователей MODX «mxBoard» и помечает её как отдел (mxboard_department);
 *   - типы задач bugfix и feature с обязательными полями (тип «рабочий» = ≥1 поле);
 *   - проект default с шестью колонками (инвариант: ровно одна initial, ровно одна final);
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

// Состав типов объявлен один раз — в core/components/mxboard/schema/task-types.php,
// оттуда же его читает стендовый сид. Два независимых списка успели разъехаться
// (у резолвера не было ни `severity`, ни `environment`), поэтому источник теперь один.
// Ставим только набор `core`: доменные типы менеджерского процесса (акции, SEO,
// вёрстка) — стандарт конкретного отдела, навязывать их установке с modstore незачем.
$schemaFile = $corePath . 'schema/task-types.php';
$types = file_exists($schemaFile) ? (require $schemaFile)['core'] ?? [] : [];

$tPos = 0;
foreach ($types as $typeKey => $typeData) {
    /** @var MxBoardTaskType|null $type */
    $type = $modx->getObject(MxBoardTaskType::class, ['department_id' => $departmentId, 'key' => $typeKey]);
    if ($type) {
        $tPos++;
        continue;
    }

    /** @var MxBoardTaskType $type */
    $type = $modx->newObject(MxBoardTaskType::class);
    $type->fromArray([
        'department_id' => $departmentId,
        'key' => $typeKey,
        'name' => $typeData['name'],
        'description' => $typeData['description'] ?? '',
        'active' => true,
        'position' => $tPos++,
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
            // options нужны `select`-полям (severity): без них поле придёт в форму
            // пустым списком и не отрисуется.
            'options' => $fieldData['options'] ?? null,
            'createdon' => $now,
        ]);
        $field->save();
    }
}

/* --- Колонки: общий шаблон и конкретный проект ------------------------------ */

// Шесть стадий — рабочий цикл с агентом-исполнителем и двумя ручными гейтами автора:
// задачи копятся в бэклоге, автор даёт старт, исполнитель выносит план, автор
// подтверждает его переводом «В работе», исполнитель сдаёт результат на проверку.
// Инвариант: ровно одна initial (backlog) и ровно одна final (done).
// Исполнитель назначается при создании — карточка сразу именная, «свободного пула» нет.
//
// Отдельных стадий «Согласование» и «Отменена» в наборе нет намеренно. Согласование
// плана выражается комментариями на `plan`, а разрешением реализовывать — переводом
// в `in_progress`; отдельный гейт между ними дублировал бы этот переход. Отложенную
// задачу возвращают в `backlog`, ненужную удаляют — стадия-тупик не нужна.
//
// Описание стадии (`description`) — инструкция тому, чей ход наступил: доска для
// агентов, и агент читает её как постановку следующего шага.
$columns = [
    // Бэклог: новая карточка падает сюда (исполнитель уже назначен). Постановка ещё
    // дорабатывается — работа НЕ начата, внешние интеграции агента отсюда не будят.
    [
        'key' => 'backlog',
        'name' => 'Бэклог',
        'position' => 0,
        'move_roles' => 'assignee,author',
        'is_initial' => true,
        'description' => 'Накопитель задач. Работа не начата: карточку можно дорабатывать и уточнять сколько угодно. Сюда же возвращают задачу, которую решили сделать позже. Ненужную задачу удаляют, а не переводят в отдельную стадию.',
    ],
    // Старт: ручной гейт запуска. Только автор (или менеджер) даёт исполнителю зелёный
    // свет — важно, когда исполнитель агент: он может быть занят или без токенов.
    [
        'key' => 'to_start',
        'name' => 'Старт',
        'position' => 1,
        'move_roles' => 'author',
        'description' => 'Ты исполнитель. Автор дал старт: напиши в комментарии, что начал выполнение, изучи постановку и подготовь план. Готовый план оставь комментарием и переведи карточку в `plan`.',
    ],
    // План: исполнитель изучил задачу и вынес план работ на подтверждение. Двигает исполнитель.
    [
        'key' => 'plan',
        'name' => 'План',
        'position' => 2,
        'move_roles' => 'assignee',
        'description' => 'Ты автор. Исполнитель вынес план на проверку. План устраивает — переведи карточку в `in_progress`, это и есть разрешение реализовывать. Не устраивает — оставь замечания комментарием, стадию не меняй.',
    ],
    // В работе: второй ручной гейт. Переводит автор — перевод и означает «план принят,
    // можно писать код». Не согласен — комментирует, стадию не меняет.
    [
        'key' => 'in_progress',
        'name' => 'В работе',
        'position' => 3,
        'move_roles' => 'author',
        'description' => 'Ты исполнитель. План подтверждён автором — реализуй его. По завершении напиши отчёт комментарием и переведи карточку в `review`.',
    ],
    // На проверке: исполнитель сдал результат. Двигает исполнитель — автор с замечаниями
    // возвращает карточку в `in_progress`.
    [
        'key' => 'review',
        'name' => 'На проверке',
        'position' => 4,
        'move_roles' => 'assignee',
        'description' => 'Ты автор. Проверь результат по постановке. Всё в порядке — переводи в `done`. Есть замечания — комментарий и возврат в `in_progress`.',
    ],
    // Готово: закрывает ТОЛЬКО автор задачи (или менеджер). Исполнитель сюда не дотянется.
    [
        'key' => 'done',
        'name' => 'Готово',
        'position' => 5,
        'move_roles' => 'author',
        'is_final' => true,
        'description' => 'Финальная стадия. Автор принял работу, задача закрыта.',
    ],
];

// Идемпотентно: создаём только недостающие колонки. Существующие не трогаем совсем —
// ни position, ни move_roles, ни имя. Стадии и права переходов настраивают под свой
// процесс, и апгрейд пакета не имеет права затирать эту настройку (до 2.7.0 затирал).
$seedColumns = static function (int $projectId) use ($modx, $columns, $now): void {
    foreach ($columns as $data) {
        /** @var MxBoardColumn|null $existing */
        $existing = $modx->getObject(MxBoardColumn::class, ['project_id' => $projectId, 'key' => $data['key']]);
        if ($existing) {
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

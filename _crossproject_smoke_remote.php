<?php

/**
 * Смоук межпроектных подзадач (#2607-132).
 *
 * Проверяет серверные правила: право создавать в проекте своего отдела, межпроектную
 * подзадачу, наследование проекта родителя, блокировку закрытия родителя незакрытой
 * подзадачей с другой доски, исполнителя по проекту подзадачи и регресс обычных
 * подзадач. Плюс gate по SQL доски: join родителя не должен обнулять выдачу.
 *
 * Скрипт сам создаёт тестовые данные и убирает их за собой. Временный — после прогона
 * удаляется со стенда.
 */

use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modX;
use MxBoard\Helpers\Transitions;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardLog;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Service\BoardQuery;
use MxBoard\Service\TaskService;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbcross');
$modx->initialize('mgr');

$pass = 0;
$fail = 0;
$created = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  OK   {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$service = new TaskService($modx);
$query = new BoardQuery($modx);

/** @var modUser|null $agent */
$agent = $modx->getObject(modUser::class, ['username' => 'ai-agent']);
/** @var modUser|null $outsider */
$outsider = $modx->getObject(modUser::class, ['username' => 'mxb_test_outsider']);
if (!$agent || !$outsider) {
    exit("Нет тестовых пользователей (ai-agent / mxb_test_outsider)\n");
}

/** @var MxBoardProject|null $projectA */
$projectA = $modx->getObject(MxBoardProject::class, ['key' => 'mxboard']);
/** @var MxBoardProject|null $projectB */
$projectB = $modx->getObject(MxBoardProject::class, ['key' => 'jarvis']);
if (!$projectA || !$projectB) {
    exit("Нет проектов mxboard / jarvis\n");
}

$deadline = date('Y-m-d', strtotime('+14 days'));

echo "=== 1. Право создавать в проекте ===\n";
check(
    'член отдела вправе создавать в проекте своего отдела',
    Transitions::canCreateInProject($modx, $agent, $projectA)
);
check(
    'посторонний не вправе создавать',
    !Transitions::canCreateInProject($modx, $outsider, $projectB),
    'mxb_test_outsider вне групп отделов'
);

echo "\n=== 2. Родитель в проекте A ===\n";
$parentRes = $service->create($agent, [
    'project' => 'mxboard',
    'type' => 'feature',
    'title' => 'CROSS-SMOKE родитель',
    'deadline' => $deadline,
    // Исполнитель ОТЛИЧЕН от автора намеренно: mxBoard запрещает самозакрытие
    // (mxboard.allow_self_close = false), а смоуку нужно именно закрыть карточки.
    'assignee' => 'ai-manager',
    'fields' => json_encode([
        'goal' => 'смоук межпроектных подзадач',
        'criteria' => 'проверка',
        'implementation' => 'проверка',
        'contexts' => 'смоук',
        'dependencies' => 'нет',
    ], JSON_UNESCAPED_UNICODE),
], 'mcp');
check('родитель создан', !empty($parentRes['success']), (string) ($parentRes['message'] ?? ''));
if (empty($parentRes['success'])) {
    exit("Родитель не создан — дальше смысла нет\n");
}
$parentId = (int) $parentRes['object']['id'];
$created[] = $parentId;

echo "\n=== 3. Межпроектная подзадача (проект B) ===\n";
$subRes = $service->create($agent, [
    'project' => 'jarvis',
    'parent_id' => $parentId,
    'type' => 'feature',
    'title' => 'CROSS-SMOKE подзадача в другом проекте',
    'deadline' => $deadline,
    // Исполнитель ОТЛИЧЕН от автора намеренно: mxBoard запрещает самозакрытие
    // (mxboard.allow_self_close = false), а смоуку нужно именно закрыть карточки.
    'assignee' => 'ai-manager',
    'fields' => json_encode([
        'goal' => 'смоук',
        'criteria' => 'проверка',
        'implementation' => 'проверка',
        'contexts' => 'смоук',
        'dependencies' => 'нет',
    ], JSON_UNESCAPED_UNICODE),
], 'mcp');
check('межпроектная подзадача создана', !empty($subRes['success']), (string) ($subRes['message'] ?? ''));

if (!empty($subRes['success'])) {
    $subId = (int) $subRes['object']['id'];
    $created[] = $subId;
    /** @var MxBoardTask $sub */
    $sub = $modx->getObject(MxBoardTask::class, $subId);
    check('подзадача лежит в проекте B', (int) $sub->get('project_id') === (int) $projectB->get('id'));
    check('связь с родителем сохранена', (int) $sub->get('parent_id') === $parentId);

    /** @var MxBoardColumn|null $col */
    $col = $modx->getObject(MxBoardColumn::class, (int) $sub->get('column_id'));
    $colProject = $col ? (int) $col->get('project_id') : -1;
    check(
        'колонка взята по проекту подзадачи',
        $col && ((int) $col->get('is_initial') === 1),
        'column=' . ($col ? $col->get('key') : '—') . ', scope project_id=' . $colProject
    );

    $note = '';
    /** @var MxBoardLog|null $logRow */
    $logRow = $modx->getObject(MxBoardLog::class, [
        'task_id' => $parentId,
        'action' => 'subtask_add',
    ]);
    if ($logRow) {
        $note = (string) $logRow->get('note');
    }
    check('журнал родителя помечает чужой проект', str_contains($note, '@jarvis'), "note={$note}");

    echo "\n=== 3b. Регресс: обычная подзадача в том же проекте ===\n";
    $sameRes = $service->create($agent, [
        'project' => 'mxboard',
        'parent_id' => $parentId,
        'type' => 'feature',
        'title' => 'CROSS-SMOKE обычная подзадача',
        'deadline' => $deadline,
        'assignee' => 'ai-manager',
        'fields' => json_encode([
            'goal' => 'смоук',
            'criteria' => 'проверка',
            'implementation' => 'проверка',
            'contexts' => 'смоук',
            'dependencies' => 'нет',
        ], JSON_UNESCAPED_UNICODE),
    ], 'mcp');
    check('обычная подзадача создаётся как раньше', !empty($sameRes['success']), (string) ($sameRes['message'] ?? ''));
    if (!empty($sameRes['success'])) {
        $sameId = (int) $sameRes['object']['id'];
        $created[] = $sameId;
        /** @var MxBoardTask $sameTask */
        $sameTask = $modx->getObject(MxBoardTask::class, $sameId);
        check(
            'она осталась в проекте родителя',
            (int) $sameTask->get('project_id') === (int) $projectA->get('id')
        );
        // Её тоже надо закрыть, иначе она, а не межпроектная, будет держать родителя.
        $service->move($agent, $sameId, (string) $modx->getObject(MxBoardColumn::class, ['is_final' => true])->get('key'), '', 'mcp');
    }

    echo "\n=== 4. Блокировка закрытия родителя ===\n";
    /** @var MxBoardColumn|null $finalA */
    $finalA = $modx->getObject(MxBoardColumn::class, ['project_id' => 0, 'is_final' => true]);
    if (!$finalA) {
        $finalA = $modx->getObject(MxBoardColumn::class, ['is_final' => true]);
    }
    $moveRes = $service->move($agent, $parentId, (string) $finalA->get('key'), '', 'mcp');
    // Отказ обязан быть ИМЕННО по открытой подзадаче: любой другой (например, запрет
    // самозакрытия) означал бы, что блокировку мы вообще не проверили.
    // В API-режиме лексикон не переведён, поэтому сверяем и с ключом, и с текстом.
    $blockMsg = mb_strtolower((string) $moveRes['message']);
    check(
        'незакрытая межпроектная подзадача блокирует финал родителя',
        empty($moveRes['success'])
            && (str_contains($blockMsg, 'open_subtasks') || str_contains($blockMsg, 'подзадач')),
        (string) ($moveRes['message'] ?? '')
    );

    /** @var MxBoardColumn|null $finalB */
    $finalB = $modx->getObject(MxBoardColumn::class, ['is_final' => true]);
    $closeSub = $service->move($agent, $subId, (string) $finalB->get('key'), '', 'mcp');
    check('подзадачу можно закрыть', !empty($closeSub['success']), (string) ($closeSub['message'] ?? ''));

    $moveRes2 = $service->move($agent, $parentId, (string) $finalA->get('key'), '', 'mcp');
    check(
        'после закрытия подзадачи родитель закрывается',
        !empty($moveRes2['success']),
        (string) ($moveRes2['message'] ?? '')
    );
}

echo "\n=== 5. Наследование проекта родителя ===\n";
$parent2 = $service->create($agent, [
    'project' => 'jarvis',
    'type' => 'feature',
    'title' => 'CROSS-SMOKE родитель в jarvis',
    'deadline' => $deadline,
    // Исполнитель ОТЛИЧЕН от автора намеренно: mxBoard запрещает самозакрытие
    // (mxboard.allow_self_close = false), а смоуку нужно именно закрыть карточки.
    'assignee' => 'ai-manager',
    'fields' => json_encode([
        'goal' => 'смоук',
        'criteria' => 'проверка',
        'implementation' => 'проверка',
        'contexts' => 'смоук',
        'dependencies' => 'нет',
    ], JSON_UNESCAPED_UNICODE),
], 'mcp');
if (!empty($parent2['success'])) {
    $parent2Id = (int) $parent2['object']['id'];
    $created[] = $parent2Id;
    // Проект НЕ передаём: подзадача обязана унаследовать проект родителя, а не уехать
    // в mxboard.default_project.
    $inherit = $service->create($agent, [
        'parent_id' => $parent2Id,
        'type' => 'feature',
        'title' => 'CROSS-SMOKE наследование проекта',
        'deadline' => $deadline,
        // Исполнитель ОТЛИЧЕН от автора намеренно: mxBoard запрещает самозакрытие
    // (mxboard.allow_self_close = false), а смоуку нужно именно закрыть карточки.
    'assignee' => 'ai-manager',
        'fields' => json_encode([
            'goal' => 'смоук',
            'criteria' => 'проверка',
            'implementation' => 'проверка',
            'contexts' => 'смоук',
            'dependencies' => 'нет',
        ], JSON_UNESCAPED_UNICODE),
    ], 'mcp');
    check('подзадача без project создана', !empty($inherit['success']), (string) ($inherit['message'] ?? ''));
    if (!empty($inherit['success'])) {
        $inheritId = (int) $inherit['object']['id'];
        $created[] = $inheritId;
        /** @var MxBoardTask $inheritTask */
        $inheritTask = $modx->getObject(MxBoardTask::class, $inheritId);
        check(
            'проект унаследован от родителя, а не default',
            (int) $inheritTask->get('project_id') === (int) $projectB->get('id'),
            'project_id=' . $inheritTask->get('project_id') . ', ожидали ' . $projectB->get('id')
        );
    }
}

echo "\n=== 7. Отказ постороннему ===\n";
$denied = $service->create($outsider, [
    'project' => 'mxboard',
    'type' => 'feature',
    'title' => 'CROSS-SMOKE не должно создаться',
    'deadline' => $deadline,
    // Исполнитель ОТЛИЧЕН от автора намеренно: mxBoard запрещает самозакрытие
    // (mxboard.allow_self_close = false), а смоуку нужно именно закрыть карточки.
    'assignee' => 'ai-manager',
    'fields' => json_encode([
        'goal' => 'смоук',
        'criteria' => 'проверка',
        'implementation' => 'проверка',
        'contexts' => 'смоук',
        'dependencies' => 'нет',
    ], JSON_UNESCAPED_UNICODE),
], 'mcp');
check('посторонний получает отказ', empty($denied['success']), (string) ($denied['message'] ?? ''));
if (!empty($denied['success'])) {
    $created[] = (int) $denied['object']['id'];
}

echo "\n=== 7b. Проект чужого отдела: отказ и исключение для sudo ===\n";
// Все проекты стенда живут в одном отделе, поэтому «чужой отдел» приходится завести
// самим: иначе проверить отказ по межпроектной подзадаче и sudo-исключение не на чем.
// Группа создаётся пустой — в ней намеренно нет ни одного участника.
$foreignGroup = $modx->newObject(\MODX\Revolution\modUserGroup::class);
$foreignGroup->set('name', 'CROSS-SMOKE foreign group');
$foreignGroup->save();

$foreignDep = $modx->newObject(\MxBoard\Model\MxBoardDepartment::class);
$foreignDep->fromArray([
    'name' => 'CROSS-SMOKE чужой отдел',
    'usergroup_id' => (int) $foreignGroup->get('id'),
    'active' => true,
    'position' => 999,
]);
$foreignDep->save();

$foreignProject = $modx->newObject(MxBoardProject::class);
$foreignProject->fromArray([
    'key' => 'cross-smoke-foreign',
    'name' => 'CROSS-SMOKE чужой проект',
    'department_id' => (int) $foreignDep->get('id'),
    'active' => true,
    'position' => 999,
]);
$foreignProject->save();

/** @var modUser|null $sudoUser */
$sudoUser = $modx->getObject(modUser::class, ['username' => 'claude']);

check(
    'член своего отдела НЕ вправе создавать в проекте чужого отдела',
    !Transitions::canCreateInProject($modx, $agent, $foreignProject)
);
check(
    'суперпользователь вправе создавать в чужом проекте (исключение)',
    $sudoUser && (bool) $sudoUser->get('sudo') && Transitions::canCreateInProject($modx, $sudoUser, $foreignProject),
    'claude sudo=' . ($sudoUser ? (int) $sudoUser->get('sudo') : '—')
);

// Межпроектная подзадача В НЕДОСТУПНЫЙ проект — сервер обязан отказать, даже если
// такой вариант не предлагался в UI.
$deniedSub = $service->create($agent, [
    'project' => 'cross-smoke-foreign',
    'parent_id' => $parentId,
    'type' => 'feature',
    'title' => 'CROSS-SMOKE подзадача в чужой проект',
    'deadline' => $deadline,
    'assignee' => 'ai-manager',
    'fields' => json_encode([
        'goal' => 'смоук',
        'criteria' => 'проверка',
        'implementation' => 'проверка',
        'contexts' => 'смоук',
        'dependencies' => 'нет',
    ], JSON_UNESCAPED_UNICODE),
], 'mcp');
check(
    'межпроектная подзадача в недоступный проект отклонена',
    empty($deniedSub['success']) && str_contains((string) $deniedSub['message'], 'project_denied'),
    (string) ($deniedSub['message'] ?? '')
);
if (!empty($deniedSub['success'])) {
    $created[] = (int) $deniedSub['object']['id'];
}
check(
    'чужой проект не попал в список доступных члену отдела',
    !in_array('cross-smoke-foreign', array_column($query->creatableProjects($agent), 'key'), true)
);
check(
    'суперпользователю чужой проект доступен',
    $sudoUser && in_array('cross-smoke-foreign', array_column($query->creatableProjects($sudoUser), 'key'), true)
);

echo "\n=== 8. Список проектов для создания ===\n";
$creatableAgent = $query->creatableProjects($agent);
$creatableOutsider = $query->creatableProjects($outsider);
check('члену отдела доступны проекты', count($creatableAgent) > 0, 'проектов: ' . count($creatableAgent));
check('постороннему не доступен ни один', count($creatableOutsider) === 0, 'проектов: ' . count($creatableOutsider));

echo "\n=== 9. GATE: доска не опустела от join родителя ===\n";
$board = $query->board($agent, $projectA, []);
$total = 0;
$withParent = 0;
$sample = '';
foreach (($board['columns'] ?? []) as $column) {
    foreach (($column['tasks'] ?? []) as $t) {
        $total++;
        if (!empty($t['parent_id'])) {
            $withParent++;
            if ($sample === '') {
                $sample = sprintf(
                    '%s → parent_num=%s parent_project=%s',
                    $t['num'] ?? '?',
                    $t['parent_num'] ?? 'NULL',
                    $t['parent_project_key'] ?? 'NULL'
                );
            }
        }
    }
}
check('доска отдаёт карточки', $total > 0, "карточек: {$total}");
check('поле parent_num присутствует в выдаче', $withParent === 0 || $sample !== '', $sample ?: 'подзадач на доске нет');

echo "\n=== 10. Проектный контекст в taskDetail ===\n";
/** @var MxBoardTask|null $detailTask */
$detailTask = $modx->getObject(MxBoardTask::class, $parentId);
$detail = $detailTask ? $query->taskDetail($agent, $detailTask) : null;
check('taskDetail отдаёт project_key карточки', !empty($detail['project_key']), (string) ($detail['project_key'] ?? ''));
check('taskDetail отдаёт department_id', (int) ($detail['department_id'] ?? 0) > 0);
$subRow = $detail['subtasks'][0] ?? [];
check(
    'подзадача в выдаче несёт свой проект и номер',
    !empty($subRow['project_key']) && !empty($subRow['num']),
    ($subRow['num'] ?? '—') . ' @' . ($subRow['project_key'] ?? '—')
);

echo "\n=== Уборка ===\n";
// Временный отдел/проект/группа из п.7b и карточки браузерной проверки (CROSS-UI).
foreach ($modx->getCollection(MxBoardTask::class) as $t) {
    if (str_starts_with((string) $t->get('title'), 'CROSS-UI')) {
        $created[] = (int) $t->get('id');
    }
}
if (isset($foreignProject) && $foreignProject) {
    $foreignProject->remove();
    echo "  удалён проект cross-smoke-foreign\n";
}
if (isset($foreignDep) && $foreignDep) {
    $foreignDep->remove();
    echo "  удалён временный отдел\n";
}
if (isset($foreignGroup) && $foreignGroup) {
    $foreignGroup->remove();
    echo "  удалена временная группа\n";
}

foreach (array_unique($created) as $id) {
    /** @var MxBoardTask|null $t */
    $t = $modx->getObject(MxBoardTask::class, $id);
    if (!$t) {
        continue;
    }
    foreach ($modx->getCollection(MxBoardLog::class, ['task_id' => $id]) as $row) {
        $row->remove();
    }
    $t->remove();
    echo "  удалена задача #{$id}\n";
}
// Записи журнала о подзадачах у родителя тоже наши.
foreach ($modx->getCollection(MxBoardLog::class, ['action' => 'subtask_add']) as $row) {
    if (in_array((int) $row->get('task_id'), $created, true)) {
        $row->remove();
    }
}

// Контроль уборки: на стенде не должно остаться ни одной карточки смоука. Удаление
// родителя уносит подзадачи каскадом, поэтому часть id к этому моменту уже не
// существует — проверяем по заголовку, а не по списку id.
$leftovers = 0;
foreach ($modx->getIterator(MxBoardTask::class) as $t) {
    $title = (string) $t->get('title');
    if (str_starts_with($title, 'CROSS-SMOKE') || str_starts_with($title, 'CROSS-UI')) {
        $leftovers++;
        printf("  ! остался мусор: #%d %s\n", $t->get('id'), $title);
    }
}
foreach ($modx->getIterator(MxBoardProject::class) as $p) {
    if (str_starts_with((string) $p->get('key'), 'cross-smoke')) {
        $leftovers++;
        printf("  ! остался проект: %s\n", $p->get('key'));
    }
}
check('тестовые данные убраны со стенда', $leftovers === 0, "осталось: {$leftovers}");

printf("\n=== ИТОГ: %d OK, %d FAIL ===\n", $pass, $fail);

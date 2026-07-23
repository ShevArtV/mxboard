<?php

/**
 * Проверка серверной пагинации обзора отдела (карточка #2607-107, блокер по #2607-105).
 *
 * Дёргает BoardQuery::departmentTasks() напрямую от имени менеджера отдела и печатает
 * состав страниц: так видно, что страница считается в SQL, а не режется в браузере.
 * Временный скрипт — после проверки удаляется со стенда.
 */

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Service\BoardQuery;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbpaging');
$modx->initialize('mgr');

$username = $argv[1] ?? 'ai-manager';
$departmentId = (int) ($argv[2] ?? 0);

/** @var modUser|null $user */
$user = $modx->getObject(modUser::class, ['username' => $username]);
if (!$user) {
    exit("Нет пользователя {$username}\n");
}

$q = new BoardQuery($modx);

if ($departmentId <= 0) {
    foreach ($q->managedDepartments($user) as $d) {
        echo "department: {$d['id']} {$d['name']}\n";
    }
    exit(0);
}

function show(array $res, string $label): void
{
    $nums = array_map(static fn (array $t): string => $t['num'], $res['tasks']);
    printf(
        "%-28s total=%d page=%d per_page=%d sort=%s %s rows=%d | %s\n",
        $label,
        $res['total'],
        $res['page'],
        $res['per_page'],
        $res['sort_by'] !== '' ? $res['sort_by'] : '(default)',
        $res['sort_dir'],
        count($res['tasks']),
        implode(',', array_slice($nums, 0, 6))
    );
}

show($q->departmentTasks($user, $departmentId, [], 1, 25), 'page 1/25 default');
show($q->departmentTasks($user, $departmentId, [], 2, 25), 'page 2/25 default');
show($q->departmentTasks($user, $departmentId, [], 3, 25), 'page 3/25 default');
show($q->departmentTasks($user, $departmentId, [], 1, 50), 'page 1/50 default');
show($q->departmentTasks($user, $departmentId, [], 2, 50), 'page 2/50 default');
show($q->departmentTasks($user, $departmentId, [], 1, 100), 'page 1/100 default');

show($q->departmentTasks($user, $departmentId, [], 1, 25, 'num', 'ASC'), 'page 1/25 num ASC');
show($q->departmentTasks($user, $departmentId, [], 1, 25, 'num', 'DESC'), 'page 1/25 num DESC');
show($q->departmentTasks($user, $departmentId, [], 1, 25, 'deadlineon', 'ASC'), 'page 1/25 deadline ASC');
show($q->departmentTasks($user, $departmentId, [], 1, 25, 'title', 'ASC'), 'page 1/25 title ASC');
show($q->departmentTasks($user, $departmentId, [], 1, 25, 'column_name', 'ASC'), 'page 1/25 stage ASC');
show($q->departmentTasks($user, $departmentId, [], 1, 25, 'author', 'ASC'), 'page 1/25 author ASC');
show($q->departmentTasks($user, $departmentId, [], 1, 25, 'assignee', 'DESC'), 'page 1/25 assignee DESC');
show($q->departmentTasks($user, $departmentId, [], 1, 25, 'type_name', 'ASC'), 'page 1/25 type ASC');
show($q->departmentTasks($user, $departmentId, [], 1, 25, 'plan_hours', 'DESC'), 'page 1/25 plan DESC');
show($q->departmentTasks($user, $departmentId, [], 1, 25, 'fact_hours', 'DESC'), 'page 1/25 fact DESC');
show($q->departmentTasks($user, $departmentId, [], 1, 25, 'fact_hours', 'ASC'), 'page 1/25 fact ASC');

// Защита от подделанного запроса и от «страницы, которой уже нет».
show($q->departmentTasks($user, $departmentId, [], 1, 100000), 'per_page=100000 -> default');
show($q->departmentTasks($user, $departmentId, [], 999, 25), 'page=999 -> last page');
show($q->departmentTasks($user, $departmentId, [], 0, 25), 'page=0 -> first page');
show($q->departmentTasks($user, $departmentId, [], 1, 25, 'id; DROP TABLE', 'ASC'), 'sort_by=мусор -> default');

// Фильтр + сортировка + страница вместе.
$stages = array_column($q->departmentStages($departmentId), 'key');
$filtered = ['stage' => array_slice($stages, 0, 1)];
show($q->departmentTasks($user, $departmentId, $filtered, 1, 25, 'num', 'ASC'), 'filter+sort page 1');
show($q->departmentTasks($user, $departmentId, $filtered, 2, 25, 'num', 'ASC'), 'filter+sort page 2');
echo 'filter: stage IN (' . implode(',', $filtered['stage']) . ")\n";

// Полнота обхода: страницы обязаны в сумме дать ВСЮ выдачу без дублей и пропусков —
// именно это ломается, когда у сортировки нет устойчивого тай-брейка.
foreach ([['', 'DESC'], ['num', 'ASC'], ['column_name', 'ASC'], ['fact_hours', 'DESC'], ['plan_hours', 'DESC']] as [$by, $dir]) {
    $seen = [];
    $page = 1;
    $total = 0;
    do {
        $res = $q->departmentTasks($user, $departmentId, [], $page, 25, $by, $dir);
        $total = $res['total'];
        foreach ($res['tasks'] as $t) {
            $seen[] = (int) $t['id'];
        }
        ++$page;
    } while (($page - 1) * 25 < $total);

    $uniq = array_unique($seen);
    printf(
        "walk %-12s %s: собрано=%d уникальных=%d total=%d -> %s\n",
        $by !== '' ? $by : '(default)',
        $dir,
        count($seen),
        count($uniq),
        $total,
        count($seen) === $total && count($uniq) === $total ? 'OK' : 'РАСХОЖДЕНИЕ'
    );
}

// Контрольное количество прямым SQL — мимо BoardQuery.
$sql = 'SELECT COUNT(*) FROM ' . $modx->getTableName(\MxBoard\Model\MxBoardTask::class) . ' t'
    . ' INNER JOIN ' . $modx->getTableName(\MxBoard\Model\MxBoardProject::class) . ' p ON p.id = t.project_id'
    . ' WHERE p.department_id = ' . $departmentId . ' AND p.active = 1';
echo 'raw SQL count = ' . (int) $modx->query($sql)->fetchColumn() . "\n";

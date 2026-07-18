<?php

/**
 * Точечная проверка ИИ-проверки полноты постановки на стенде.
 * Запуск: /usr/local/php/php-8.3/bin/php _ai_verify_remote.php
 *
 * Переиспользует сид smoke (проект default, тип bugfix, тестовые юзеры).
 * Прописывает настройки ИИ, временно включает ai_check у bugfix, гоняет два create
 * (размытый → блок, полный → проход), проверяет вердикт+журнал, затем откатывает.
 * Ключ передаётся аргументом, в репозиторий не коммитится.
 */

use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardLog;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;
use MxBoard\Service\TaskService;

define('MODX_API_MODE', true);
require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbaiverify');
$modx->initialize('mgr');
$modx->getService('lexicon', 'modLexicon');
$modx->lexicon->load('mxboard:default');

$corePath = MODX_CORE_PATH . 'components/mxboard/';
if (is_file($corePath . 'vendor/autoload.php')) {
    require_once $corePath . 'vendor/autoload.php';
}
if (!isset($modx->packages['MxBoard\\Model'])) {
    $modx->addPackage('MxBoard\\Model', $corePath . 'src/', null, 'MxBoard\\');
}

$BASE_URL = $argv[1] ?? '';
$API_KEY = $argv[2] ?? '';
$MODEL = $argv[3] ?? 'mimo-v2.5';
if ($BASE_URL === '' || $API_KEY === '') {
    fwrite(STDERR, "usage: php _ai_verify_remote.php <base_url> <api_key> [model]\n");
    exit(2);
}

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function upsertSetting(modX $modx, string $key, string $value): void
{
    $s = $modx->getObject(modSystemSetting::class, $key) ?: $modx->newObject(modSystemSetting::class);
    $s->fromArray(['key' => $key, 'value' => $value, 'xtype' => 'textfield', 'namespace' => 'mxboard', 'area' => 'mxboard_ai'], '', true, true);
    $s->save();
    $modx->setOption($key, $value); // немедленный эффект в этом процессе
}

echo "== mxBoard: проверка ИИ-проверки полноты ==\n";

// 0. Колонки в БД.
$tt = $modx->getTableName(MxBoardTaskType::class);
$cols = [];
foreach ($modx->query("SHOW COLUMNS FROM {$tt}")->fetchAll(PDO::FETCH_COLUMN) as $c) { $cols[] = $c; }
check('колонка task_type.ai_check', in_array('ai_check', $cols, true));
check('колонка task_type.ai_prompt', in_array('ai_prompt', $cols, true));
$tk = $modx->getTableName(MxBoardTask::class);
$tcols = $modx->query("SHOW COLUMNS FROM {$tk}")->fetchAll(PDO::FETCH_COLUMN);
check('колонка task.ai_verdict', in_array('ai_verdict', $tcols, true));

// 1. Настройки ИИ.
upsertSetting($modx, 'mxboard.ai_base_url', $BASE_URL);
upsertSetting($modx, 'mxboard.ai_api_key', $API_KEY);
upsertSetting($modx, 'mxboard.ai_model', $MODEL);
upsertSetting($modx, 'mxboard.ai_check_mode', 'strict');
check('mxboard.ai_check_prompt задан', trim((string) $modx->getOption('mxboard.ai_check_prompt', null, '')) !== '');

// 2. Сид.
$project = $modx->getObject(MxBoardProject::class, ['key' => 'default']);
if (!$project) { echo "нет проекта default — сначала запусти _smoke_remote.php\n"; exit(1); }
$bug = $modx->getObject(MxBoardTaskType::class, ['key' => 'bugfix']);
if (!$bug) { echo "нет типа bugfix\n"; exit(1); }
$department = $modx->getObject(MxBoardDepartment::class, (int) $project->get('department_id'));
$usergroupId = $department ? (int) $department->get('usergroup_id') : 0;

$ensureUser = function (string $username) use ($modx): modUser {
    $u = $modx->getObject(modUser::class, ['username' => $username]);
    if (!$u) {
        $u = $modx->newObject(modUser::class);
        $u->set('username', $username);
        $p = $modx->newObject(modUserProfile::class);
        $p->set('email', $username . '@mxboard.test');
        $u->addOne($p);
        $u->set('active', 1);
        $u->save();
    }
    return $u;
};
$joinDept = function (modUser $u, int $gid) use ($modx): void {
    if ($gid > 0 && !$modx->getObject(modUserGroupMember::class, ['member' => $u->get('id'), 'user_group' => $gid])) {
        $m = $modx->newObject(modUserGroupMember::class);
        $m->fromArray(['user_group' => $gid, 'member' => (int) $u->get('id'), 'role' => 0, 'rank' => 0]);
        $m->save();
    }
};

$author = $ensureUser('mxb_test_author');
$worker = $ensureUser('mxb_test_worker');
$joinDept($author, $usergroupId);
$joinDept($worker, $usergroupId);

$prevAiCheck = (bool) $bug->get('ai_check');
$bug->set('ai_check', true);
$bug->save();

$service = new TaskService($modx);
$DEADLINE = time() + 7 * 86400;
$assignee = (int) $worker->get('id');
$created = [];

// 3. Размытая постановка → ждём блок (strict).
$vague = $service->create($author, [
    'type' => 'bugfix',
    'title' => 'сломался сайт не работает оплата stripe',
    'deadline' => $DEADLINE,
    'assignee_id' => $assignee,
    'fields' => ['where' => 'хз', 'what' => 'не работает', 'steps' => '-', 'expected' => '-'],
], 'api');
check('размытая задача заблокирована', !$vague['success'], (string) $vague['message']);
check('в ответе вердикт (ai_incomplete)', is_array($vague['object']) && !empty($vague['object']['ai_incomplete']),
    json_encode($vague['object'], JSON_UNESCAPED_UNICODE));
if (is_array($vague['object']) && isset($vague['object']['verdict'])) {
    echo "       verdict: " . json_encode($vague['object']['verdict'], JSON_UNESCAPED_UNICODE) . "\n";
}

// 4. Полная постановка → ждём проход + вердикт в задаче + журнал.
$good = $service->create($author, [
    'type' => 'bugfix',
    'title' => 'Корзина падает при добавлении товара из акции',
    'tor' => 'При добавлении акционного товара в корзину страница отдаёт 500.',
    'deadline' => $DEADLINE,
    'assignee_id' => $assignee,
    'fields' => [
        'where' => 'assets/components/minishop2/js/web/minishop.js, обработчик addToCart; лог PHP core/cache/logs/error.log',
        'what' => 'Fatal: Call to a member function getPrice() on null при товаре со скидкой',
        'steps' => '1. Открыть /catalog. 2. Добавить товар с плашкой «акция». 3. Открыть /cart — 500.',
        'expected' => 'Товар добавляется, корзина открывается без ошибки, цена со скидкой корректна.',
    ],
], 'api');
check('полная задача прошла', $good['success'], (string) $good['message']);
$goodId = (int) ($good['object']['id'] ?? 0);
if ($goodId) { $created[] = $goodId; }

$task = $goodId ? $modx->getObject(MxBoardTask::class, $goodId) : null;
$verdict = $task ? $task->get('ai_verdict') : null;
check('вердикт сохранён в задаче', is_array($verdict) && array_key_exists('complete', $verdict),
    json_encode($verdict, JSON_UNESCAPED_UNICODE));
check('журнал ai_check по задаче есть', $goodId > 0 &&
    $modx->getCount(MxBoardLog::class, ['task_id' => $goodId, 'action' => 'ai_check']) > 0);
check('журнал ai_check отказа (task_id=0) есть', $modx->getCount(MxBoardLog::class, ['task_id' => 0, 'action' => 'ai_check']) > 0);

// 5. Откат.
$bug->set('ai_check', $prevAiCheck);
$bug->save();
foreach ($created as $id) {
    $t = $modx->getObject(MxBoardTask::class, $id);
    if ($t) { $t->remove(); }
}
// Журнальные записи ai_check с task_id=0 оставляем как аудит; за собой чистим только задачи.
$modx->exec("DELETE FROM {$modx->getTableName(MxBoardLog::class)} WHERE task_id = 0 AND action = 'ai_check'");

echo "\n== Итог: {$pass} OK, {$fail} FAIL ==\n";
exit($fail > 0 ? 1 : 0);

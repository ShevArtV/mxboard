<?php

/**
 * Агентские учётки-исполнители на стенде: `codex-agent` и `opencode-agent`.
 *
 * Зачем. До 2026-07-20 единственным исполнителем на доске был `claude-agent` (#55),
 * а `codex` (#54) работал менеджером. Менеджеру некому было отдавать задачи, кроме
 * Claude, хотя часть работы дешевле и уместнее гонять через Codex и opencode.
 * Заводились предыдущие агенты руками в менеджере — операция невоспроизводимая и,
 * как показал разбор 2026-07-18, с граблями (роли/authority, мусорные юзеры в
 * группе отдела). Этот скрипт делает её повторяемой.
 *
 * Что делает для каждого агента:
 *   1. находит-или-создаёт активного modUser + modUserProfile;
 *   2. включает его в группу отдела с role=0 (обычный член) — именно членство в
 *      этой группе делает пользователя доступным как assignee, см.
 *      BoardQuery::departmentUsers();
 *   3. выпускает профильный токен по логике Processors/Mgr/Token/IssueForUser:
 *      сырой — в profile.extended.mxboard, sha256 — в mxboard_token.
 *
 * Роль СТРОГО role=0. Менеджерская роль с authority=1 уже занята Codex-менеджером,
 * а `authority` в modUserGroupRole — уникальный индекс: вторую такую роль создать
 * нельзя, save() при этом молча вернёт false (разбор 2026-07-18).
 *
 * Пароль не задаётся осмысленным: агент входит по Bearer-токену, пароль — случайные
 * 32 байта, которые нигде не сохраняются. Нужен вход в менеджер живым человеком —
 * сбрасывайте пароль штатно из менеджера.
 *
 * Сырые токены печатаются в stdout ОДИН раз (позже их можно посмотреть в профиле
 * пользователя в менеджере). Их место хранения — gitignored config/mxboard-agents.json.
 * В git токены не кладём.
 *
 * Запуск на стенде (из корня www/):
 *   /usr/local/php/php-8.3/bin/php scripts/stand/seed-agent-users.php
 *
 * Идемпотентен по пользователю и членству в группе. Токен при повторном запуске
 * НЕ перевыпускается, если он уже есть в профиле — чтобы случайный повтор не
 * обесточил работающего агента. Нужен новый токен — `--reissue`.
 */

use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardToken;

define('MODX_API_MODE', true);

require_once __DIR__ . '/../../config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbseedagentusers');
$modx->initialize('mgr');

$corePath = MODX_CORE_PATH . 'components/mxboard/';
if (is_file($corePath . 'vendor/autoload.php')) {
    require_once $corePath . 'vendor/autoload.php';
}
if (!isset($modx->packages['MxBoard\\Model'])) {
    $modx->addPackage('MxBoard\\Model', $corePath . 'src/', null, 'MxBoard\\');
}

$reissue = in_array('--reissue', $argv, true);

$agents = [
    [
        'username' => 'codex-agent',
        'fullname' => 'Codex (исполнитель)',
        'email' => 'codex-agent@mxboard.local',
    ],
    [
        'username' => 'opencode-agent',
        'fullname' => 'opencode (дешёвый ресёрч)',
        'email' => 'opencode-agent@mxboard.local',
    ],
];

/** @var MxBoardDepartment|null $department */
$department = $modx->getObject(MxBoardDepartment::class, ['active' => true]);
if (!$department) {
    exit("Отдел не найден — сначала поставьте пакет.\n");
}
$groupId = (int) $department->get('usergroup_id');
if ($groupId <= 0) {
    exit("У отдела #{$department->get('id')} не задана группа пользователей.\n");
}
echo "Отдел: {$department->get('name')} (группа #{$groupId})\n";

$now = time();
$issued = [];

foreach ($agents as $agent) {
    $username = $agent['username'];

    /** @var modUser|null $user */
    $user = $modx->getObject(modUser::class, ['username' => $username]);
    if (!$user) {
        /** @var modUser $user */
        $user = $modx->newObject(modUser::class);
        $user->fromArray([
            'username' => $username,
            'active' => true,
            'sudo' => false,
        ]);
        // set(), а не fromArray(): modUser::set перехватывает 'password' и хеширует
        // его вместе с солью — через fromArray в базу уехал бы открытый текст.
        $user->set('password', bin2hex(random_bytes(32)));
        if (!$user->save()) {
            echo "  FAIL: пользователь {$username} не создан\n";
            continue;
        }
        echo "  user +{$username} (#{$user->get('id')})\n";
    } else {
        if (!$user->get('active')) {
            $user->set('active', true);
            $user->save();
            echo "  user ~{$username}: снова активен\n";
        }
        echo "  ok: пользователь {$username} уже есть (#{$user->get('id')})\n";
    }
    $userId = (int) $user->get('id');

    /** @var modUserProfile|null $profile */
    $profile = $user->getOne('Profile');
    if (!$profile) {
        /** @var modUserProfile $profile */
        $profile = $modx->newObject(modUserProfile::class);
        $profile->fromArray([
            'internalKey' => $userId,
            'fullname' => $agent['fullname'],
            'email' => $agent['email'],
        ]);
        if (!$profile->save()) {
            echo "  FAIL: профиль {$username} не создан\n";
            continue;
        }
        echo "  profile +{$username}\n";
    }

    // Членство в группе отдела — это и есть «быть исполнителем» на доске.
    $member = $modx->getObject(modUserGroupMember::class, [
        'user_group' => $groupId,
        'member' => $userId,
    ]);
    if (!$member) {
        /** @var modUserGroupMember $member */
        $member = $modx->newObject(modUserGroupMember::class);
        $member->fromArray([
            'user_group' => $groupId,
            'member' => $userId,
            'role' => 0,
            'rank' => 0,
        ]);
        if (!$member->save()) {
            echo "  FAIL: {$username} не добавлен в группу #{$groupId}\n";
            continue;
        }
        echo "  group +{$username} → #{$groupId} (role=0)\n";
    } else {
        echo "  ok: {$username} уже в группе #{$groupId} (role={$member->get('role')})\n";
    }

    $extended = $profile->get('extended');
    if (!is_array($extended)) {
        $extended = [];
    }
    $prev = $extended['mxboard'] ?? [];
    $prevId = (int) ($prev['token_id'] ?? 0);

    if ($prevId > 0 && !$reissue) {
        echo "  ok: токен {$username} уже выпущен (id={$prevId}), --reissue для замены\n";
        continue;
    }

    if ($prevId > 0) {
        /** @var MxBoardToken|null $old */
        $old = $modx->getObject(MxBoardToken::class, $prevId);
        if ($old) {
            $old->remove();
        }
    }

    $raw = bin2hex(random_bytes(24));

    /** @var MxBoardToken $token */
    $token = $modx->newObject(MxBoardToken::class);
    $token->fromArray([
        'user_id' => $userId,
        'name' => 'profile',
        'token_hash' => hash('sha256', $raw),
        'active' => true,
        'lastusedon' => 0,
        'createdon' => $now,
    ]);
    if (!$token->save()) {
        echo "  FAIL: токен {$username} не сохранён\n";
        continue;
    }

    $extended['mxboard'] = [
        'token' => $raw,
        'token_id' => (int) $token->get('id'),
        'createdon' => $now,
    ];
    $profile->set('extended', $extended);
    if (!$profile->save()) {
        echo "  FAIL: профиль {$username} не сохранён с токеном\n";
        continue;
    }

    $issued[$username] = ['user_id' => $userId, 'token' => $raw];
    echo "  token +{$username}\n";
}

if ($issued) {
    echo "\n--- Сырые токены (в git не класть; место хранения — config/mxboard-agents.json) ---\n";
    foreach ($issued as $username => $data) {
        echo "{$username}\tuser_id={$data['user_id']}\ttoken={$data['token']}\n";
    }
}

echo "\nГотово.\n";

<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Token;

use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;
use MxBoard\Helpers\Transitions;
use MxBoard\Processors\Mgr\ServiceProcessor;

/**
 * Текущий профильный токен пользователя (для виджета на странице профиля).
 *
 * Возвращает сырой токен из profile.extended.mxboard — он здесь хранится осознанно
 * (вариант A), чтобы суперадмин мог посмотреть/скопировать его повторно. Гейт — sudo,
 * как и у выпуска: видеть чужой токен вправе только суперадмин.
 */
class GetForUser extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        if (!Transitions::isSuperuser($this->modx, $user)) {
            return $this->failure($this->modx->lexicon('mxboard_err_structure_denied'));
        }

        $userId = (int) $this->getProperty('user_id', 0);
        /** @var modUser|null $target */
        $target = $userId > 0 ? $this->modx->getObject(modUser::class, $userId) : null;
        if (!$target) {
            return $this->failure($this->modx->lexicon('mxboard_err_user_not_found'));
        }

        /** @var modUserProfile|null $profile */
        $profile = $target->getOne('Profile');
        $extended = $profile ? $profile->get('extended') : [];
        $data = (is_array($extended) ? ($extended['mxboard'] ?? []) : []);

        $createdon = (int) ($data['createdon'] ?? 0);

        return $this->success('', [
            'token' => (string) ($data['token'] ?? ''),
            'token_id' => (int) ($data['token_id'] ?? 0),
            'createdon' => $createdon,
            'createdon_formatted' => $createdon > 0 ? date('Y-m-d H:i:s', $createdon) : '',
        ]);
    }
}

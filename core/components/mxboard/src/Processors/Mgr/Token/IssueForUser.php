<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Token;

use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;
use MxBoard\Helpers\Transitions;
use MxBoard\Model\MxBoardToken;
use MxBoard\Processors\Mgr\ServiceProcessor;

/**
 * Выдать/перевыпустить токен агента ИЗ ПРОФИЛЯ пользователя (кнопка на странице
 * профиля в менеджере). В отличие от именованных токенов раздела «Токены агентов»,
 * здесь — ровно ОДИН токен на пользователя (вариант A хранения):
 *
 *  - сырой токен кладётся в profile.extended.mxboard (token, token_id, createdon) —
 *    чтобы его можно было увидеть и скопировать позже, а не только один раз;
 *  - sha256 — в mxboard_token (по нему идёт вход в API).
 *
 * Перевыпуск удаляет ПРЕДЫДУЩУЮ строку профильного токена по token_id (именованные
 * токены старого раздела не трогает — у них своя жизнь).
 *
 * Гейт — глобальный sudo: раздавать доступ агентам от имени пользователей вправе
 * только суперадмин.
 */
class IssueForUser extends ServiceProcessor
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
        if (!$profile) {
            return $this->failure($this->modx->lexicon('mxboard_err_user_not_found'));
        }

        $extended = $profile->get('extended');
        if (!is_array($extended)) {
            $extended = [];
        }
        $prev = $extended['mxboard'] ?? [];

        // Снести прежний ПРОФИЛЬНЫЙ токен (по token_id), чтобы на пользователя был один.
        $prevId = (int) ($prev['token_id'] ?? 0);
        if ($prevId > 0) {
            /** @var MxBoardToken|null $old */
            $old = $this->modx->getObject(MxBoardToken::class, $prevId);
            if ($old) {
                $old->remove();
            }
        }

        $raw = bin2hex(random_bytes(24));
        $now = time();

        /** @var MxBoardToken $token */
        $token = $this->modx->newObject(MxBoardToken::class);
        $token->fromArray([
            'user_id' => $userId,
            'name' => 'profile',
            'token_hash' => hash('sha256', $raw),
            'active' => true,
            'lastusedon' => 0,
            'createdon' => $now,
        ]);
        if (!$token->save()) {
            return $this->failure($this->modx->lexicon('mxboard_err_save'));
        }

        $extended['mxboard'] = [
            'token' => $raw,
            'token_id' => (int) $token->get('id'),
            'createdon' => $now,
        ];
        $profile->set('extended', $extended);
        if (!$profile->save()) {
            return $this->failure($this->modx->lexicon('mxboard_err_save'));
        }

        return $this->success($this->modx->lexicon('mxboard_token_created'), [
            'token' => $raw,
            'token_id' => (int) $token->get('id'),
            'createdon' => $now,
            'createdon_formatted' => date('Y-m-d H:i:s', $now),
        ]);
    }
}

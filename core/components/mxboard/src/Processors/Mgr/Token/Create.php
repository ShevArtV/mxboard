<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Token;

use MODX\Revolution\modUser;
use MODX\Revolution\Processors\Processor;
use MxBoard\Model\MxBoardToken;

/**
 * Выдать токен агенту.
 *
 * В базу уходит только sha256-хэш — сырой токен нигде не хранится и показывается
 * ОДИН раз, в этом ответе. Потерял — выпускай новый. Это осознанный размен:
 * утечка дампа БД не даёт доступа к API.
 */
class Create extends Processor
{
    public $languageTopics = ['mxboard:default'];

    public function process()
    {
        $this->modx->lexicon->load('mxboard:default');

        $userId = (int) $this->getProperty('user_id', 0);
        if ($userId <= 0) {
            return $this->failure($this->modx->lexicon('mxboard_err_user_not_found'));
        }

        $user = $this->modx->getObject(modUser::class, $userId);
        if (!$user) {
            return $this->failure($this->modx->lexicon('mxboard_err_user_not_found'));
        }

        $name = trim((string) $this->getProperty('name', ''));
        if ($name === '') {
            $name = (string) $user->get('username');
        }

        $raw = bin2hex(random_bytes(24));

        /** @var MxBoardToken $token */
        $token = $this->modx->newObject(MxBoardToken::class);
        $token->fromArray([
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => hash('sha256', $raw),
            'active' => true,
            'lastusedon' => 0,
            'createdon' => time(),
        ]);

        if (!$token->save()) {
            return $this->failure($this->modx->lexicon('mxboard_err_save'));
        }

        $array = $token->toArray();
        unset($array['token_hash']);
        $array['username'] = (string) $user->get('username');
        $array['createdon_formatted'] = date('Y-m-d H:i:s', (int) $token->get('createdon'));
        $array['token'] = $raw;

        return $this->success($this->modx->lexicon('mxboard_token_created'), $array);
    }
}

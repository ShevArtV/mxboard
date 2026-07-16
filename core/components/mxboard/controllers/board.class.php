<?php

use MODX\Revolution\modExtraManagerController;

/**
 * CMP mxBoard — канбан-доска (Vue).
 *
 * Имя класса плоское и в глобальном namespace — так его строит и ищет
 * MODX\Revolution\modManagerResponse::getControllerClassName() для пары
 * namespace=mxboard + action=board (ucfirst(namespace) . action . 'ManagerController'),
 * подгружая файл core/components/mxboard/controllers/board.class.php.
 * PSR-4 src/ тут не резолвится — поэтому контроллер страницы здесь.
 */
class MxboardboardManagerController extends modExtraManagerController
{
    public function getLanguageTopics()
    {
        return ['mxboard:default'];
    }

    public function checkPermissions()
    {
        return true;
    }

    public function getPageTitle()
    {
        return $this->modx->lexicon('mxboard');
    }

    public function loadCustomCssJs()
    {
        $assetsUrl = MODX_ASSETS_URL . 'components/mxboard/';

        $user = $this->modx->user;

        $config = [
            'connector_url' => $assetsUrl . 'connector.php',
            'token' => $user
                ? $user->getUserToken($this->modx->context->get('key'))
                : '',
            'assets_url' => $assetsUrl,
            'lexicon_topics' => $this->getLanguageTopics(),
            'user_id' => $user ? (int) $user->get('id') : 0,
            'board' => (string) $this->modx->getOption('mxboard.default_board', null, 'default'),
            // Право «двигать что угодно куда угодно» (MxBoard\Helpers\Transitions::PERMISSION_MOVE_ANY;
            // строкой, чтобы контроллер не зависел от загруженного автолоада компонента).
            // UI не пугает запретом закрытия чужой задачи того, кому сервер это всё равно разрешит.
            'can_move_any' => $user
                && ((bool) $user->get('sudo') || $this->modx->hasPermission('mxboard_move_any')),
        ];
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->modx->regClientStartupHTMLBlock("<script>window.MxBoardConfig = {$json};</script>");

        // Лексикон в JS: строки интерфейса не хардкодятся во Vue, а берутся через
        // @vuetools/useLexicon из window.MODx.lang. Прокидываем ВСЕ загруженные записи
        // mxboard_* явно, не полагаясь на то, как менеджер экспонирует топики.
        $this->modx->lexicon->load('mxboard:default');
        $lang = $this->modx->lexicon->fetch('mxboard');
        if (!empty($lang)) {
            $langJson = json_encode($lang, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->modx->regClientStartupHTMLBlock(
                "<script>window.MODx=window.MODx||{};MODx.lang=Object.assign(MODx.lang||{}, {$langJson});</script>"
            );
        }

        // Cache-bust по mtime бандла — каждая пересборка автоматически сбрасывает кэш.
        $distPath = MODX_ASSETS_PATH . 'components/mxboard/js/mgr/vue-dist/';
        $distUrl = $assetsUrl . 'js/mgr/vue-dist/';
        $ver = @filemtime($distPath . 'board.min.js') ?: $this->modx->getOption('mxboard.version', null, '1.0.0');

        // CSS приложения. Vue/PrimeVue/тема Aura/PrimeIcons приходят из пакета VueTools.
        if (is_file($distPath . 'board.min.css')) {
            $cssVer = @filemtime($distPath . 'board.min.css') ?: $ver;
            $this->modx->regClientCSS($distUrl . 'board.min.css?v=' . rawurlencode((string) $cssVer));
        }

        // Entry — ES-модуль; vue/primevue резолвятся из Import Map VueTools.
        // Сначала проверка карты: без неё в консоли будет только
        // «Failed to resolve module specifier "vue"» без объяснения причины.
        $this->registerVueToolsCheck();
        $this->modx->regClientStartupHTMLBlock(
            '<script type="module" data-vue-module src="'
            . $distUrl . 'board.min.js?v=' . rawurlencode((string) $ver) . '"></script>'
        );
    }

    /**
     * Inline-проверка Import Map пакета VueTools (один раз на страницу).
     * Нет карты с ключом vue → удаляем data-vue-module скрипты и алертим.
     */
    protected function registerVueToolsCheck(): void
    {
        $title = json_encode($this->modx->lexicon('mxboard_error') ?: 'mxBoard', JSON_UNESCAPED_UNICODE);
        $message = json_encode(
            $this->modx->lexicon('mxboard_vuetools_required')
                ?: 'Для работы требуется пакет VueTools. Установите его через «Управление пакетами».',
            JSON_UNESCAPED_UNICODE
        );

        $script = <<<JS
<script>
(function () {
    var map = document.querySelector('script[type="importmap"]');
    var ok = false;
    if (map) {
        try { var j = JSON.parse(map.textContent); ok = !!(j.imports && j.imports.vue); } catch (e) { ok = false; }
    }
    if (!ok) {
        document.querySelectorAll('script[type="module"][data-vue-module]').forEach(function (el) { el.remove(); });
        var alertFn = function () {
            if (typeof MODx !== 'undefined' && MODx.msg) { MODx.msg.alert({$title}, {$message}); }
            else { alert({$message}); }
        };
        if (typeof Ext !== 'undefined') { Ext.onReady(alertFn); }
        else { document.addEventListener('DOMContentLoaded', function () { setTimeout(alertFn, 500); }); }
    }
})();
</script>
JS;
        $this->modx->regClientStartupHTMLBlock($script);
    }

    public function getTemplateFile()
    {
        return MODX_CORE_PATH . 'components/mxboard/templates/mgr/board.tpl';
    }
}

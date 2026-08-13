import { createApp } from 'vue';
// Vue/PrimeVue берутся из Import Map пакета VueTools (не бандлятся).
// Всё PrimeVue — именованными импортами из единого бандла 'primevue';
// тема (Aura) и PrimeIcons тоже приходят из VueTools (vuetools.css).
import { PrimeVue, Aura, ConfirmationService, ToastService, Tooltip } from 'primevue';
import BoardApp from './pages/BoardApp.vue';
import { t } from './utils/i18n.js';

const app = createApp(BoardApp);
app.use(PrimeVue, {
    theme: { preset: Aura, options: { darkModeSelector: '.mxb-dark' } },
    // Пустые состояния списков PrimeVue берёт из своей локали, а она по умолчанию
    // английская («No results found»). Лексикон контроллер кладёт в window.MODx.lang
    // до бандла, поэтому t() здесь уже отвечает переводами.
    locale: {
        // Select/MultiSelect читают сначала устаревший emptySearchMessage и лишь потом
        // emptyFilterMessage, поэтому задаём оба — иначе побеждает английский дефолт.
        emptySearchMessage: t('mxboard_ui_no_results'),
        emptyFilterMessage: t('mxboard_ui_no_results'),
        emptyMessage: t('mxboard_ui_empty'),
        emptySelectionMessage: t('mxboard_ui_nothing_selected'),
    },
});
app.use(ConfirmationService);
app.use(ToastService);
app.directive('tooltip', Tooltip);
app.mount('#mxboard-app');

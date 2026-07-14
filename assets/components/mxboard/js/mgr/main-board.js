import { createApp } from 'vue';
// Vue/PrimeVue берутся из Import Map пакета VueTools (не бандлятся).
// Всё PrimeVue — именованными импортами из единого бандла 'primevue';
// тема (Aura) и PrimeIcons тоже приходят из VueTools (vuetools.css).
import { PrimeVue, Aura, ConfirmationService, ToastService, Tooltip } from 'primevue';
import BoardApp from './pages/BoardApp.vue';

const app = createApp(BoardApp);
app.use(PrimeVue, { theme: { preset: Aura, options: { darkModeSelector: '.mxb-dark' } } });
app.use(ConfirmationService);
app.use(ToastService);
app.directive('tooltip', Tooltip);
app.mount('#mxboard-app');

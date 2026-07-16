import { useLexicon } from '@vuetools/useLexicon';

// Единая точка локализации на VueTools useLexicon. Строки НЕ хардкодятся в
// компонентах — только ключи; сами тексты живут в lexicon/{ru,en}/default.inc.php
// и прокидываются контроллером CMP в window.MODx.lang (useLexicon читает его).
// t(key, params) подставляет [[+x]] / {x} / :x и возвращает key, если перевода нет.
const { _, has, getByPrefix } = useLexicon();

export const t = _;
export { has, getByPrefix };

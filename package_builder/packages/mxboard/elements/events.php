<?php

/**
 * События mxBoard — точка интеграции для внешних систем.
 *
 * Ядро не знает ни про Jarvis, ни про трекеры: всё, что специфично для конкретной
 * инсталляции, вешается плагином на эти события и читает task.meta.
 */
return [
    'mxbOnTaskCreate',
    'mxbOnTaskTake',
    'mxbOnTaskRelease',
    'mxbOnBeforeTaskMove',
    'mxbOnTaskMove',
    'mxbOnTaskClose',
    'mxbOnTaskComment',
    'mxbOnTaskUpdate',
    'mxbOnTaskDelete',
    'mxbOnDeadlineDispute',
    'mxbOnDeadlineResolve',
    'mxbOnPlanDispute',
    'mxbOnPlanResolve',
];

<script setup>
import { ref } from 'vue';
import { Tabs, TabList, Tab, TabPanels, TabPanel } from 'primevue';
import { t } from '../utils/i18n.js';
import DepartmentsTab from '../components/structure/DepartmentsTab.vue';
import TypesTab from '../components/structure/TypesTab.vue';
import ProjectsTab from '../components/structure/ProjectsTab.vue';
import ColumnsTab from '../components/structure/ColumnsTab.vue';
import QueuesTab from '../components/structure/QueuesTab.vue';

// Экран «Структура» — только менеджеру (гейт по cfg.is_manager в BoardApp).
// Внутренние вкладки: отделы · типы+поля · проекты · колонки/стадии · очереди.
const sub = ref('departments');
</script>

<template>
    <Tabs v-model:value="sub">
        <TabList>
            <Tab value="departments">{{ t('mxboard_ui_struct_departments') }}</Tab>
            <Tab value="types">{{ t('mxboard_ui_struct_types') }}</Tab>
            <Tab value="projects">{{ t('mxboard_ui_struct_projects') }}</Tab>
            <Tab value="columns">{{ t('mxboard_ui_struct_columns') }}</Tab>
            <Tab value="queues">{{ t('mxboard_ui_struct_queues') }}</Tab>
        </TabList>
        <!-- Свой скролл рабочей области: фрейм менеджера страницу не прокручивает,
             поэтому длинная таблица (и раскрытые поля) иначе уходит под нижний край. -->
        <div class="mxb-struct-scroll">
            <TabPanels>
                <TabPanel value="departments"><DepartmentsTab /></TabPanel>
                <TabPanel value="types"><TypesTab /></TabPanel>
                <TabPanel value="projects"><ProjectsTab /></TabPanel>
                <TabPanel value="columns"><ColumnsTab /></TabPanel>
                <TabPanel value="queues"><QueuesTab /></TabPanel>
            </TabPanels>
        </div>
    </Tabs>
</template>

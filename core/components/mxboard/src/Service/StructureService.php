<?php

declare(strict_types=1);

namespace MxBoard\Service;

use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroup;
use MODX\Revolution\modX;
use MxBoard\Helpers\Transitions;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;

/**
 * Структурные операции агента-менеджера: регистрация отдела, создание типов и проектов.
 *
 * Общий слой для MCP и REST — как и TaskService. Право на всё здесь — «менеджер отдела»
 * (глобальный sudo или супер группы отдела), кроме регистрации отдела, которую вправе
 * сделать и супер самой регистрируемой группы.
 *
 * Инварианты (тип ≥1 поле, проект ровно одна initial/одна final) проверяются ЗДЕСЬ,
 * атомарно с созданием: полуфабрикатов-«нерабочих» типов и битых проектов не остаётся.
 */
class StructureService
{
    /** Допустимые типы полей. Тип `files` — файловая зона задачи (вложения, не хранится в fields). */
    private const FIELD_TYPES = ['textarea', 'url', 'number', 'date', 'user', 'files'];

    public function __construct(private modX $modx)
    {
    }

    /**
     * Пометить группу пользователей MODX как отдел.
     *
     * Право: глобальный sudo ИЛИ супер самой этой группы (руководитель регистрирует свой отдел).
     * Идемпотентно: если группа уже отдел — возвращаем существующий.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function registerDepartment(modUser $user, array $data): array
    {
        $usergroupId = (int) ($data['usergroup_id'] ?? 0);
        if ($usergroupId <= 0) {
            return $this->fail('mxboard_err_group_required');
        }

        /** @var modUserGroup|null $group */
        $group = $this->modx->getObject(modUserGroup::class, $usergroupId);
        if (!$group) {
            return $this->fail('mxboard_err_group_not_found');
        }

        if (!Transitions::isSuperuser($this->modx, $user) && !Transitions::isGroupSuper($this->modx, $user, $usergroupId)) {
            return $this->fail('mxboard_err_structure_denied');
        }

        /** @var MxBoardDepartment|null $existing */
        $existing = $this->modx->getObject(MxBoardDepartment::class, ['usergroup_id' => $usergroupId]);
        if ($existing) {
            return $this->ok($existing->toArray());
        }

        $name = trim((string) ($data['name'] ?? '')) ?: (string) $group->get('name');

        /** @var MxBoardDepartment $department */
        $department = $this->modx->newObject(MxBoardDepartment::class);
        $department->fromArray([
            'usergroup_id' => $usergroupId,
            'name' => $name,
            'active' => true,
            'position' => (int) ($data['position'] ?? 0),
            'createdon' => time(),
        ]);

        if (!$department->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok($department->toArray());
    }

    /**
     * Создать тип задачи вместе с полями. Инвариант: ≥1 поле.
     *
     * Право: менеджер отдела. Уникум `(department_id, key)`.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function createType(modUser $user, array $data): array
    {
        $departmentId = (int) ($data['department_id'] ?? 0);
        if ($departmentId <= 0) {
            return $this->fail('mxboard_err_department_required');
        }
        if (!$this->modx->getObject(MxBoardDepartment::class, $departmentId)) {
            return $this->fail('mxboard_err_department_not_found');
        }
        if (!Transitions::isDepartmentManager($this->modx, $user, $departmentId)) {
            return $this->fail('mxboard_err_structure_denied');
        }

        $key = trim((string) ($data['key'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($key === '' || $name === '') {
            return $this->fail('mxboard_err_type_key_name_required');
        }

        $fieldsInput = $data['fields'] ?? [];
        if (!is_array($fieldsInput) || count($fieldsInput) < 1) {
            return $this->fail('mxboard_err_type_no_fields');
        }

        [$fields, $fieldError] = $this->normalizeFields($fieldsInput);
        if ($fieldError !== null) {
            return $this->fail($fieldError);
        }

        if ($this->modx->getObject(MxBoardTaskType::class, ['department_id' => $departmentId, 'key' => $key])) {
            return $this->fail('mxboard_err_type_exists');
        }

        $now = time();

        $this->modx->beginTransaction();

        /** @var MxBoardTaskType $type */
        $type = $this->modx->newObject(MxBoardTaskType::class);
        $type->fromArray([
            'department_id' => $departmentId,
            'key' => $key,
            'name' => $name,
            'description' => (string) ($data['description'] ?? ''),
            'active' => true,
            'ai_check' => !empty($data['ai_check']),
            'ai_prompt' => trim((string) ($data['ai_prompt'] ?? '')) ?: null,
            'position' => (int) ($data['position'] ?? 0),
            'createdon' => $now,
        ]);
        if (!$type->save()) {
            $this->modx->rollback();
            return $this->fail('mxboard_err_save');
        }

        foreach ($fields as $pos => $field) {
            /** @var MxBoardField $f */
            $f = $this->modx->newObject(MxBoardField::class);
            $f->fromArray([
                'task_type_id' => (int) $type->get('id'),
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'required' => $field['required'],
                'position' => $pos,
                'options' => $field['options'],
                'createdon' => $now,
            ]);
            if (!$f->save()) {
                $this->modx->rollback();
                return $this->fail('mxboard_err_save');
            }
        }

        $this->modx->commit();

        $out = $type->toArray();
        $out['fields'] = $fields;

        return $this->ok($out);
    }

    /**
     * Создать проект с колонками. Без колонок — берётся глобальный шаблон (project_id=0).
     *
     * Право: менеджер отдела. Инвариант: ровно одна initial и ровно одна final.
     * Уникум `(department_id, key)`.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function createProject(modUser $user, array $data): array
    {
        $departmentId = (int) ($data['department_id'] ?? 0);
        if ($departmentId <= 0) {
            return $this->fail('mxboard_err_department_required');
        }
        if (!$this->modx->getObject(MxBoardDepartment::class, $departmentId)) {
            return $this->fail('mxboard_err_department_not_found');
        }
        if (!Transitions::isDepartmentManager($this->modx, $user, $departmentId)) {
            return $this->fail('mxboard_err_structure_denied');
        }

        $key = trim((string) ($data['key'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($key === '' || $name === '') {
            return $this->fail('mxboard_err_project_key_name_required');
        }

        if ($this->modx->getObject(MxBoardProject::class, ['department_id' => $departmentId, 'key' => $key])) {
            return $this->fail('mxboard_err_project_exists');
        }

        $columnsInput = $data['columns'] ?? [];
        $columns = is_array($columnsInput) && $columnsInput !== []
            ? $this->normalizeColumns($columnsInput)
            : $this->templateColumns();

        [$columns, $columnError] = $columns;
        if ($columnError !== null) {
            return $this->fail($columnError);
        }

        $now = time();

        $this->modx->beginTransaction();

        /** @var MxBoardProject $project */
        $project = $this->modx->newObject(MxBoardProject::class);
        $project->fromArray([
            'department_id' => $departmentId,
            'key' => $key,
            'name' => $name,
            'description' => (string) ($data['description'] ?? ''),
            'active' => true,
            'position' => (int) ($data['position'] ?? 0),
            'createdon' => $now,
            'updatedon' => $now,
        ]);
        if (!$project->save()) {
            $this->modx->rollback();
            return $this->fail('mxboard_err_save');
        }

        $projectId = (int) $project->get('id');
        foreach ($columns as $pos => $col) {
            /** @var MxBoardColumn $column */
            $column = $this->modx->newObject(MxBoardColumn::class);
            $column->fromArray([
                'project_id' => $projectId,
                'key' => $col['key'],
                'name' => $col['name'],
                'position' => $pos,
                'move_roles' => $col['move_roles'],
                'stage_key' => $col['stage_key'],
                'is_initial' => $col['is_initial'],
                'is_final' => $col['is_final'],
                'createdon' => $now,
            ]);
            if (!$column->save()) {
                $this->modx->rollback();
                return $this->fail('mxboard_err_save');
            }
        }

        $this->modx->commit();

        $out = $project->toArray();
        $out['columns'] = $columns;

        return $this->ok($out);
    }

    /**
     * Правка отдела: name, active, position. usergroup_id не меняется — это была бы
     * подмена отдела под чужой группой, а не правка.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function updateDepartment(modUser $user, int $id, array $data): array
    {
        /** @var MxBoardDepartment|null $department */
        $department = $this->modx->getObject(MxBoardDepartment::class, $id);
        if (!$department) {
            return $this->fail('mxboard_err_department_not_found');
        }
        if (!Transitions::isDepartmentManager($this->modx, $user, $id)) {
            return $this->fail('mxboard_err_structure_denied');
        }

        // name = '' допустим: по схеме пустое имя означает «брать имя группы MODX».
        if (array_key_exists('name', $data)) {
            $department->set('name', trim((string) $data['name']));
        }
        if (array_key_exists('active', $data)) {
            $department->set('active', !empty($data['active']));
        }
        if (array_key_exists('position', $data)) {
            $department->set('position', (int) $data['position']);
        }

        if (!$department->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok($department->toArray());
    }

    /**
     * Снять пометку «отдел» с группы. Только с пустого отдела: проекты и типы — живые
     * данные, каскадное удаление снесло бы задачи всего отдела одной кнопкой.
     *
     * @return array{success: bool, message: string, object: null}
     */
    public function removeDepartment(modUser $user, int $id): array
    {
        /** @var MxBoardDepartment|null $department */
        $department = $this->modx->getObject(MxBoardDepartment::class, $id);
        if (!$department) {
            return $this->fail('mxboard_err_department_not_found');
        }
        if (!Transitions::isDepartmentManager($this->modx, $user, $id)) {
            return $this->fail('mxboard_err_structure_denied');
        }

        $hasProjects = (int) $this->modx->getCount(MxBoardProject::class, ['department_id' => $id]) > 0;
        $hasTypes = (int) $this->modx->getCount(MxBoardTaskType::class, ['department_id' => $id]) > 0;
        if ($hasProjects || $hasTypes) {
            return $this->fail('mxboard_err_department_not_empty');
        }

        if (!$department->remove()) {
            return $this->fail('mxboard_err_save');
        }

        return ['success' => true, 'message' => '', 'object' => null];
    }

    /**
     * Правка типа: name, description, active, position. `key` после создания не меняется —
     * по нему адресуются задачи и журнал.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function updateType(modUser $user, int $id, array $data): array
    {
        /** @var MxBoardTaskType|null $type */
        $type = $this->modx->getObject(MxBoardTaskType::class, $id);
        if (!$type) {
            return $this->fail('mxboard_err_type_not_found');
        }
        if (!Transitions::isDepartmentManager($this->modx, $user, (int) $type->get('department_id'))) {
            return $this->fail('mxboard_err_structure_denied');
        }

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->fail('mxboard_err_type_key_name_required');
            }
            $type->set('name', $name);
        }
        if (array_key_exists('description', $data)) {
            $type->set('description', (string) $data['description']);
        }
        if (array_key_exists('active', $data)) {
            $type->set('active', !empty($data['active']));
        }
        if (array_key_exists('ai_check', $data)) {
            $type->set('ai_check', !empty($data['ai_check']));
        }
        if (array_key_exists('ai_prompt', $data)) {
            $type->set('ai_prompt', trim((string) $data['ai_prompt']) ?: null);
        }
        if (array_key_exists('position', $data)) {
            $type->set('position', (int) $data['position']);
        }

        if (!$type->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok($type->toArray());
    }

    /**
     * Удалить тип вместе с полями (composite). Только если по типу нет задач —
     * иначе карточки остались бы с битым type_id и нечитаемыми fields.
     *
     * @return array{success: bool, message: string, object: null}
     */
    public function removeType(modUser $user, int $id): array
    {
        /** @var MxBoardTaskType|null $type */
        $type = $this->modx->getObject(MxBoardTaskType::class, $id);
        if (!$type) {
            return $this->fail('mxboard_err_type_not_found');
        }
        if (!Transitions::isDepartmentManager($this->modx, $user, (int) $type->get('department_id'))) {
            return $this->fail('mxboard_err_structure_denied');
        }

        if ((int) $this->modx->getCount(MxBoardTask::class, ['type_id' => $id]) > 0) {
            return $this->fail('mxboard_err_type_has_tasks');
        }

        if (!$type->remove()) {
            return $this->fail('mxboard_err_save');
        }

        return ['success' => true, 'message' => '', 'object' => null];
    }

    /**
     * Добавить поле к существующему типу.
     *
     * @param array<string, mixed> $data task_type_id + key/label/type/required/options/position
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function createField(modUser $user, array $data): array
    {
        $typeId = (int) ($data['task_type_id'] ?? 0);
        /** @var MxBoardTaskType|null $type */
        $type = $typeId > 0 ? $this->modx->getObject(MxBoardTaskType::class, $typeId) : null;
        if (!$type) {
            return $this->fail('mxboard_err_type_not_found');
        }
        if (!Transitions::isDepartmentManager($this->modx, $user, (int) $type->get('department_id'))) {
            return $this->fail('mxboard_err_structure_denied');
        }

        [$fields, $fieldError] = $this->normalizeFields([$data]);
        if ($fieldError !== null) {
            return $this->fail($fieldError);
        }
        $field = $fields[0];

        if ($this->modx->getObject(MxBoardField::class, ['task_type_id' => $typeId, 'key' => $field['key']])) {
            return $this->fail('mxboard_err_field_exists');
        }

        $position = array_key_exists('position', $data)
            ? (int) $data['position']
            : (int) $this->modx->getCount(MxBoardField::class, ['task_type_id' => $typeId]);

        /** @var MxBoardField $f */
        $f = $this->modx->newObject(MxBoardField::class);
        $f->fromArray([
            'task_type_id' => $typeId,
            'key' => $field['key'],
            'label' => $field['label'],
            'type' => $field['type'],
            'required' => $field['required'],
            'position' => $position,
            'options' => $field['options'],
            'createdon' => time(),
        ]);

        if (!$f->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok($f->toArray());
    }

    /**
     * Правка поля: label, type, required, position, options. `key` не меняется —
     * значения в task.fields адресуются по нему.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function updateField(modUser $user, int $id, array $data): array
    {
        /** @var MxBoardField|null $field */
        $field = $this->modx->getObject(MxBoardField::class, $id);
        if (!$field) {
            return $this->fail('mxboard_err_field_not_found');
        }
        $error = $this->fieldGate($user, $field);
        if ($error !== null) {
            return $this->fail($error);
        }

        if (array_key_exists('label', $data)) {
            $label = trim((string) $data['label']);
            if ($label === '') {
                return $this->fail('mxboard_err_field_invalid');
            }
            $field->set('label', $label);
        }
        if (array_key_exists('type', $data)) {
            $type = (string) $data['type'];
            if (!in_array($type, self::FIELD_TYPES, true)) {
                return $this->fail('mxboard_err_field_type_invalid');
            }
            $field->set('type', $type);
        }
        if (array_key_exists('required', $data)) {
            $field->set('required', !empty($data['required']));
        }
        if (array_key_exists('position', $data)) {
            $field->set('position', (int) $data['position']);
        }
        if (array_key_exists('options', $data)) {
            $field->set('options', is_array($data['options']) ? $data['options'] : null);
        }

        if (!$field->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok($field->toArray());
    }

    /**
     * Удалить поле типа. Последнее поле не удаляется: тип без полей — нерабочий
     * (тот же инвариант, что при создании типа).
     *
     * @return array{success: bool, message: string, object: null}
     */
    public function removeField(modUser $user, int $id): array
    {
        /** @var MxBoardField|null $field */
        $field = $this->modx->getObject(MxBoardField::class, $id);
        if (!$field) {
            return $this->fail('mxboard_err_field_not_found');
        }
        $error = $this->fieldGate($user, $field);
        if ($error !== null) {
            return $this->fail($error);
        }

        $siblings = (int) $this->modx->getCount(MxBoardField::class, [
            'task_type_id' => (int) $field->get('task_type_id'),
        ]);
        if ($siblings <= 1) {
            return $this->fail('mxboard_err_field_last');
        }

        if (!$field->remove()) {
            return $this->fail('mxboard_err_save');
        }

        return ['success' => true, 'message' => '', 'object' => null];
    }

    /**
     * Правка проекта: name, description, active, position. `key` не меняется.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function updateProject(modUser $user, int $id, array $data): array
    {
        /** @var MxBoardProject|null $project */
        $project = $this->modx->getObject(MxBoardProject::class, $id);
        if (!$project) {
            return $this->fail('mxboard_err_project_not_found');
        }
        if (!Transitions::isDepartmentManager($this->modx, $user, (int) $project->get('department_id'))) {
            return $this->fail('mxboard_err_structure_denied');
        }

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->fail('mxboard_err_project_key_name_required');
            }
            $project->set('name', $name);
        }
        if (array_key_exists('description', $data)) {
            $project->set('description', (string) $data['description']);
        }
        if (array_key_exists('active', $data)) {
            $project->set('active', !empty($data['active']));
        }
        if (array_key_exists('position', $data)) {
            $project->set('position', (int) $data['position']);
        }
        $project->set('updatedon', time());

        if (!$project->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok($project->toArray());
    }

    /**
     * Удалить проект с колонками (composite). Только пустой: задачи — живые данные.
     *
     * @return array{success: bool, message: string, object: null}
     */
    public function removeProject(modUser $user, int $id): array
    {
        /** @var MxBoardProject|null $project */
        $project = $this->modx->getObject(MxBoardProject::class, $id);
        if (!$project) {
            return $this->fail('mxboard_err_project_not_found');
        }
        if (!Transitions::isDepartmentManager($this->modx, $user, (int) $project->get('department_id'))) {
            return $this->fail('mxboard_err_structure_denied');
        }

        if ((int) $this->modx->getCount(MxBoardTask::class, ['project_id' => $id]) > 0) {
            return $this->fail('mxboard_err_project_has_tasks');
        }

        if (!$project->remove()) {
            return $this->fail('mxboard_err_save');
        }

        return ['success' => true, 'message' => '', 'object' => null];
    }

    /**
     * Добавить колонку в проект (или в глобальный шаблон при project_id = 0).
     *
     * Флаги is_initial/is_final здесь игнорируются: у проекта они уже стоят на других
     * колонках (инвариант «ровно одна»), перенос — через updateColumn.
     *
     * @param array<string, mixed> $data project_id + key/name/move_roles/stage_key/position
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function createColumn(modUser $user, array $data): array
    {
        $projectId = (int) ($data['project_id'] ?? 0);
        $error = $this->columnScopeGate($user, $projectId);
        if ($error !== null) {
            return $this->fail($error);
        }

        $key = trim((string) ($data['key'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($key === '' || $name === '') {
            return $this->fail('mxboard_err_column_invalid');
        }

        if ($this->modx->getObject(MxBoardColumn::class, ['project_id' => $projectId, 'key' => $key])) {
            return $this->fail('mxboard_err_column_exists');
        }

        $position = array_key_exists('position', $data)
            ? (int) $data['position']
            : (int) $this->modx->getCount(MxBoardColumn::class, ['project_id' => $projectId]);

        /** @var MxBoardColumn $column */
        $column = $this->modx->newObject(MxBoardColumn::class);
        $column->fromArray([
            'project_id' => $projectId,
            'key' => $key,
            'name' => $name,
            'position' => $position,
            'move_roles' => trim((string) ($data['move_roles'] ?? '')),
            'stage_key' => trim((string) ($data['stage_key'] ?? '')),
            'color' => trim((string) ($data['color'] ?? '#6c757d')),
            'is_initial' => false,
            'is_final' => false,
            'createdon' => time(),
        ]);

        if (!$column->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok($column->toArray());
    }

    /**
     * Правка колонки: name, move_roles, stage_key, position; `key` не меняется
     * (он в журнале переходов). is_initial/is_final = 1 ПЕРЕНОСИТ флаг с прежней
     * колонки-носителя — инвариант «ровно одна» сохраняется сам собой; снять флаг
     * в 0 напрямую нельзя (falsy-значения игнорируются).
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function updateColumn(modUser $user, int $id, array $data): array
    {
        /** @var MxBoardColumn|null $column */
        $column = $this->modx->getObject(MxBoardColumn::class, $id);
        if (!$column) {
            return $this->fail('mxboard_err_column_not_found');
        }
        $error = $this->columnScopeGate($user, (int) $column->get('project_id'));
        if ($error !== null) {
            return $this->fail($error);
        }

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->fail('mxboard_err_column_invalid');
            }
            $column->set('name', $name);
        }
        if (array_key_exists('move_roles', $data)) {
            $column->set('move_roles', trim((string) $data['move_roles']));
        }
        if (array_key_exists('stage_key', $data)) {
            $column->set('stage_key', trim((string) $data['stage_key']));
        }
        if (array_key_exists('color', $data)) {
            $column->set('color', trim((string) $data['color']) ?: '#6c757d');
        }
        if (array_key_exists('position', $data)) {
            $column->set('position', (int) $data['position']);
        }

        foreach (['is_initial', 'is_final'] as $flag) {
            if (!empty($data[$flag]) && !(bool) $column->get($flag)) {
                $this->transferFlag($flag, $column);
            }
        }

        if (!$column->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok($column->toArray());
    }

    /**
     * Удалить колонку. Носитель is_initial/is_final не удаляется (сначала перенос
     * флага), непустая — тоже: карточкам стало бы негде жить.
     *
     * @return array{success: bool, message: string, object: null}
     */
    public function removeColumn(modUser $user, int $id): array
    {
        /** @var MxBoardColumn|null $column */
        $column = $this->modx->getObject(MxBoardColumn::class, $id);
        if (!$column) {
            return $this->fail('mxboard_err_column_not_found');
        }
        $error = $this->columnScopeGate($user, (int) $column->get('project_id'));
        if ($error !== null) {
            return $this->fail($error);
        }

        if ((bool) $column->get('is_initial') || (bool) $column->get('is_final')) {
            return $this->fail('mxboard_err_column_protected');
        }
        if ((int) $this->modx->getCount(MxBoardTask::class, ['column_id' => $id]) > 0) {
            return $this->fail('mxboard_err_column_has_tasks');
        }

        if (!$column->remove()) {
            return $this->fail('mxboard_err_save');
        }

        return ['success' => true, 'message' => '', 'object' => null];
    }

    /**
     * Право на колонки области: project_id = 0 — глобальный шаблон, его правит только
     * глобальный супер; иначе — менеджер отдела проекта.
     *
     * @return string|null ключ ошибки или null, если можно
     */
    private function columnScopeGate(modUser $user, int $projectId): ?string
    {
        if ($projectId === 0) {
            return Transitions::isSuperuser($this->modx, $user) ? null : 'mxboard_err_structure_denied';
        }

        /** @var MxBoardProject|null $project */
        $project = $this->modx->getObject(MxBoardProject::class, $projectId);
        if (!$project) {
            return 'mxboard_err_project_not_found';
        }

        return Transitions::isDepartmentManager($this->modx, $user, (int) $project->get('department_id'))
            ? null
            : 'mxboard_err_structure_denied';
    }

    /** Право на поле — через его тип: менеджер отдела типа. */
    private function fieldGate(modUser $user, MxBoardField $field): ?string
    {
        /** @var MxBoardTaskType|null $type */
        $type = $this->modx->getObject(MxBoardTaskType::class, (int) $field->get('task_type_id'));
        if (!$type) {
            return 'mxboard_err_type_not_found';
        }

        return Transitions::isDepartmentManager($this->modx, $user, (int) $type->get('department_id'))
            ? null
            : 'mxboard_err_structure_denied';
    }

    /** Перенести is_initial/is_final на $column: снять с прежних носителей в том же проекте. */
    private function transferFlag(string $flag, MxBoardColumn $column): void
    {
        $table = $this->modx->getTableName(MxBoardColumn::class);
        $this->modx->exec("UPDATE {$table} SET {$flag} = 0 WHERE project_id = " . (int) $column->get('project_id') . " AND {$flag} = 1");
        $column->set($flag, true);
    }

    /**
     * Нормализовать и провалидировать поля типа.
     *
     * @param array<int, mixed> $input
     *
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private function normalizeFields(array $input): array
    {
        $out = [];
        $seen = [];
        foreach ($input as $raw) {
            if (!is_array($raw)) {
                return [[], 'mxboard_err_field_invalid'];
            }
            $key = trim((string) ($raw['key'] ?? ''));
            $label = trim((string) ($raw['label'] ?? ''));
            if ($key === '' || $label === '') {
                return [[], 'mxboard_err_field_invalid'];
            }
            if (isset($seen[$key])) {
                return [[], 'mxboard_err_field_duplicate'];
            }
            $seen[$key] = true;

            $type = (string) ($raw['type'] ?? 'text');
            if (!in_array($type, self::FIELD_TYPES, true)) {
                return [[], 'mxboard_err_field_type_invalid'];
            }

            $options = null;
            if (isset($raw['options']) && is_array($raw['options'])) {
                $options = $raw['options'];
            }

            $out[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => !empty($raw['required']),
                'options' => $options,
            ];
        }

        return [$out, null];
    }

    /**
     * Нормализовать колонки и проверить инвариант (одна initial / одна final).
     *
     * @param array<int, mixed> $input
     *
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private function normalizeColumns(array $input): array
    {
        $out = [];
        $seen = [];
        $initial = 0;
        $final = 0;
        foreach ($input as $raw) {
            if (!is_array($raw)) {
                return [[], 'mxboard_err_column_invalid'];
            }
            $key = trim((string) ($raw['key'] ?? ''));
            $name = trim((string) ($raw['name'] ?? ''));
            if ($key === '' || $name === '') {
                return [[], 'mxboard_err_column_invalid'];
            }
            if (isset($seen[$key])) {
                return [[], 'mxboard_err_column_duplicate'];
            }
            $seen[$key] = true;

            $isInitial = !empty($raw['is_initial']);
            $isFinal = !empty($raw['is_final']);
            $initial += $isInitial ? 1 : 0;
            $final += $isFinal ? 1 : 0;

            $out[] = [
                'key' => $key,
                'name' => $name,
                'move_roles' => trim((string) ($raw['move_roles'] ?? '')),
                'stage_key' => trim((string) ($raw['stage_key'] ?? '')),
                'color' => trim((string) ($raw['color'] ?? '#6c757d')),
                'is_initial' => $isInitial,
                'is_final' => $isFinal,
            ];
        }

        if ($initial !== 1 || $final !== 1) {
            return [[], 'mxboard_err_column_invariant'];
        }

        return [$out, null];
    }

    /**
     * Колонки из глобального шаблона (project_id = 0).
     *
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private function templateColumns(): array
    {
        $c = $this->modx->newQuery(MxBoardColumn::class);
        $c->where(['project_id' => 0]);
        $c->sortby('position', 'ASC');

        $out = [];
        /** @var MxBoardColumn $column */
        foreach ($this->modx->getCollection(MxBoardColumn::class, $c) as $column) {
            $out[] = [
                'key' => (string) $column->get('key'),
                'name' => (string) $column->get('name'),
                'move_roles' => (string) $column->get('move_roles'),
                'stage_key' => (string) $column->get('stage_key'),
                'color' => (string) ($column->get('color') ?: '#6c757d'),
                'is_initial' => (bool) $column->get('is_initial'),
                'is_final' => (bool) $column->get('is_final'),
            ];
        }

        if ($out === []) {
            return [[], 'mxboard_err_no_template_columns'];
        }

        return [$out, null];
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array{success: bool, message: string, object: array<string, mixed>}
     */
    private function ok(array $object): array
    {
        return ['success' => true, 'message' => '', 'object' => $object];
    }

    /** @return array{success: bool, message: string, object: null} */
    private function fail(string $lexiconKey): array
    {
        return [
            'success' => false,
            'message' => $this->modx->lexicon($lexiconKey) ?: $lexiconKey,
            'object' => null,
        ];
    }
}

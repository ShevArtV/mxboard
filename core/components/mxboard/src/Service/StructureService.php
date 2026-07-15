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
    /** Допустимые типы полей. */
    private const FIELD_TYPES = ['text', 'textarea', 'url', 'number', 'date', 'select', 'user', 'file'];

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

        /** @var MxBoardTaskType $type */
        $type = $this->modx->newObject(MxBoardTaskType::class);
        $type->fromArray([
            'department_id' => $departmentId,
            'key' => $key,
            'name' => $name,
            'description' => (string) ($data['description'] ?? ''),
            'active' => true,
            'position' => (int) ($data['position'] ?? 0),
            'createdon' => $now,
        ]);
        if (!$type->save()) {
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
            $f->save();
        }

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
            $column->save();
        }

        $out = $project->toArray();
        $out['columns'] = $columns;

        return $this->ok($out);
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

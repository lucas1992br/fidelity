<?php

use Illuminate\Support\Facades\Schema;

test('package tables exist with the schema columns', function (string $table, array $columns) {
    expect(Schema::hasColumns($table, $columns))->toBeTrue();
})->with([
    'roles' => ['roles', ['id', 'name', 'guard_name', 'created_at', 'updated_at']],
    'permissions' => ['permissions', ['id', 'name', 'guard_name', 'created_at', 'updated_at']],
    'model_has_roles' => ['model_has_roles', ['role_id', 'model_type', 'model_id']],
    'model_has_permissions' => ['model_has_permissions', ['permission_id', 'model_type', 'model_id']],
    'role_has_permissions' => ['role_has_permissions', ['permission_id', 'role_id']],
    'activity_log' => ['activity_log', ['id', 'log_name', 'description', 'subject_type', 'subject_id', 'event', 'causer_type', 'causer_id', 'properties', 'batch_uuid', 'created_at', 'updated_at']],
    'settings' => ['settings', ['id', 'group', 'name', 'locked', 'payload', 'created_at', 'updated_at']],
]);

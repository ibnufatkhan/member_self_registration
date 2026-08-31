<?php
/**
 * @author Drajat Hasan
 * @contributor Ibnufatkhan
 * @requires PHP >= 8.3
 */

use SLiMS\DB;
use SLiMS\Plugins;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed!');

if (!isset($_POST['saveData'])) {
    return;
}

if (trim((string) ($_POST['name'] ?? '')) === '') {
    exit(toastr('Nama skema tidak boleh kosong!')->warning('Peringatan'));
}

$columns = $_POST['column'] ?? [];
if (!is_array($columns) || $columns === []) {
    exit(toastr('Struktur skema tidak boleh kosong')->error('Galat'));
}

$hadCustomTable = (bool) count(array_filter($columns, static fn(mixed $column): bool => is_array($column) && ($column['field'] ?? '') === 'advance'));

if ($hadCustomTable) {
    foreach ($columns as $key => $advColumn) {
        if (($advColumn['field'] ?? '') === 'advance') {
            $columns[$key]['advfield'] = 'adv_' . ($advColumn['advfield'] ?? '');
        }
    }
    $_POST['column'] = $columns;
}

$isRequirementFieldsExists = (bool) count(array_filter(
    $columns,
    static fn(mixed $column): bool => is_array($column) && in_array($column['field'] ?? '', ['member_id', 'member_name', 'gender'], true)
));

if (!$isRequirementFieldsExists) {
    exit(toastr('Ruas member_id, member_name dan gender tidak ditemukan')->error('Galat'));
}

$typeMap = getMysqlBlueprintTypeMap();
$memberSchema = Schema::table('member')->columns(true);

$insert = DB::getInstance()->prepare('insert ignore into `self_registration_schemas` set name = ?, info = ?, structure = ?, created_at = now()');

$_POST['name'] = preg_replace('/[^A-Za-z\s]/', '', (string) $_POST['name']) ?? '';
if (trim((string) $_POST['name']) === '') {
    exit(toastr('Nama skema tidak boleh kosong!')->warning('Peringatan'));
}
$newTable = buildSchemaTableName($_POST['name']);

$insert->execute([
    $_POST['name'],
    json_encode($_POST['info'] ?? [], JSON_UNESCAPED_UNICODE),
    json_encode(array_map(static function (array $data): array {
        $data['is_required'] = (bool) ($data['is_required'] ?? false);
        return $data;
    }, $columns), JSON_UNESCAPED_UNICODE),
]);

$schemaId = (int) DB::getInstance()->lastInsertId();

Plugins::getInstance()->execute('member_self_before_create_schema', [
    'memberSchema' => $memberSchema,
    'mysqlColumnType' => $typeMap['mysql'],
    'slimsSchemaColumnType' => $typeMap['blueprint'],
    'newTable' => $newTable,
    'structure' => $columns,
    'hadCustomTable' => $hadCustomTable,
]);

createSelfRegistrationTables($newTable, $columns, $hadCustomTable);
if ($schemaId > 0) {
    rememberSchemaTableName($schemaId, $newTable);
}

redirect()->simbioAJAX(pluginUrl(reset: true));
exit;

<?php
/**
 * @author Drajat Hasan
 * @contributor Ibnufatkhan
 * @requires PHP >= 8.3
 */

use SLiMS\DB;
use SLiMS\Plugins;

defined('INDEX_AUTH') or die('Direct access is not allowed!');

$rawJson = (string) ($_POST['import']['raw_json'] ?? '');

if ($rawJson === '' || !json_validate($rawJson)) {
    exit(toastr('Berkas skema tidak valid')->error('Galat'));
}

$new_data = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);

if (!is_array($new_data) || empty($new_data['name']) || empty($new_data['structure'])) {
    exit(toastr('Data skema tidak lengkap')->error('Galat'));
}

$new_data['updated_at'] = date('Y-m-d H:i:s');

$columns = implode(',', array_map(static fn(string $column): string => '`' . $column . '` = ?', array_keys($new_data)));
$values = array_values($new_data);

$insert = DB::getInstance()->prepare(<<<SQL
insert ignore into 
    `self_registration_schemas`
    set {$columns}
SQL);

$insert->execute($values);

$newTable = schemaTableName((string) $new_data['name']);
$structure = decodeJson($new_data['structure'], true);

$hadCustomTable = (bool) count(array_filter($structure, static fn(array $column): bool => ($column['field'] ?? '') === 'advance'));

$isRequirementFieldsExists = (bool) count(array_filter(
    $structure,
    static fn(array $column): bool => in_array($column['field'] ?? '', ['member_id', 'member_name', 'gender'], true)
));

if (!$isRequirementFieldsExists) {
    exit(toastr('Ruas member_id, member_name dan gender tidak ditemukan')->error('Galat'));
}

$typeMap = getMysqlBlueprintTypeMap();
$memberSchema = \SLiMS\Table\Schema::table('member')->columns(true);

Plugins::getInstance()->execute('member_self_before_create_schema', [
    'memberSchema' => $memberSchema,
    'mysqlColumnType' => $typeMap['mysql'],
    'slimsSchemaColumnType' => $typeMap['blueprint'],
    'newTable' => $newTable,
    'structure' => $structure,
    'hadCustomTable' => $hadCustomTable,
]);

createSelfRegistrationTables($newTable, $structure, $hadCustomTable, skipExistingCustomColumns: true);

toastr('Berhasil mengimport skema')->success();
echo <<<HTML
<script>
    top.jQuery.colorbox.close();
</script>
HTML;
redirect()->simbioAJAX(pluginUrl([], true));
exit;

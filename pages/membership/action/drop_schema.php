<?php
/**
 * @author Drajat Hasan
 * @contributor Ibnufatkhan
 * @requires PHP >= 8.3
 */

use SLiMS\DB;
use SLiMS\Plugins;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed');

Plugins::getInstance()->execute('member_self_before_drop_schema', [
    'schemaById' => $schemaById,
]);

$schemaById->execute([$_POST['schema_id'] ?? 0]);
$detail = $schemaById->fetchObject();

if ($detail === false) {
    exit;
}

DB::getInstance()->prepare('delete from `self_registration_schemas` where `id` = ?')->execute([$_POST['schema_id'] ?? 0]);
Schema::drop(registrationTableName($detail));

$advanceOnly = array_filter(decodeJson($detail->structure ?? '[]', true), static function (array $column): bool {
    return ($column['field'] ?? '') === 'advance';
});

$fieldsToDrop = array_map(static function (array $data): string {
    $advfield = (string) ($data['advfield'] ?? '');
    if (str_contains($advfield, '|')) {
        $advfield = explode(',', $advfield, 2)[0];
    }

    return $advfield;
}, $advanceOnly);

foreach ($fieldsToDrop as $column) {
    if ($column !== '') {
        Schema::dropColumn('member_custom', $column);
    }
}
exit;

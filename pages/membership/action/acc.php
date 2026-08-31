<?php
/**
 * @author Drajat Hasan
 * @contributor Ibnufatkhan
 * @requires PHP >= 8.3
 */

use SLiMS\DB;
use SLiMS\Plugins;

defined('INDEX_AUTH') or die('Direct access is not allowed!');

$member_id = $_POST['form']['member_id'] ?? 0;
Plugins::getInstance()->execute('member_self_before_acc', ['member_id' => $member_id, 'activeSchema' => $activeSchema]);

$schema = $activeSchema->fetchObject();
if ($schema === false) {
    redirect()->back();
    exit;
}

$baseTable = registrationTableName($schema);

$data = DB::getInstance()->prepare('select * from `' . $baseTable . '` where member_id = ?');
$data->execute([$member_id]);

if ($data->rowCount() < 1) {
    redirect()->back();
    exit;
}

$result = $data->fetch(PDO::FETCH_ASSOC);
if (!is_array($result)) {
    redirect()->back();
    exit;
}

$result_customs = [];

$columnNames = array_keys($result);
foreach ($columnNames as $columnName) {
    $newValue = $_POST['form'][$columnName] ?? '';

    if (is_array($newValue)) {
        $newValue = json_encode($newValue);
    }

    if (str_starts_with((string) $columnName, 'adv_')) {
        if (!isset($result_customs['member_id'])) {
            $result_customs['member_id'] = $member_id;
        }
        $result_customs[$columnName] = $newValue;
        unset($result[$columnName]);
        continue;
    }

    if ($columnName === 'mpasswd' && $newValue !== '' && $newValue !== null) {
        $_POST['form'][$columnName] = password_hash((string) $newValue, PASSWORD_BCRYPT);
        $newValue = $_POST['form'][$columnName];
    }

    if ($newValue === '' || $newValue === null) {
        continue;
    }

    $result[$columnName] = $newValue;
}

$result['input_date'] = $result['created_at'];
unset($result['created_at'], $result['updated_at']);

$result['register_date'] = date('Y-m-d');
$result['member_since_date'] = date('Y-m-d');
$result['last_update'] = date('Y-m-d');
$result['expire_date'] = date('Y-m-d', strtotime('+1 year'));
$result['is_new'] = 1;

if (isset($result['member_type_id'])) {
    $memberType = DB::getInstance()->prepare('select member_periode from mst_member_type where member_type_id = ?');
    $memberType->execute([$result['member_type_id']]);

    if ($memberType->rowCount() === 1) {
        $memberTypeData = $memberType->fetchObject();
        if ($memberTypeData !== false) {
            $periode = $memberTypeData->member_periode;
            $result['expire_date'] = date('Y-m-d', strtotime('+' . $periode . ' days'));
        }
    }
}

$columns = implode(',', array_map(static fn(string $column): string => '`' . $column . '` = ?', array_keys($result)));

$insert = DB::getInstance()->prepare(<<<SQL
insert ignore 
        into `member`
            set {$columns}
SQL);

$process = $insert->execute(array_values($result));

if (count($result_customs) && $process) {
    $column_customs = implode(',', array_map(static fn(string $column): string => '`' . $column . '` = ?', array_keys($result_customs)));

    $insert_custom = DB::getInstance()->prepare(<<<SQL
    insert ignore 
            into `member_custom`
                set {$column_customs}
    SQL);

    $process_custom = $insert_custom->execute(array_values($result_customs));
}

if ($process) {
    if (isset($process_custom) && $process_custom === false) {
        toastr('Gagal menyimpan data custom')->success();
    }

    toastr('Data berhasil disimpan')->success();
    echo '<script>top.jQuery.colorbox.close();</script>';

    $delete = DB::getInstance()->prepare('delete from `' . $baseTable . '` where member_id = ?');
    $delete->execute([$member_id]);

    echo '<script>top.jQuery.colorbox.close();</script>';
    redirect()->simbioAJAX(pluginUrl(reset: true));
}
exit;

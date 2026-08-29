<?php
use SLiMS\DB;

defined('INDEX_AUTH') or die('Direct access is not allowed');

$schemaId = $_POST['schema_id'] ?? 0;
$formConfig = $_POST['form_config'] ?? [];

$update = DB::getInstance()->prepare('update `self_registration_schemas` set `option` = ? where `id` = ?');
$update->execute([json_encode($formConfig), $schemaId]);

toastr('Data berhasil disimpan')->success();
redirect()->simbioAJAX(pluginUrl(reset: true));
exit;

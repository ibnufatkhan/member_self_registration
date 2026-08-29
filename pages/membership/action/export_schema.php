<?php
use SLiMS\Json;

defined('INDEX_AUTH') or die('Direct access is not allowed!');

$schemaById->execute([$_GET['schema_id'] ?? 0]);

if ($schemaById->rowCount() < 1) {
    toastr('Data skema tidak tersedia')->error();
    exit;
}

$data = $schemaById->fetchObject();
if ($data === false) {
    toastr('Data skema tidak tersedia')->error();
    exit;
}
unset($data->id);

$data->status = 0;

$filename = schemaTableName((string) $data->name);

header('Content-disposition: attachment; filename=' . $filename . '.json' );
exit(Json::stringify($data)->withHeader());
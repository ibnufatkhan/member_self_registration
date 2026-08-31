<?php
use SLiMS\Plugins;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed!');

// create datagrid
$datagrid = new simbio_datagrid();

$data = $activeSchema->fetchObject();
if ($data === false) {
    echo '<div class="alert alert-warning">Skema aktif tidak ditemukan.</div>';
    return;
}
$structure = decodeJson($data->structure ?? '[]', true);
$structure = array_merge(array_values(array_filter($structure, static function (mixed $column): bool {
    return is_array($column) && in_array($column['field'] ?? '', ['member_id', 'member_name'], true);
})), [['name' => _('Input Date'), 'field' => 'created_at']]);

$columns = [];
$columns[] = '`member_id` AS `Aksi`';

foreach ($structure as $no => $detail) {
    if ($detail['field'] === 'advance') continue;

    $columns[] = '`' . $detail['field'] . '` AS `' . $detail['name'] . '`';
}

$table_spec = registrationTableName($data);

if (!Schema::hasTable($table_spec)) {
    echo '<div class="alert alert-danger p-3">';
    echo '<strong>Tabel pendaftaran tidak ditemukan.</strong>';
    echo '<p class="mb-1">Skema aktif: <code>' . htmlspecialchars((string) $data->name, ENT_QUOTES, 'UTF-8') . '</code></p>';
    echo '<p class="mb-0">Nama tabel yang dicari: <code>' . htmlspecialchars($table_spec, ENT_QUOTES, 'UTF-8') . '</code></p>';
    echo '<p class="mt-2 mb-0">Kemungkinan nama skema terpotong (batas lama 32 karakter). Nonaktifkan lalu aktifkan ulang skema, atau buat skema baru dengan nama yang lebih pendek setelah memperbarui plugin.</p>';
    echo '</div>';
    return;
}

if (isset($data->id)) {
    rememberSchemaTableName((int) $data->id, $table_spec);
}

$datagrid->setSQLColumn(...$columns);

// modify column value
$datagrid->setSQLorder('created_at DESC');

function setButton($dbs, $data)
{
    return '<a height="500" title="Detail ' . $data[2] . '" href="' . pluginUrl(['section' => 'view_detail', 'member_id' => $data[0], 'headless' => 'yes']) . '" class="notAJAX openPopUp btn btn-primary"><i class="fa fa-pencil"></i></a>';
}

$datagrid->modifyColumnContent(0, 'callback{setButton}');

// set table and table header attributes
$datagrid->table_attr = 'id="dataList" class="s-table table"';
$datagrid->table_header_attr = 'class="dataListHeader thead-dark" style="font-weight: bold;"';
// set delete proccess URL
$datagrid->chbox_form_URL = pluginUrl(reset: true);
$datagrid->column_width = ['10%', '10%'];

if (isset($_GET['keywords'])) {
    $keywords = $dbs->escape_string($_GET['keywords']);
    $datagrid->setSQLCriteria('(member_id like \'%' . $keywords . '%\' or member_name like \'%' . $keywords . '%\')');
}

Plugins::getInstance()->execute('member_self_before_datagrid', [
    'datagrid' => $datagrid,
    'table_spec' => $table_spec
]);

// put the result into variables
try {
    $datagrid_result = $datagrid->createDataGrid($dbs, $table_spec, 10, false);
    echo $datagrid_result;
} catch (Throwable $e) {
    echo '<div class="alert alert-danger p-3">Gagal memuat daftar pendaftaran dari tabel <code>'
        . htmlspecialchars($table_spec, ENT_QUOTES, 'UTF-8')
        . '</code>. '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</div>';
}
<?php
use SLiMS\Plugins;
defined('INDEX_AUTH') or die('Direct access is not allowed!');

$schema = $activeSchema->fetchObject();
if ($schema === false) {
    $content = '<div class="alert alert-warning">Skema aktif tidak ditemukan.</div>';
    require SB . '/admin/' . $sysconf['admin_template']['dir'] . '/notemplate_page_tpl.php';
    exit;
}
$table_slug = trim(strtolower(str_replace(' ', '_', (string) $schema->name)));
$table_name = registrationTableName($schema);

Plugins::getInstance()->execute('member_self_before_preview_detail', ['schema' => $schema, 'table_name' => $table_slug]);

$record = \SLiMS\DB::getInstance()->prepare('select * from `' . $table_name . '` where member_id = ?');
$record->execute([$_GET['member_id'] ?? '']);

$row = $record->fetch(PDO::FETCH_ASSOC);
$content = formGenerator($schema, is_array($row) ? $row : [], pluginUrl(['acc_member' => 'yes']));

// include the page template
require SB.'/admin/'.$sysconf['admin_template']['dir'].'/notemplate_page_tpl.php';
exit;
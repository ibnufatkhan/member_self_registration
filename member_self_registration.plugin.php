<?php
/**
 * Plugin Name: member_self_registration
 * Plugin URI: https://github.com/ibnufatkhan/member_self_registration
 * Description: Plugin untuk daftar online, kompatibel PHP 8.3
 * Version: 2.1.0
 * Author: Drajat Hasan
 * Author URI: https://github.com/drajathasan
 * Contributor: Ibnufatkhan
 * Contributor URI: https://github.com/ibnufatkhan
 * Requires PHP: 8.3
 *
 * Kredit:
 * - Drajat Hasan — pengembang asli plugin
 * - Ibnufatkhan — port dan penyesuaian untuk PHP 8.3
 */

use SLiMS\DB;
use SLiMS\Plugins;
use SLiMS\Url;
use SLiMS\Table\Schema;

define('MSLR', __DIR__);

define('MSWB', (string) Url::getSlimsBaseUri('plugins/' . basename(MSLR) . '/'));

include_once __DIR__ . DS . 'helper.php';

$plugin = Plugins::getInstance();

$plugin->registerMenu('membership', 'Daftar Online', __DIR__ . '/pages/membership/index.php');

$table = 'self_registration_schemas';

if (Schema::hasTable($table)) {
    $activeSchema = DB::getInstance()->query('select id,name from `' . $table . '` where status = 1');

    if ($activeSchema->rowCount() > 0) {
        $data = $activeSchema->fetchObject();
        if ($data !== false) {
            $plugin->registerMenu('opac', $data->name, __DIR__ . DS . 'pages' . DS . 'opac' . DS . 'index.php');
        }
    }
}

$plugin->register(Plugins::MEMBERSHIP_INIT, function () use ($table) {
    global $member_custom_fields, $can_read, $can_write, $sysconf, $dbs;

    include __DIR__ . '/pages/customs/membership/index.php';
    exit;
});

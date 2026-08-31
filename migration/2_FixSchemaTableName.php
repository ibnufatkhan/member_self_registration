<?php
/**
 * @author Ibnufatkhan
 * @requires PHP >= 8.3
 * @desc Enlarge schema name and store physical table name to avoid VARCHAR(32) truncation mismatch.
 */

use SLiMS\DB;
use SLiMS\Table\Schema;
use SLiMS\Migration\Migration;

class FixSchemaTableName extends Migration
{
    public function up()
    {
        $db = DB::getInstance();

        $db->query('ALTER TABLE `self_registration_schemas` MODIFY `name` VARCHAR(128) NOT NULL');

        if (!Schema::hasColumn('self_registration_schemas', 'table_name')) {
            Schema::table('self_registration_schemas', function ($table) {
                $table->string('table_name', 64)->nullable()->add();
            });
        }

        if (!function_exists('resolveSchemaTableName')) {
            include_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'helper.php';
        }

        $schemas = $db->query('select `id`, `name`, `table_name` from `self_registration_schemas`');
        $update = $db->prepare('update `self_registration_schemas` set `table_name` = ? where `id` = ?');

        while ($row = $schemas->fetchObject()) {
            if ($row === false) {
                continue;
            }

            $stored = trim((string) ($row->table_name ?? ''));
            if ($stored !== '' && Schema::hasTable($stored)) {
                continue;
            }

            $resolved = resolveSchemaTableName((string) $row->name);
            if (Schema::hasTable($resolved)) {
                $update->execute([$resolved, $row->id]);
            }
        }
    }

    public function down()
    {
    }
}

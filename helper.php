<?php
/**
 * @author Drajat Hasan
 * @contributor Ibnufatkhan
 * @requires PHP >= 8.3
 */

use SLiMS\Url;
use SLiMS\Captcha\Factory as Captcha;
use SLiMS\Filesystems\Storage;
use SLiMS\Table\Schema;
use SLiMS\Table\Grammar\Mysql;

if (!function_exists('getActiveSchemaData')) {
    function getActiveSchemaData(): ?object
    {
        $state = \SLiMS\DB::getInstance()->query('select * from self_registration_schemas where status = 1');

        if ($state->rowCount() < 1) {
            return null;
        }

        $row = $state->fetchObject();

        return $row === false ? null : $row;
    }
}

if (!function_exists('decodeJson')) {
    /**
     * Decode JSON with PHP 8.3 json_validate() and safe fallbacks.
     */
    function decodeJson(mixed $json, bool $assoc = false): mixed
    {
        if (is_array($json) || is_object($json)) {
            return $json;
        }

        if (!is_string($json) || $json === '' || !json_validate($json)) {
            return $assoc ? [] : (object) [];
        }

        $decoded = json_decode($json, $assoc);

        if ($assoc) {
            return is_array($decoded) ? $decoded : [];
        }

        return is_object($decoded) ? $decoded : (object) [];
    }
}

if (!function_exists('buildSchemaTableName')) {
    /**
     * Compute the physical table name from a schema display name.
     * The schemas.name column used to be VARCHAR(32), so a longer name can be
     * truncated in the database while CREATE TABLE used the original string.
     */
    function buildSchemaTableName(string $name): string
    {
        $slug = strtolower(trim((string) preg_replace('/\s+/', '_', $name)));
        $slug = trim($slug, '_');

        return 'self_registration_' . $slug;
    }
}

if (!function_exists('listSelfRegistrationTables')) {
    /**
     * @return list<string>
     */
    function listSelfRegistrationTables(): array
    {
        static $cache = null;

        if (is_array($cache)) {
            return $cache;
        }

        $cache = [];

        try {
            $statement = \SLiMS\DB::getInstance()->query('SHOW TABLES');
            while ($row = $statement->fetch(PDO::FETCH_NUM)) {
                $table = (string) ($row[0] ?? '');
                if ($table !== '' && str_starts_with($table, 'self_registration_') && $table !== 'self_registration_schemas') {
                    $cache[] = $table;
                }
            }
        } catch (Throwable) {
            $cache = [];
        }

        return $cache;
    }
}

if (!function_exists('resolveSchemaTableName')) {
    function resolveSchemaTableName(string $name): string
    {
        $computed = buildSchemaTableName($name);

        try {
            if (Schema::hasTable($computed)) {
                return $computed;
            }
        } catch (Throwable) {
            // Fall through to prefix matching when the truncated name does not exist.
        }

        $matches = [];
        foreach (listSelfRegistrationTables() as $table) {
            if ($table === $computed || str_starts_with($table, $computed)) {
                $matches[] = $table;
            }
        }

        if ($matches === []) {
            return $computed;
        }

        usort($matches, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $matches[0];
    }
}

if (!function_exists('registrationTableName')) {
    function registrationTableName(?object $schema, ?string $fallbackName = null): string
    {
        $stored = is_object($schema) ? trim((string) ($schema->table_name ?? '')) : '';
        if ($stored !== '') {
            try {
                if (Schema::hasTable($stored)) {
                    return $stored;
                }
            } catch (Throwable) {
            }
        }

        $name = $fallbackName ?? (is_object($schema) ? (string) ($schema->name ?? '') : '');

        return resolveSchemaTableName($name);
    }
}

if (!function_exists('schemaTableName')) {
    function schemaTableName(string $name): string
    {
        return resolveSchemaTableName($name);
    }
}

if (!function_exists('rememberSchemaTableName')) {
    function rememberSchemaTableName(int|string $schemaId, string $tableName): void
    {
        if ($schemaId === '' || $schemaId === 0 || $tableName === '') {
            return;
        }

        try {
            if (!Schema::hasColumn('self_registration_schemas', 'table_name')) {
                return;
            }

            \SLiMS\DB::getInstance()
                ->prepare('update `self_registration_schemas` set `table_name` = ? where `id` = ?')
                ->execute([$tableName, $schemaId]);
        } catch (Throwable) {
            return;
        }
    }
}

if (!function_exists('isAdminUrl')) {
    function isAdminUrl(string $url): bool
    {
        return str_contains(strtolower($url), 'admin');
    }
}

if (!function_exists('getMysqlBlueprintTypeMap')) {
    /**
     * Read SLiMS Mysql grammar type map without array_pop() on temporary arrays.
     *
     * @return array{mysql: list<string>, blueprint: list<string>, map: array<string, string>}
     */
    function getMysqlBlueprintTypeMap(): array
    {
        $property = new ReflectionProperty(Mysql::class, 'types');
        /** @var array<string, string> $types */
        $types = $property->getValue();

        return [
            'mysql' => array_values($types),
            'blueprint' => array_keys($types),
            'map' => $types,
        ];
    }
}

if (!function_exists('findLastKeyByValue')) {
    function findLastKeyByValue(array $items, mixed $needle): int|string|null
    {
        $matches = array_keys($items, $needle, true);

        if ($matches === []) {
            return null;
        }

        return $matches[array_key_last($matches)];
    }
}

if (!function_exists('findLastMatching')) {
    function findLastMatching(array $items, callable $callback): mixed
    {
        $filtered = array_filter($items, $callback);

        return $filtered === [] ? null : $filtered[array_key_last($filtered)];
    }
}

if (!function_exists('applyStructureColumn')) {
    function applyStructureColumn(
        object $table,
        array $column,
        array $memberSchema,
        array $typeMap,
        bool $nullable = false,
        bool $add = false
    ): void {
        $detail = findLastMatching(
            $memberSchema,
            static fn(array $schemaColumn): bool => ($column['field'] ?? '') === ($schemaColumn['COLUMN_NAME'] ?? null)
        );

        $dataType = is_array($detail)
            ? ($detail['DATA_TYPE'] ?? ($column['advfieldtype'] ?? null))
            : ($column['advfieldtype'] ?? null);

        $typeId = findLastKeyByValue($typeMap['mysql'], $dataType);
        $blueprintMethod = ($typeId !== null && isset($typeMap['blueprint'][$typeId]))
            ? $typeMap['blueprint'][$typeId]
            : (string) ($dataType ?? 'string');

        if (($column['field'] ?? '') !== 'advance' && (empty($detail) || $typeId === null || !isset($typeMap['blueprint'][$typeId]))) {
            return;
        }

        $field = trim((string) (empty($column['advfield'] ?? '') ? ($column['field'] ?? '') : $column['advfield']));

        if ($blueprintMethod === 'enum') {
            [$field, $data] = array_pad(explode(',', $field, 2), 2, '');
            $detail = is_array($detail) ? $detail : [];
            $detail['CHARACTER_MAXIMUM_LENGTH'] = explode('|', trim($data));
        }

        if ($blueprintMethod === 'enum_radio') {
            $blueprintMethod = 'string';
            [$field] = array_pad(explode(',', $field, 2), 2, '');
        }

        if ($blueprintMethod === 'text_multiple') {
            $blueprintMethod = 'text';
            [$field] = array_pad(explode(',', $field, 2), 2, '');
        }

        if (in_array($field, ['member_id', 'member_name'], true) && !$add) {
            $table->index($field);
            if ($field === 'member_id') {
                $table->unique('member_id');
            }
        }

        $params = !in_array($blueprintMethod, ['text', 'date', 'datetime'], true)
            ? [$field, is_array($detail) ? ($detail['CHARACTER_MAXIMUM_LENGTH'] ?? 64) : 64]
            : [$field];

        $definition = $table->{$blueprintMethod}(...$params);

        if ($nullable) {
            $definition = $definition->nullable();
        } else {
            $definition = $definition->notNull();
        }

        if ($add) {
            $definition->add();
        }
    }
}

if (!function_exists('createSelfRegistrationTables')) {
    function createSelfRegistrationTables(
        string $newTable,
        array $structure,
        bool $hadCustomTable,
        bool $skipExistingCustomColumns = false
    ): void {
        $typeMap = getMysqlBlueprintTypeMap();
        $memberSchema = Schema::table('member')->columns(true);

        Schema::create($newTable, function ($table) use ($memberSchema, $typeMap, $structure): void {
            foreach ($structure as $column) {
                applyStructureColumn($table, $column, $memberSchema, $typeMap, nullable: false, add: false);
            }

            $table->timestamps();
            $table->engine = 'MyISAM';
            $table->charset = 'utf8';
            $table->collation = 'utf8_unicode_ci';
        });

        if (!$hadCustomTable) {
            return;
        }

        $customStructure = $structure;
        if ($skipExistingCustomColumns) {
            $customStructure = array_values(array_filter($structure, static function (array $column): bool {
                $advfield = trim((string) ($column['advfield'] ?? ''));
                if ($advfield === '') {
                    return false;
                }

                $field = explode(',', $advfield)[0];

                return !Schema::hasColumn('member_custom', $field);
            }));
        }

        if ($customStructure === []) {
            return;
        }

        Schema::table('member_custom', function ($table) use ($memberSchema, $typeMap, $customStructure): void {
            foreach ($customStructure as $column) {
                if (($column['field'] ?? '') !== 'advance') {
                    continue;
                }

                applyStructureColumn($table, $column, $memberSchema, $typeMap, nullable: true, add: true);
            }
        });
    }
}

if (!function_exists('action')) {
    function action(string $actionName, array $attribute = []): void
    {
        global $sysconf;
        extract($attribute);
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $trace[1] ?? ($trace[0] ?? []);
        $callerFile = $caller['file'] ?? '';
        $info = pathinfo($callerFile);

        if (file_exists($path = ($info['dirname'] ?? '') . DS . 'action' . DS . basename($actionName) . '.php')) {
            include $path;
        } else {
            throw new Exception('Action ' . $actionName . ' is not found!', 404);
        }
    }
}

if (!function_exists('pluginUrl')) {
    /**
     * Generate URL with plugin_container.php?id=<id>&mod=<mod> + custom query
     */
    function pluginUrl(array $data = [], bool $reset = false): string
    {
        $mod = (string) ($_GET['mod'] ?? '');
        $id = (string) ($_GET['id'] ?? '');

        if ($reset) {
            return Url::getSelf(fn($self) => $self . '?mod=' . $mod . '&id=' . $id);
        }

        return Url::getSelf(function ($self) use ($data) {
            return $self . '?' . http_build_query(array_merge($_GET, $data));
        });
    }
}

if (!function_exists('textColor')) {
    // source : https://www.bitbook.io/php-function-to-calculate-the-best-font-color-for-a-background-color/
    function textColor(string $hexCode): string
    {
        $hexCode = ltrim($hexCode, '#');
        $hexCode = str_pad(substr($hexCode, 0, 6), 6, '0');

        $redHex = substr($hexCode, 0, 2);
        $greenHex = substr($hexCode, 2, 2);
        $blueHex = substr($hexCode, 4, 2);

        $r = hexdec($redHex) / 255;
        $g = hexdec($greenHex) / 255;
        $b = hexdec($blueHex) / 255;

        $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

        return $brightness > 0.6 ? '000000' : 'ffffff';
    }
}

if (!function_exists('formGenerator')) {
    /**
     * Form generator based on schema
     */
    function formGenerator(object $data, array $record = [], string $actionUrl = '', mixed $opac = null): string
    {
        $structure = decodeJson($data->structure ?? '[]', true);
        $option = decodeJson($data->option ?? '{}');
        $info = decodeJson($data->info ?? '{}');

        ob_start();

        $js = '';
        $withUpload = '';
        if (($option->image ?? false)) {
            $withUpload = 'enctype="multipart/form-data"';
        }

        echo '<form id="self_member" method="POST" action="' . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '" ' . $withUpload . '>';

        if ($key = flash()->includes('self_regis_error')) {
            flash()->danger($key);
        }

        if ($actionUrl === '' || isAdminUrl($actionUrl)) {
            if ($actionUrl === '') {
                echo '<h3>Pratinjau</h3>';
                echo '<h5>Skema ' . htmlspecialchars((string) $data->name, ENT_QUOTES, 'UTF-8') . '</h5>';
            } else {
                echo '<h3>Pratinjau Data</h3>';
                echo '<h5>Calon anggota ' . htmlspecialchars((string) ($record['member_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</h5>';
            }
        } else {
            if ($opac !== null) {
                $opac->page_title = $info->title ?? '';
            }
            $descInfo = '<div class="alert alert-info p-3">' . strip_tags((string) ($info->desc ?? ''), '<p><a><i><em><h1><h2><h3><ul><ol><li>') . '</div>';
        }

        if (($info->position ?? '') === 'top' && isset($descInfo)) {
            echo $descInfo;
        }

        foreach ($structure as $key => $column) {
            if (isAdminUrl($actionUrl)) {
                if (empty($column['advfield'] ?? '')) {
                    $key = $column['field'] ?? $key;
                } else {
                    $advfield = explode(',', (string) $column['advfield']);
                    $key = $advfield[0];
                }
            }

            $is_required = (($column['is_required'] ?? false) === true) ? ' required' : '';

            $required_mark = $is_required ? '<em class="text-danger">*</em>' : '';
            $columnName = htmlspecialchars((string) ($column['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            echo <<<HTML
            <div class="my-3">
                <label class="form-label"><strong>{$columnName} {$required_mark}</strong></label>
            HTML;

            $defaultValue = $record[$column['field'] ?? ''] ?? $record[$column['advfield'] ?? ''] ?? '';

            if (in_array($column['advfieldtype'] ?? '', ['enum', 'enum_radio', 'text_multiple'], true)) {
                [$name] = array_pad(explode(',', (string) ($column['advfield'] ?? ''), 2), 2, '');
                $defaultValue = $record[$name] ?? '';
            }

            switch ($column['field'] ?? '') {
                case 'mpasswd':
                    if ($actionUrl !== '') {
                        $is_required = '';
                    }
                    echo <<<HTML
                    <br>
                    <small>tulis dibawah berikut</small>
                    <input type="password" placeholder="masukan {$columnName} anda" name="form[{$key}]" id="pass1" class="form-control" {$is_required}>
                    <small>konfirmasi ulang password anda</small>
                    <input type="password" name="confirm_password" placeholder="masukan ulang {$columnName} anda" id="pass2" class="form-control" {$is_required}>
                    HTML;
                    break;

                case 'gender':
                    $man = ((string) $defaultValue === '1') ? 'selected' : '';
                    $woman = ((string) $defaultValue === '0') ? 'selected' : '';
                    echo <<<HTML
                    <select name="form[{$key}]" class="form-control" {$is_required}>
                        <option>Pilih</option>
                        <option value="1" {$man}>Laki-Laki</option>
                        <option value="0" {$woman}>Perempuan</option>
                    </select>
                    HTML;
                    break;

                case 'member_address':
                    $escapedValue = htmlspecialchars((string) $defaultValue, ENT_QUOTES, 'UTF-8');
                    echo <<<HTML
                    <textarea name="form[{$key}]" placeholder="masukan {$columnName} anda" class="form-control" {$is_required}>{$escapedValue}</textarea>
                    HTML;
                    break;

                case 'member_type_id':
                    $memberType = \SLiMS\DB::getInstance()->query('select member_type_id, member_type_name from mst_member_type');
                    echo '<select class="form-control" name="form[' . $key . ']" ' . $is_required . '>';
                    echo '<option value="0">Pilih</option>';
                    while ($result = $memberType->fetch(PDO::FETCH_NUM)) {
                        $selected = ((string) $defaultValue === (string) $result[0]) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars((string) $result[0], ENT_QUOTES, 'UTF-8') . '" ' . $selected . '>' . htmlspecialchars((string) $result[1], ENT_QUOTES, 'UTF-8') . '</option>';
                    }
                    echo '</select>';
                    break;

                case 'advance':
                    switch ($column['advfieldtype'] ?? '') {
                        case 'varchar':
                        case 'int':
                            $types = ['varchar' => 'text', 'int' => 'number'];
                            $type = $types[$column['advfieldtype']];
                            $escapedValue = htmlspecialchars((string) $defaultValue, ENT_QUOTES, 'UTF-8');
                            echo <<<HTML
                            <input type="{$type}" name="form[{$key}]" value="{$escapedValue}" placeholder="masukan {$columnName} anda" class="form-control" {$is_required}/>
                            HTML;
                            break;

                        case 'text':
                            $escapedValue = htmlspecialchars((string) $defaultValue, ENT_QUOTES, 'UTF-8');
                            echo <<<HTML
                            <textarea name="form[{$key}]" placeholder="masukan {$columnName} anda" class="form-control" {$is_required}>{$escapedValue}</textarea>
                            HTML;
                            break;

                        case 'enum':
                            [, $list] = array_pad(explode(',', (string) ($column['advfield'] ?? ''), 2), 2, '');
                            echo '<select name="form[' . $key . ']" class="form-control">';
                            echo '<option value="">Pilih</option>';
                            foreach (explode('|', $list) as $item) {
                                $selected = ((string) $defaultValue === (string) $item) ? 'selected' : '';
                                echo '<option value="' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '" ' . $selected . '>' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</option>';
                            }
                            echo '</select>';
                            break;

                        case 'enum_radio':
                            $field = explode(',', (string) ($column['advfield'] ?? ''), 2);
                            $uniqueId = md5($field[0] ?? '');
                            $checked = '';

                            if ($is_required) {
                                $js .= <<<HTML
                                if ($('.radio{$uniqueId}:checked').length < 1) {
                                    evt.preventDefault();
                                    alert('Pilih salah satu dari isian {$columnName}');
                                    return;
                                }
                                HTML;
                            }

                            echo '<div class="d-flex flex-column">';
                            foreach (explode('|', trim($field[1] ?? '')) as $optionKey => $value) {
                                if ($value === '') {
                                    continue;
                                }
                                if ((string) $defaultValue === (string) $value) {
                                    $checked = 'checked';
                                }
                                $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                                echo '<div>
                                    <input class="radio' . $uniqueId . '" id="radio' . $uniqueId . '-' . $optionKey . '" data-title="' . $columnName . '" type="radio" name="form[' . $key . ']" value="' . $safeValue . '" ' . $checked . '/>
                                    <label for="radio' . $uniqueId . '-' . $optionKey . '" style="cursor: pointer">' . $safeValue . '</label>
                                </div>';
                                $checked = '';
                            }
                            echo '</div>';
                            break;

                        case 'text_multiple':
                            $field = explode(',', (string) ($column['advfield'] ?? ''), 2);
                            $uniqueId = md5($field[0] ?? '');
                            $defaultList = is_array($defaultValue)
                                ? $defaultValue
                                : decodeJson(is_string($defaultValue) ? $defaultValue : '[]', true);
                            $checked = '';

                            if ($is_required) {
                                $js .= <<<HTML
                                if ($('.checkbox{$uniqueId}:checked').length < 1) {
                                    evt.preventDefault();
                                    alert('Pilih salah satu dari isian {$columnName}');
                                    return;
                                }
                                HTML;
                            }

                            echo '<div class="d-flex flex-column">';
                            foreach (explode('|', trim($field[1] ?? '')) as $optionKey => $value) {
                                if ($value === '') {
                                    continue;
                                }
                                if (in_array($value, $defaultList, true)) {
                                    $checked = 'checked';
                                }
                                $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                                echo '<div class="mx-3">
                                    <input class="checkbox' . $uniqueId . '" id="checkbox' . $uniqueId . '-' . $optionKey . '" type="checkbox" name="form[' . $key . '][]" value="' . $safeValue . '" ' . $checked . '/>
                                    <label for="checkbox' . $uniqueId . '-' . $optionKey . '" style="cursor: pointer">' . $safeValue . '</label>
                                </div>';
                                $checked = '';
                            }
                            echo '</div>';
                            break;
                    }
                    break;

                case 'member_image':
                    if (($option->image ?? null) === null) {
                        echo '<div class="alert alert-info font-weight-bold">Anda belum mengantur ruas ini pada "Pengaturan Form"</div>';
                    } else {
                        if (!isset($record['member_image'])) {
                            echo <<<HTML
                            <input type="file" name="member_image" placeholder="masukan {$columnName} anda" class="form-control d-block" {$is_required}/>
                            <small>Maksimal ukuran file foto adalah 2MB</small>
                            HTML;
                        } else {
                            $image = Storage::images()->isExists('persons/' . $record['member_image']) ? $record['member_image'] : 'avatar.jpg';
                            echo '<img class="d-block" src="' . SWB . 'lib/minigalnano/createthumb.php?filename=images/persons/' . rawurlencode((string) $image) . '&width=120"/>';
                        }
                    }
                    break;

                default:
                    $types = ['birth_date' => 'date', 'member_email' => 'email'];
                    $type = $types[$column['field'] ?? ''] ?? 'text';
                    $escapedValue = htmlspecialchars((string) $defaultValue, ENT_QUOTES, 'UTF-8');
                    echo <<<HTML
                    <input type="{$type}" name="form[{$key}]" value="{$escapedValue}" placeholder="masukan {$columnName} anda" class="form-control" {$is_required}/>
                    HTML;
                    break;
            }

            echo <<<HTML
            </div>
            HTML;
        }

        if (($info->position ?? '') === 'bottom' && isset($descInfo)) {
            echo $descInfo;
        }

        if (($option->with_agreement ?? false) && !isAdminUrl($actionUrl)) {
            echo <<<HTML
            <div>
                <input type="checkbox" id="iAgree"/>
                <label for="iAgree" style="cursor: pointer">Saya menyetujui prasyarat diatas</label>
            </div>
            HTML;
        }

        if ($actionUrl !== '') {
            $captcha = Captcha::section('memberarea');

            if (!isAdminUrl($actionUrl)) {
                if (($option->captcha ?? false) && $captcha->isSectionActive() && config('captcha', false)) {
                    echo '<div class="captchaMember my-2">';
                    echo $captcha->getCaptcha();
                    echo '</div>';
                }

                echo \Volnix\CSRF\CSRF::getHiddenInputString();

                $disableBeforeAgree = '';
                if ($option->with_agreement ?? false) {
                    $disableBeforeAgree = 'disabled';
                }

                echo '<div class="form-group">
                    <input type="hidden" name="action" value="save"/>
                    <button class="btn btn-primary" type="submit" name="save" ' . $disableBeforeAgree . ' ' . (empty($disableBeforeAgree) ? '' : 'title="Klik \'Saya menyetujui prasyarat diatas\'"') . '>Daftar</button>
                    <button class="btn btn-outline-secondary" type="reset" name="save">Batal</button>
                </div>
                ';
            } else {
                echo '<div class="form-group">
                    <input type="hidden" name="action" value="acc"/>
                    <button class="btn btn-success" type="submit" name="acc">Setujui</button>
                    <a class="btn btn-danger" href="' . pluginUrl(['section' => 'view_detail', 'member_id' => $_GET['member_id'] ?? 0, 'headless' => 'yes', 'action' => 'delete_reg']) . '">Hapus</a>
                </div>';
            }
            if (!isAdminUrl($actionUrl)) {
                echo '<strong><em class="text-danger">*</em> ) wajib diisi</strong>';
            }
        }
        echo '</form>';

        if (!isAdminUrl($actionUrl)) {
            $agreeJs = '';
            if ($option->with_agreement ?? false) {
                $agreeJs = <<<HTML
                $('#iAgree').click(function() {
                    if ($('#iAgree:checked').length < 1) { 
                        $('button[name="save"]').prop('disabled', true)
                        $('button[name="save"]').prop('title', 'Klik \'Saya menyetujui prasyarat diatas\'')
                    } else {
                        $('button[name="save"]').prop('title', 'Klik untuk menyimpan data')
                        $('button[name="save"]').prop('disabled', false)
                    }
                });
                HTML;
            }
            echo <<<HTML
            <script>
                $(document).ready(function() {
                    {$agreeJs}
                    $('#self_member').submit(function(evt) {
                        {$js}
                    })
                })
            </script>
            HTML;
        }

        return (string) ob_get_clean();
    }
}

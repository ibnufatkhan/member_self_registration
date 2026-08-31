<?php
/**
 * @author Drajat Hasan
 * @contributor Ibnufatkhan
 * @requires PHP >= 8.3
 */

use SLiMS\DB;
use SLiMS\Plugins;
use SLiMS\Filesystems\Storage;
use SLiMS\Captcha\Factory as Captcha;
use SLiMS\Table\Schema;
use Volnix\CSRF\CSRF;

defined('INDEX_AUTH') or die('Direct access is not allowed!');

if (!isset($_POST['form']) || !is_array($_POST['form'])) {
    return;
}

try {
    $structure = decodeJson($schema->structure ?? '[]', true);
    $option = decodeJson($schema->option ?? '{}');

    $passwordMatches = array_filter(
        $structure,
        static fn(array $item): bool => ($item['field'] ?? '') === 'mpasswd'
    );
    $passwordFieldId = $passwordMatches === [] ? null : array_key_last($passwordMatches);

    if (!CSRF::validate($_POST)) {
        // Keep original behaviour: CSRF is recorded but not blocking.
    }

    $captcha = Captcha::section('memberarea');
    if (($option->captcha ?? false) && $captcha->isSectionActive() && $captcha->isValid() === false) {
        $message = isDev() ? $captcha->getError() : __('Wrong Captcha Code entered, Please write the right code!');
        session_unset();
    }

    if (
        $passwordFieldId !== null
        && isset($_POST['form'][$passwordFieldId])
        && (string) $_POST['form'][$passwordFieldId] !== (string) ($_POST['confirm_password'] ?? '')
    ) {
        throw new Exception('Password tidak cocok');
    }

    $sqlSet = [];
    $sqlParams = [];
    $registrationTable = registrationTableName($schema);
    if (!Schema::hasTable($registrationTable)) {
        throw new Exception('Tabel pendaftaran tidak ditemukan. Skema aktif tidak memiliki tabel data.');
    }

    $sqlRaw = 'insert ignore into `' . $registrationTable . '` set ';

    foreach ($_POST['form'] as $order => $value) {
        if (!isset($structure[$order]) || !is_array($structure[$order])) {
            continue;
        }

        $detail = $structure[$order];

        if (($detail['field'] ?? '') === 'advance') {
            if (in_array($detail['advfieldtype'] ?? '', ['enum', 'enum_radio', 'text_multiple'], true)) {
                $field = explode(',', (string) ($detail['advfield'] ?? ''), 2);
                $detail['field'] = $field[0] ?? '';
            } else {
                $detail['field'] = $detail['advfield'] ?? '';
            }
        }

        $fieldName = (string) ($detail['field'] ?? '');
        if ($fieldName === '') {
            continue;
        }

        $sqlSet[] = '`' . $fieldName . '` = ?';

        if ($fieldName === 'mpasswd') {
            $value = password_hash((string) $value, PASSWORD_BCRYPT);
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }

        $sqlParams[] = $value;
    }

    if ($option->image ?? false) {
        if (($_FILES['member_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE) {
            $max = ini_get('upload_max_filesize');
            throw new Exception("Gagal membaca file foto profil, karena file terindikasi lebih besar dari nilai di server ({$max}B)");
        }

        $images_disk = Storage::images();
        if (!empty($_FILES['member_image']) && ($_FILES['member_image']['size'] ?? 0)) {
            $newFilename = md5((string) random_int(1, 1000) . date('this'));

            $image_upload = $images_disk->upload('member_image', function ($images) use ($sysconf) {
                $images->isExtensionAllowed($sysconf['allowed_images']);
                $images->isLimitExceeded(500 * 1024);

                if (!empty($images->getError())) {
                    $images->destroyIfFailed();
                }
            })->as('persons' . DS . $newFilename);

            if ($image_upload->getUploadStatus()) {
                $sqlSet[] = '`member_image` = ?';
                $sqlParams[] = $image_upload->getUploadedFileName();
            } else {
                throw new Exception('Gagal upload foto profil karena : ' . $image_upload->getError());
            }
        }
    }

    $sqlSet[] = '`created_at` = now()';
    $query = $sqlRaw . implode(',', $sqlSet);

    Plugins::getInstance()->execute('member_self_before_save', ['query' => $query, 'sqlParams' => $sqlParams]);

    if (!defined('MSRPLUS_BYPASS_INSERT')) {
        $insert = DB::getInstance()->prepare($query);
        $insert->execute($sqlParams);

        if ($insert->rowCount() === 0) {
            throw new Exception('Data tidak berhasil disimpan, mungkin karena data sudah ada.');
        }
    }

    if ($option->message_after_save ?? false) {
        toastr($option->message_after_save)->jsAlert();
    }
} catch (Exception $e) {
    redirect()->withMessage('self_regis_error', $e->getMessage())->back();
}

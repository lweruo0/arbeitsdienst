<?php
/**
 ***********************************************************************************************
 * Deinstallation des alten Admidio-Plugins "Arbeitsdienst" (WSVBS)
 *
 * Das alte Plugin hat keine eigene Deinstallation. Dieses Skript entfernt alles, was es angelegt hat:
 *  - Profilfeld-Kategorie ARBEITSDIENST (Typ USF, Anzeigename "PAD_ARBEITSDIENST") mit den Feldern
 *    WORKPAID, WORKFEE, WORKREFERENCE, WORKSEQUENCETYPE, WORKDUEDATE und deren Werten
 *  - Menuepunkt(e) mit Link auf /adm_plugins/arbeitsdienst/ samt "Sichtbar fuer"-Rechten
 *  - Einstellungen PAD__* in adm_plugin_preferences
 *  - Tabelle adm_user_arbeitsdienst (optional, nur wenn angehakt)
 *
 * NICHT angefasst werden die Rollen-Kategorie "Arbeitsdienst" (Typ ROL) und die neue Kategorie
 * WORK_SERVICE<org> mit den AD*-Feldern des Plugins Mitgliedsbeitrag.
 *
 * Ablauf: Aufruf zeigt eine Voransicht (es wird nichts geaendert). Erst "Jetzt deinstallieren"
 * sichert alle betroffenen Zeilen als JSON in adm_my_files und loescht sie in einer Transaktion.
 * Den Ordner adm_plugins/arbeitsdienst danach per FTP loeschen.
 *
 * Ablage: adm_plugins/arbeitsdienst/deinstall_arbeitsdienst.php
 * Aufruf: <admidio-url>/adm_plugins/arbeitsdienst/deinstall_arbeitsdienst.php (nur Administratoren)
 *
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;

// system/common.php auch finden, wenn das Skript in einem anderen Plugin-Ordner liegt
$adminRoot = __DIR__;
while (!is_file($adminRoot . '/system/common.php') && dirname($adminRoot) !== $adminRoot) {
    $adminRoot = dirname($adminRoot);
}
require_once($adminRoot . '/system/common.php');
require_once($adminRoot . '/system/login_valid.php');

const PAD_FIELDS = array('WORKPAID', 'WORKFEE', 'WORKREFERENCE', 'WORKSEQUENCETYPE', 'WORKDUEDATE');
const PAD_CATEGORY = 'ARBEITSDIENST';
const PAD_MENU_URL = '%/adm_plugins/arbeitsdienst/%';
const PAD_PREF_PREFIX = 'PAD\_\_%';

/**
 * Ermittelt alle betroffenen Datensaetze (ohne etwas zu aendern).
 *
 * @return array<string, mixed>
 */
function pad_inventory(): array
{
    global $gDb, $gCurrentOrgId;

    $orgId = (int) $gCurrentOrgId;
    $inv = array('warnings' => array());

    // Kategorie: nur Profilfeld-Kategorien (USF) dieser Organisation (oder global)
    $inv['categories'] = $gDb->queryPrepared(
        'SELECT * FROM ' . TBL_CATEGORIES . ' WHERE cat_type = \'USF\' AND cat_name_intern = ? AND (cat_org_id = ? OR cat_org_id IS NULL)',
        array(PAD_CATEGORY, $orgId)
    )->fetchAll(PDO::FETCH_ASSOC);
    $catIds = array_map('intval', array_column($inv['categories'], 'cat_id'));

    // Profilfelder ueber den internen Namen
    $placeholders = implode(',', array_fill(0, count(PAD_FIELDS), '?'));
    $inv['user_fields'] = $gDb->queryPrepared(
        'SELECT * FROM ' . TBL_USER_FIELDS . ' WHERE usf_name_intern IN (' . $placeholders . ')',
        PAD_FIELDS
    )->fetchAll(PDO::FETCH_ASSOC);
    $usfIds = array_map('intval', array_column($inv['user_fields'], 'usf_id'));

    foreach ($inv['user_fields'] as $field) {
        if (!in_array((int) $field['usf_cat_id'], $catIds, true)) {
            $inv['warnings'][] = 'Feld ' . $field['usf_name_intern'] . ' liegt nicht in der Kategorie ' . PAD_CATEGORY . ' (cat_id ' . $field['usf_cat_id'] . ') – es wird trotzdem entfernt.';
        }
    }

    // weitere (fremde) Felder in der Kategorie? Dann die Kategorie behalten.
    $inv['foreign_fields'] = array();
    if (count($catIds) > 0) {
        $inv['foreign_fields'] = $gDb->queryPrepared(
            'SELECT usf_id, usf_name, usf_name_intern FROM ' . TBL_USER_FIELDS . ' WHERE usf_cat_id IN (' . implode(',', $catIds) . ')'
            . (count($usfIds) > 0 ? ' AND usf_id NOT IN (' . implode(',', $usfIds) . ')' : ''),
            array()
        )->fetchAll(PDO::FETCH_ASSOC);
        if (count($inv['foreign_fields']) > 0) {
            $inv['warnings'][] = 'In der Kategorie ' . PAD_CATEGORY . ' liegen weitere Felder (' . implode(', ', array_column($inv['foreign_fields'], 'usf_name_intern'))
                . '). Die Kategorie wird deshalb NICHT geloescht.';
            $inv['categories'] = array();
            $catIds = array();
        }
    }
    $inv['cat_ids'] = $catIds;
    $inv['usf_ids'] = $usfIds;

    $inv['user_data'] = count($usfIds) > 0
        ? $gDb->queryPrepared('SELECT * FROM ' . TBL_USER_DATA . ' WHERE usd_usf_id IN (' . implode(',', $usfIds) . ')', array())->fetchAll(PDO::FETCH_ASSOC)
        : array();

    $inv['category_rights'] = count($catIds) > 0
        ? $gDb->queryPrepared(
            'SELECT rrd.* FROM ' . TBL_ROLES_RIGHTS_DATA . ' rrd JOIN ' . TBL_ROLES_RIGHTS . ' ror ON ror.ror_id = rrd.rrd_ror_id
              WHERE ror.ror_name_intern LIKE \'category\_%\' AND rrd.rrd_object_id IN (' . implode(',', $catIds) . ')',
            array()
        )->fetchAll(PDO::FETCH_ASSOC)
        : array();

    // Menue
    $inv['menu'] = $gDb->queryPrepared('SELECT * FROM ' . TBL_MENU . ' WHERE men_url LIKE ?', array(PAD_MENU_URL))->fetchAll(PDO::FETCH_ASSOC);
    $menIds = array_map('intval', array_column($inv['menu'], 'men_id'));
    $inv['men_ids'] = $menIds;
    $inv['menu_rights'] = count($menIds) > 0
        ? $gDb->queryPrepared(
            'SELECT rrd.* FROM ' . TBL_ROLES_RIGHTS_DATA . ' rrd JOIN ' . TBL_ROLES_RIGHTS . ' ror ON ror.ror_id = rrd.rrd_ror_id
              WHERE ror.ror_name_intern LIKE \'menu\_%\' AND rrd.rrd_object_id IN (' . implode(',', $menIds) . ')',
            array()
        )->fetchAll(PDO::FETCH_ASSOC)
        : array();

    // Einstellungen
    $prefTable = TABLE_PREFIX . '_plugin_preferences';
    $inv['prefs'] = pad_table_exists($prefTable)
        ? $gDb->queryPrepared('SELECT * FROM ' . $prefTable . ' WHERE plp_name LIKE ? AND (plp_org_id = ? OR plp_org_id IS NULL)', array(PAD_PREF_PREFIX, $orgId))->fetchAll(PDO::FETCH_ASSOC)
        : array();

    // eigene Tabelle des alten Plugins
    $inv['hours_table'] = TABLE_PREFIX . '_user_arbeitsdienst';
    $inv['hours_exists'] = pad_table_exists($inv['hours_table']);
    $inv['hours'] = $inv['hours_exists']
        ? $gDb->queryPrepared('SELECT * FROM ' . $inv['hours_table'], array())->fetchAll(PDO::FETCH_ASSOC)
        : array();

    return $inv;
}

function pad_table_exists(string $table): bool
{
    global $gDb;
    return (int) $gDb->queryPrepared(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        array($table)
    )->fetchColumn() > 0;
}

function pad_e(string $text): string
{
    return SecurityUtils::encodeHTML($text);
}

try {
    if (!$gCurrentUser->isAdministrator()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $selfUrl = CURRENT_URL;
    $headline = 'Altes Plugin „Arbeitsdienst“ deinstallieren';
    $gNavigation->addStartUrl(CURRENT_URL, $headline, 'bi-trash');
    $page = new HtmlPage('pad-deinstall', $headline);

    $inv = pad_inventory();
    $isRun = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'run';

    if ($isRun) {
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
        $dropTable = !empty($_POST['drop_table']);

        // 1. Sicherung aller betroffenen Zeilen
        $backup = array(
            'created' => date('Y-m-d H:i:s'),
            'org_id' => (int) $gCurrentOrgId,
            'user' => $gCurrentUser->getValue('usr_login_name'),
        );
        foreach (array('categories', 'user_fields', 'user_data', 'category_rights', 'menu', 'menu_rights', 'prefs', 'hours') as $key) {
            $backup[$key] = $inv[$key];
        }
        $backupFile = ADMIDIO_PATH . FOLDER_DATA . '/backup_arbeitsdienst_alt_' . date('Ymd_His') . '.json';
        if (file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new Exception('Die Sicherung konnte nicht geschrieben werden: ' . $backupFile . ' – es wurde nichts geloescht.');
        }

        // 2. Loeschen in einer Transaktion
        $deleted = array();
        $gDb->startTransaction();
        if (count($inv['usf_ids']) > 0) {
            $ids = implode(',', $inv['usf_ids']);
            $deleted['Feldwerte'] = $gDb->queryPrepared('DELETE FROM ' . TBL_USER_DATA . ' WHERE usd_usf_id IN (' . $ids . ')', array())->rowCount();
            $deleted['Profilfelder'] = $gDb->queryPrepared('DELETE FROM ' . TBL_USER_FIELDS . ' WHERE usf_id IN (' . $ids . ')', array())->rowCount();
        }
        if (count($inv['cat_ids']) > 0) {
            $ids = implode(',', $inv['cat_ids']);
            $deleted['Kategorie-Rechte'] = $gDb->queryPrepared(
                'DELETE FROM ' . TBL_ROLES_RIGHTS_DATA . ' WHERE rrd_object_id IN (' . $ids . ')
                    AND rrd_ror_id IN (SELECT ror_id FROM ' . TBL_ROLES_RIGHTS . ' WHERE ror_name_intern LIKE \'category\_%\')',
                array()
            )->rowCount();
            $deleted['Kategorie'] = $gDb->queryPrepared('DELETE FROM ' . TBL_CATEGORIES . ' WHERE cat_id IN (' . $ids . ')', array())->rowCount();
        }
        if (count($inv['men_ids']) > 0) {
            $ids = implode(',', $inv['men_ids']);
            $deleted['Menue-Rechte'] = $gDb->queryPrepared(
                'DELETE FROM ' . TBL_ROLES_RIGHTS_DATA . ' WHERE rrd_object_id IN (' . $ids . ')
                    AND rrd_ror_id IN (SELECT ror_id FROM ' . TBL_ROLES_RIGHTS . ' WHERE ror_name_intern LIKE \'menu\_%\')',
                array()
            )->rowCount();
            $deleted['Menuepunkte'] = $gDb->queryPrepared('DELETE FROM ' . TBL_MENU . ' WHERE men_id IN (' . $ids . ')', array())->rowCount();
        }
        if (count($inv['prefs']) > 0) {
            $ids = implode(',', array_map('intval', array_column($inv['prefs'], 'plp_id')));
            $deleted['Einstellungen'] = $gDb->queryPrepared('DELETE FROM ' . TABLE_PREFIX . '_plugin_preferences WHERE plp_id IN (' . $ids . ')', array())->rowCount();
        }
        $gDb->endTransaction();

        // DROP TABLE beendet in MySQL jede Transaktion, daher erst danach
        if ($dropTable && $inv['hours_exists']) {
            $gDb->queryPrepared('DROP TABLE ' . $inv['hours_table'], array());
            $deleted['Tabelle ' . $inv['hours_table']] = 'entfernt (' . count($inv['hours']) . ' Zeilen gesichert)';
        }

        // Profilfelder und Menue in allen Sitzungen neu einlesen
        $gProfileFields->readProfileFields((int) $gCurrentOrgId);
        $gCurrentSession->reloadAllSessions();

        $html = '<div class="alert alert-success"><strong>Deinstallation abgeschlossen.</strong><ul class="mb-0">';
        foreach ($deleted as $what => $count) {
            $html .= '<li>' . pad_e($what) . ': ' . pad_e((string) $count) . '</li>';
        }
        $html .= '</ul></div>'
            . '<div class="alert alert-info">Sicherung: <code>' . pad_e(str_replace(ADMIDIO_PATH, '', $backupFile)) . '</code><br/>'
            . 'Jetzt noch den Ordner <code>adm_plugins/arbeitsdienst</code> (mit diesem Skript) per FTP loeschen.</div>';
        $page->addHtml($html);
        $page->show();
        exit;
    }

    // Voransicht
    $rows = array(
        array('Profilfeld-Kategorie ' . PAD_CATEGORY . ' (Typ Profilfelder)', count($inv['categories']), implode(', ', array_map(static fn ($c) => $c['cat_name'] . ' (ID ' . $c['cat_id'] . ')', $inv['categories']))),
        array('Profilfelder', count($inv['user_fields']), implode(', ', array_column($inv['user_fields'], 'usf_name_intern'))),
        array('Gespeicherte Feldwerte', count($inv['user_data']), ''),
        array('Rechte der Kategorie', count($inv['category_rights']), ''),
        array('Menuepunkte', count($inv['menu']), implode(', ', array_map(static fn ($m) => $m['men_name'] . ' (' . $m['men_url'] . ')', $inv['menu']))),
        array('Rechte der Menuepunkte („Sichtbar fuer“)', count($inv['menu_rights']), ''),
        array('Einstellungen PAD__*', count($inv['prefs']), ''),
        array('Tabelle ' . $inv['hours_table'], $inv['hours_exists'] ? count($inv['hours']) : '–', $inv['hours_exists'] ? 'Zeilen (wird nur entfernt, wenn unten angehakt)' : 'nicht vorhanden'),
    );

    $html = '<p>Voransicht – es wurde noch nichts geaendert. Gefunden wurde:</p>'
        . '<table class="table table-sm table-bordered" style="max-width: 60rem;"><thead><tr><th>Was</th><th style="text-align:right;">Anzahl</th><th>Details</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td>' . pad_e($row[0]) . '</td><td style="text-align:right;">' . pad_e((string) $row[1]) . '</td><td>' . pad_e($row[2]) . '</td></tr>';
    }
    $html .= '</tbody></table>';

    foreach ($inv['warnings'] as $warning) {
        $html .= '<div class="alert alert-warning">' . pad_e($warning) . '</div>';
    }
    $html .= '<div class="alert alert-secondary">Bleibt erhalten: die Rollen-Kategorie „Arbeitsdienst“ und die Kategorie „Arbeitsdienst“ (WORK_SERVICE…) des Plugins Mitgliedsbeitrag.</div>';

    $nothing = count($inv['user_fields']) + count($inv['categories']) + count($inv['menu']) + count($inv['prefs']) === 0 && !$inv['hours_exists'];
    if ($nothing) {
        $html .= '<div class="alert alert-success">Es ist nichts mehr zu entfernen. Bitte nur noch den Ordner <code>adm_plugins/arbeitsdienst</code> loeschen.</div>';
    } else {
        $html .= '<form method="post" action="' . pad_e($selfUrl) . '">'
            . '<input type="hidden" name="adm_csrf_token" value="' . pad_e($gCurrentSession->getCsrfToken()) . '" />'
            . '<input type="hidden" name="mode" value="run" />';
        if ($inv['hours_exists']) {
            $html .= '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="drop_table" value="1" id="drop_table"'
                . (count($inv['hours']) === 0 ? ' checked' : '') . ' />'
                . '<label class="form-check-label" for="drop_table">Tabelle ' . pad_e($inv['hours_table']) . ' entfernen'
                . (count($inv['hours']) > 0 ? ' – <strong>enthaelt ' . count($inv['hours']) . ' Eintraege</strong> (werden mitgesichert)' : ' (leer)') . '</label></div>';
        }
        $html .= '<p>Vor dem Loeschen werden alle betroffenen Zeilen als JSON in <code>adm_my_files</code> gesichert. '
            . 'Zusaetzlich sollte vorher eine Datenbanksicherung gemacht werden.</p>'
            . '<button type="submit" class="btn btn-danger" onclick="return confirm(\'Altes Plugin Arbeitsdienst jetzt deinstallieren?\');">'
            . '<i class="bi bi-trash"></i> Jetzt deinstallieren</button></form>';
    }

    $page->addHtml($html);
    $page->show();
} catch (Throwable $e) {
    if (isset($gDb)) {
        $gDb->rollback();
    }
    $gMessage->show($e->getMessage());
}

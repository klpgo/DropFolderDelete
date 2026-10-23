<?php
/***************************************************************
* Extension: dropfolderdelete
*
* Page listing the files of the logged-in user's drop folder, with
* a delete button per file.
*
* Usage (relative to your SeedDMS webroot):
*
*   ext/dropfolderdelete/out/out.DropFolderDelete.php
*   ext/dropfolderdelete/out/out.DropFolderDelete.php?folderid=123
*
* folderid is optional and only used for the "Zurück" link.
*
* Requests handled by this one script:
*   GET              list of files (?sort=n|d|s, trailing "d" = descending)
*   GET  ?view=X     open/download file X
*   GET  ?confirm=X  confirmation page for deleting X
*   POST file=X      delete X (needs the form token), then redirect
*                    back to the list (POST/redirect/GET)
*
* X is always a path relative to the user's drop folder. Every
* request resolves it with realpath() and rejects anything outside
* the user's own drop folder.
*
* Bootstrap is identical to folderzip / pdfmerge / SeedDMS's own
* out/*.php scripts, so only an authenticated user gets here.
*
* The page is plain HTML with its own stylesheet and no JavaScript
* (inline scripts would be blocked by SeedDMS's
* Content-Security-Policy anyway), so it does not depend on the
* theme internals of a particular SeedDMS version.
***************************************************************/

if (!isset($settings))
	require_once("../../../inc/inc.Settings.php");
require_once("inc/inc.Utils.php");
require_once("inc/inc.LogInit.php");
require_once("inc/inc.Language.php");
require_once("inc/inc.Init.php");
require_once("inc/inc.Extension.php");
require_once("inc/inc.DBInit.php");
require_once("inc/inc.Authentication.php");
require_once("inc/inc.ClassUI.php");

// Already loaded if the extension is enabled; load it explicitly
// for direct calls.
if (!class_exists('SeedDMS_ExtDropFolderDelete_Files')) {
	require_once(__DIR__ . '/../class.dropfolderdelete.php');
}

/* ---------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------- */
function dfd_h($s) {
	return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function dfd_size($bytes) {
	$units = array('B', 'KB', 'MB', 'GB');
	$i = 0;
	$v = (float) $bytes;
	while ($v >= 1024 && $i < count($units) - 1) {
		$v /= 1024;
		$i++;
	}
	return ($i === 0 ? (string) (int) $v : number_format($v, 1, ',', '.')) . ' ' . $units[$i];
}

function dfd_date($ts) {
	return $ts > 0 ? date('d.m.Y H:i', $ts) : '';
}

/** URL of this script with the given query parameters. */
function dfd_url($params) {
	$params = array_filter($params, function ($v) { return $v !== null && $v !== ''; });
	return 'out.DropFolderDelete.php' . ($params ? '?' . http_build_query($params) : '');
}

/**
 * Per-user, per-login-session token against CSRF. Bound to the
 * SeedDMS session cookie and the installation's encryption key, so
 * it changes on every new login and cannot be guessed by another
 * site. Does not rely on SeedDMS's form-key functions, whose
 * signature differs between versions.
 */
function dfd_token($user, $settings) {
	$sess = isset($_COOKIE['mydms_session']) ? (string) $_COOKIE['mydms_session'] : '';
	$key = isset($settings->_encryptionKey) ? (string) $settings->_encryptionKey : '';
	return hash_hmac('sha256', 'dropfolderdelete|' . $user->getID(), $sess . '|' . $key . '|' . __FILE__);
}

function dfd_log($msg, $error = false) {
	global $logger;
	if (isset($logger) && is_object($logger)) {
		$level = $error ? (defined('PEAR_LOG_ERR') ? PEAR_LOG_ERR : 3) : (defined('PEAR_LOG_INFO') ? PEAR_LOG_INFO : 6);
		$logger->log('dropfolderdelete: ' . $msg, $level);
	}
}

function dfd_page_start($title) {
	header('Content-Type: text/html; charset=utf-8');
	header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'none'");
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: no-store');
	echo "<!DOCTYPE html>\n<html lang=\"de\">\n<head>\n<meta charset=\"utf-8\">\n"
		. "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
		. "<title>" . dfd_h($title) . "</title>\n"
		. "<link rel=\"stylesheet\" href=\"dropfolderdelete.css\">\n"
		. "</head>\n<body>\n<main>\n";
}

function dfd_page_end() {
	echo "</main>\n</body>\n</html>\n";
	exit;
}

/** Error page in the style of this extension, then exit. */
function dfd_error($msg, $backurl, $status = 400) {
	http_response_code($status);
	dfd_page_start('Ablageordner');
	echo '<h1>Ablageordner</h1>';
	echo '<p class="msg error">' . dfd_h($msg) . '</p>';
	echo '<p><a class="btn" href="' . dfd_h($backurl) . '">Zurück</a></p>';
	dfd_page_end();
}

/* ---------------------------------------------------------------
 * Context
 * ------------------------------------------------------------- */
$folderid = isset($_REQUEST['folderid']) && is_numeric($_REQUEST['folderid']) && (int) $_REQUEST['folderid'] > 0
	? (int) $_REQUEST['folderid'] : 0;
if ($folderid === 0 && !empty($settings->_rootFolderID)) {
	$folderid = (int) $settings->_rootFolderID;
}
$seeddmsurl = '../../../out/out.ViewFolder.php' . ($folderid ? '?folderid=' . $folderid : '');

$sort = isset($_REQUEST['sort']) && is_string($_REQUEST['sort']) && preg_match('/^[nds]d?$/', $_REQUEST['sort'])
	? $_REQUEST['sort'] : 'n';

$listurl = dfd_url(array('folderid' => $folderid ?: null, 'sort' => $sort !== 'n' ? $sort : null));

// from=menu: opened via the trash icon in the drop folder menu of the
// navigation bar. Cancel and delete then lead back to the folder the
// user was in, not to the list of this extension.
$frommenu = isset($_REQUEST['from']) && $_REQUEST['from'] === 'menu';
$returnurl = $frommenu ? $seeddmsurl : $listurl;

if (!is_object($user) || $user->isGuest()) {
	dfd_error('Der Gastzugang hat keinen Ablageordner.', $seeddmsurl, 403);
}
if (empty($settings->_dropFolderDir)) {
	dfd_error('Es ist kein Ablageordner konfiguriert (Einstellungen → Server → Ablageordner).', $seeddmsurl, 404);
}
$base = SeedDMS_ExtDropFolderDelete_Files::baseDir($settings, $user);
if ($base === '') {
	dfd_error('Für den Benutzer "' . $user->getLogin() . '" gibt es keinen Ablageordner.', $seeddmsurl, 404);
}
$recursive = SeedDMS_ExtDropFolderDelete_Files::recursive($settings);
$token = dfd_token($user, $settings);

/* ---------------------------------------------------------------
 * POST: delete one file
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$posted = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
	if (!hash_equals($token, $posted)) {
		dfd_error('Ungültiges oder abgelaufenes Formular. Bitte die Seite neu laden und erneut versuchen.', $listurl, 403);
	}
	$rel = isset($_POST['file']) && is_string($_POST['file']) ? $_POST['file'] : '';
	$path = SeedDMS_ExtDropFolderDelete_Files::resolve($base, $rel, $recursive);
	if ($path === false) {
		dfd_error('Die Datei "' . $rel . '" wurde im Ablageordner nicht gefunden.', $listurl, 404);
	}
	if (!@unlink($path)) {
		$err = error_get_last();
		dfd_log('user ' . $user->getLogin() . ' could not delete ' . $path . ': ' . ($err ? $err['message'] : 'unknown error'), true);
		dfd_error('Die Datei "' . $rel . '" konnte nicht gelöscht werden (fehlende Schreibrechte des Webservers auf den Ablageordner?).', $listurl, 500);
	}
	dfd_log('user ' . $user->getLogin() . ' deleted ' . $path);
	header('Location: ' . ($frommenu ? $seeddmsurl : dfd_url(array('folderid' => $folderid ?: null, 'sort' => $sort !== 'n' ? $sort : null, 'deleted' => $rel))), true, 303);
	exit;
}

/* ---------------------------------------------------------------
 * GET ?view=X: open/download a file
 * ------------------------------------------------------------- */
if (isset($_GET['view'])) {
	$rel = is_string($_GET['view']) ? $_GET['view'] : '';
	$path = SeedDMS_ExtDropFolderDelete_Files::resolve($base, $rel, $recursive);
	if ($path === false) {
		dfd_error('Die Datei "' . $rel . '" wurde im Ablageordner nicht gefunden.', $listurl, 404);
	}
	$mime = function_exists('mime_content_type') ? (string) @mime_content_type($path) : '';
	if ($mime === '') {
		$mime = 'application/octet-stream';
	}
	// Show only harmless types inline. Everything else (HTML, SVG,
	// ...) would run in SeedDMS's origin, so it is downloaded.
	$inline = $mime === 'application/pdf' || $mime === 'text/plain'
		|| in_array($mime, array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff'), true);
	$name = basename($path);
	$ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $name);
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
	header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
	header('Content-Length: ' . filesize($path));
	header('X-Content-Type-Options: nosniff');
	// No CSP sandbox here: Chrome refuses to show PDFs in a sandbox.
	// Safe because only the whitelisted types above are inline.
	header('Cache-Control: private, no-store');
	readfile($path);
	exit;
}

/* ---------------------------------------------------------------
 * GET ?confirm=X: confirmation page
 * ------------------------------------------------------------- */
if (isset($_GET['confirm'])) {
	$rel = is_string($_GET['confirm']) ? $_GET['confirm'] : '';
	$path = SeedDMS_ExtDropFolderDelete_Files::resolve($base, $rel, $recursive);
	if ($path === false) {
		dfd_error('Die Datei "' . $rel . '" wurde im Ablageordner nicht gefunden.', $returnurl, 404);
	}
	dfd_page_start('Datei löschen?');
	echo '<h1>Datei löschen?</h1>';
	echo '<div class="confirm">';
	echo '<p>Soll diese Datei endgültig aus dem Ablageordner gelöscht werden?</p>';
	echo '<dl>';
	echo '<dt>Datei</dt><dd class="name">' . dfd_h($rel) . '</dd>';
	echo '<dt>Größe</dt><dd>' . dfd_h(dfd_size(filesize($path))) . '</dd>';
	echo '<dt>Geändert</dt><dd>' . dfd_h(dfd_date(filemtime($path))) . '</dd>';
	echo '</dl>';
	echo '<p class="hint">Das Löschen kann nicht rückgängig gemacht werden. '
		. '<a href="' . dfd_h(dfd_url(array('view' => $rel))) . '" target="_blank" rel="noopener">Datei vorher ansehen</a></p>';
	echo '<form method="post" action="' . dfd_h(dfd_url(array())) . '" class="actions">';
	echo '<input type="hidden" name="token" value="' . dfd_h($token) . '">';
	echo '<input type="hidden" name="file" value="' . dfd_h($rel) . '">';
	echo '<input type="hidden" name="folderid" value="' . dfd_h($folderid) . '">';
	echo '<input type="hidden" name="sort" value="' . dfd_h($sort) . '">';
	if ($frommenu) {
		echo '<input type="hidden" name="from" value="menu">';
	}
	echo '<button type="submit" class="btn danger" autofocus>Ja, löschen</button> ';
	echo '<a class="btn" href="' . dfd_h($returnurl) . '">Abbrechen</a>';
	echo '</form>';
	echo '</div>';
	dfd_page_end();
}

/* ---------------------------------------------------------------
 * GET: list
 * ------------------------------------------------------------- */
$files = SeedDMS_ExtDropFolderDelete_Files::listFiles($base, $recursive);
$field = $sort[0];
$desc = strlen($sort) > 1;
usort($files, function ($a, $b) use ($field) {
	if ($field === 'd' && $a['mtime'] !== $b['mtime']) {
		return $a['mtime'] < $b['mtime'] ? -1 : 1;
	}
	if ($field === 's' && $a['size'] !== $b['size']) {
		return $a['size'] < $b['size'] ? -1 : 1;
	}
	return strnatcasecmp($a['rel'], $b['rel']);
});
if ($desc) {
	$files = array_reverse($files);
}

/** Column header link: click toggles ascending/descending. */
$th = function ($label, $f, $class = '') use ($sort, $folderid) {
	$active = $sort[0] === $f;
	$next = $active && strlen($sort) === 1 ? $f . 'd' : $f;
	$arrow = $active ? (strlen($sort) > 1 ? ' ▼' : ' ▲') : '';
	return '<th' . ($class ? ' class="' . $class . '"' : '') . '><a href="'
		. dfd_h(dfd_url(array('folderid' => $folderid ?: null, 'sort' => $next !== 'n' ? $next : null)))
		. '">' . dfd_h($label) . $arrow . '</a></th>';
};

$total = 0;
foreach ($files as $f) {
	$total += $f['size'];
}

dfd_page_start('Ablageordner');
echo '<p class="back"><a href="' . dfd_h($seeddmsurl) . '">← Zurück zu SeedDMS</a></p>';
echo '<h1>Ablageordner von ' . dfd_h($user->getFullName() !== '' ? $user->getFullName() : $user->getLogin()) . '</h1>';

if (isset($_GET['deleted']) && is_string($_GET['deleted']) && $_GET['deleted'] !== '') {
	echo '<p class="msg ok">„' . dfd_h($_GET['deleted']) . '“ wurde gelöscht.</p>';
}

if (count($files) === 0) {
	echo '<p class="empty">Der Ablageordner ist leer.</p>';
	dfd_page_end();
}

echo '<p class="summary">' . (count($files) === 1 ? '1 Datei' : count($files) . ' Dateien') . ', ' . dfd_h(dfd_size($total))
	. ($recursive ? ' (inkl. Unterordner)' : '') . '</p>';
echo '<table>';
echo '<thead><tr>' . $th('Datei', 'n') . $th('Geändert', 'd', 'date') . $th('Größe', 's', 'num') . '<th class="act"></th></tr></thead>';
echo '<tbody>';
foreach ($files as $f) {
	$view = dfd_url(array('view' => $f['rel']));
	$confirm = dfd_url(array('confirm' => $f['rel'], 'folderid' => $folderid ?: null, 'sort' => $sort !== 'n' ? $sort : null));
	echo '<tr>';
	echo '<td class="name"><a href="' . dfd_h($view) . '" target="_blank" rel="noopener" title="Öffnen">' . dfd_h($f['rel']) . '</a></td>';
	echo '<td class="date">' . dfd_h(dfd_date($f['mtime'])) . '</td>';
	echo '<td class="num">' . dfd_h(dfd_size($f['size'])) . '</td>';
	echo '<td class="act"><a class="btn danger small" href="' . dfd_h($confirm) . '" title="' . dfd_h($f['rel']) . ' löschen">Löschen</a></td>';
	echo '</tr>';
}
echo '</tbody></table>';
dfd_page_end();

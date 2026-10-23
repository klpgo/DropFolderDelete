<?php
/***************************************************************
* Extension: dropfolderdelete
* Adds an "Ablageordner" row to the Informationen table of the
* folder view, linking to a page where the files of the user's
* drop folder can be deleted one by one.
***************************************************************/

/**
 * init() runs on every request once the extension is enabled.
 * Registers a hook for SeedDMS's ViewFolder view.
 */
class SeedDMS_ExtDropFolderDelete extends SeedDMS_ExtBase {
	function init() { /* {{{ */
		$GLOBALS['SEEDDMS_HOOKS']['view']['viewFolder'][] = new SeedDMS_ExtDropFolderDelete_ViewFolderHook();
	} /* }}} */
}

/**
 * Drop folder helpers, shared by the hook below and
 * out/out.DropFolderDelete.php, so both see the same files.
 */
class SeedDMS_ExtDropFolderDelete_Files {

	/**
	 * Settings of this extension from the Extension Manager.
	 */
	static function extconf($settings) { /* {{{ */
		return isset($settings->_extensions['dropfolderdelete']) && is_array($settings->_extensions['dropfolderdelete'])
			? $settings->_extensions['dropfolderdelete'] : array();
	} /* }}} */

	static function recursive($settings) { /* {{{ */
		$c = self::extconf($settings);
		return !empty($c['recursive']);
	} /* }}} */

	/**
	 * Absolute path of the user's drop folder (dropFolderDir/<login>),
	 * resolved with realpath(). Returns '' if no drop folder is
	 * configured or the user's subdirectory does not exist.
	 */
	static function baseDir($settings, $user) { /* {{{ */
		if (!is_object($user) || empty($settings->_dropFolderDir)) {
			return '';
		}
		$login = $user->getLogin();
		if ($login === '' || $login === '.' || $login === '..' || strpbrk($login, "/\\\0") !== false) {
			return '';
		}
		$dir = realpath(rtrim($settings->_dropFolderDir, '/') . '/' . $login);
		return ($dir !== false && is_dir($dir)) ? $dir : '';
	} /* }}} */

	/**
	 * All regular files of the drop folder as
	 * array(array('rel' => 'sub/name.pdf', 'size' => ..., 'mtime' => ...), ...).
	 * Hidden files/directories (leading dot) and symlinks are skipped.
	 */
	static function listFiles($base, $recursive) { /* {{{ */
		$files = array();
		if ($base === '') {
			return $files;
		}
		self::scan($base, '', $recursive, $files, 0);
		return $files;
	} /* }}} */

	private static function scan($base, $rel, $recursive, &$files, $depth) { /* {{{ */
		$dir = $rel === '' ? $base : $base . '/' . $rel;
		$entries = @scandir($dir);
		if ($entries === false || $depth > 20) {
			return;
		}
		foreach ($entries as $name) {
			if ($name === '' || $name[0] === '.') {
				continue;
			}
			$path = $dir . '/' . $name;
			$r = $rel === '' ? $name : $rel . '/' . $name;
			// Symlinks are skipped entirely: they might point outside
			// the drop folder, and resolve() would refuse them anyway
			// if they do.
			if (is_link($path)) {
				continue;
			}
			if (is_dir($path)) {
				if ($recursive) {
					self::scan($base, $r, $recursive, $files, $depth + 1);
				}
			} elseif (is_file($path)) {
				$files[] = array('rel' => $r, 'size' => (int) @filesize($path), 'mtime' => (int) @filemtime($path));
			}
		}
	} /* }}} */

	/**
	 * Resolves a relative path coming from the browser to an absolute
	 * path inside the drop folder. Returns false for anything that is
	 * not a regular, visible file inside it (path traversal, symlinks
	 * pointing outside, hidden files, subfolders if not enabled).
	 */
	static function resolve($base, $rel, $recursive) { /* {{{ */
		if ($base === '' || !is_string($rel) || $rel === '' || strpos($rel, "\0") !== false) {
			return false;
		}
		$rel = str_replace('\\', '/', $rel);
		foreach (explode('/', $rel) as $part) {
			if ($part === '' || $part[0] === '.') {
				return false;
			}
		}
		if (!$recursive && strpos($rel, '/') !== false) {
			return false;
		}
		if (is_link($base . '/' . $rel)) {
			return false;
		}
		$real = realpath($base . '/' . $rel);
		if ($real === false || strpos($real, $base . '/') !== 0 || !is_file($real)) {
			return false;
		}
		if (!$recursive && dirname($real) !== $base) {
			return false;
		}
		return $real;
	} /* }}} */
}

/**
 * Hook object for the ViewFolder view.
 */
class SeedDMS_ExtDropFolderDelete_ViewFolderHook {

	function additionalFolderInfos($view, $folder) { /* {{{ */
		global $settings;

		$user = $view->getParam('user');
		if (!is_object($user) || $user->isGuest()) {
			return array();
		}
		$base = SeedDMS_ExtDropFolderDelete_Files::baseDir($settings, $user);
		if ($base === '') {
			return array();
		}
		$n = count(SeedDMS_ExtDropFolderDelete_Files::listFiles($base, SeedDMS_ExtDropFolderDelete_Files::recursive($settings)));
		$conf = SeedDMS_ExtDropFolderDelete_Files::extconf($settings);
		if ($n === 0 && !empty($conf['hideempty'])) {
			return array();
		}

		// Relative to out/out.ViewFolder.php, like folderzip/pdfmerge.
		// folderid only serves the "Zurück" link.
		$url = '../ext/dropfolderdelete/out/out.DropFolderDelete.php?folderid=' . $folder->getID();
		$text = $n === 0 ? 'leer' : ($n === 1 ? '1 Datei' : $n . ' Dateien');
		$link = '<a href="' . htmlspecialchars($url) . '" title="Dateien im eigenen Ablageordner ansehen und einzeln löschen">'
			. '<i class="fa fa-trash"></i> ' . $text . ($n > 0 ? ' – einzeln löschen' : '') . '</a>';
		return array(
			array('Ablageordner', $link),
		);
	} /* }}} */
}

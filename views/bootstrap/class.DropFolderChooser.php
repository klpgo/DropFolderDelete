<?php
/***************************************************************
* Extension: dropfolderdelete
*
* Replaces SeedDMS's DropFolderChooser view (registered via the
* 'views' key in conf.php, see UI::factory() in inc.ClassUI.php)
* with a subclass that only overrides menuList(): the drop folder
* menu in the navigation bar gets a trash icon behind each file,
* linking to the confirmation page of this extension.
*
* Everything else (the chooser dialog of the upload form, js())
* is inherited unchanged from the core class.
*
* The file must be named class.DropFolderChooser.php: UI::factory()
* only keeps an extension's view if a file of exactly that name
* exists below views/<theme>/ or views/bootstrap/.
***************************************************************/

// This file is included from within UI::factory(), so $settings and
// $theme are that function's local variables. Fall back to the
// globals for any other caller.
$dfd_settings = isset($settings) && is_object($settings) ? $settings : $GLOBALS['settings'];
$dfd_theme = isset($theme) && is_string($theme) && $theme !== '' ? $theme : 'bootstrap';

if (!class_exists('SeedDMS_View_DropFolderChooser', false)) {
	$dfd_core = $dfd_settings->_rootDir . 'views/' . $dfd_theme . '/class.DropFolderChooser.php';
	if (!file_exists($dfd_core)) {
		$dfd_core = $dfd_settings->_rootDir . 'views/bootstrap/class.DropFolderChooser.php';
	}
	require_once($dfd_core);
}
if (!class_exists('SeedDMS_ExtDropFolderDelete_Files', false)) {
	require_once(__DIR__ . '/../../class.dropfolderdelete.php');
}
unset($dfd_settings, $dfd_theme, $dfd_core);

class SeedDMS_View_DropFolderChooser_DropFolderDelete extends SeedDMS_View_DropFolderChooser {

	/**
	 * Files of the drop folder like the core's private getFiles():
	 * array(array(subpath, filename, size, ctime), ...), at most
	 * $maxfiles entries.
	 */
	private function dfdGetFiles($path, $recursive, $maxfiles) { /* {{{ */
		if ($recursive)
			$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
		else
			$rii = new DirectoryIterator($path);

		$files = array();
		foreach ($rii as $file) {
			if (!$file->isDir()) {
				$full = $file->getPath() . DIRECTORY_SEPARATOR . $file->getFilename();
				$files[] = array((string) substr($file->getPath(), strlen($path) + 1), $file->getFilename(), filesize($full), filectime($full));
			}
			if ($maxfiles > 0 && count($files) > ($maxfiles - 1))
				return $files;
		}
		return $files;
	} /* }}} */

	/**
	 * Same output as the core menuList() (SeedDMS 6.0.x), plus a
	 * trash icon behind each file this extension may delete.
	 */
	public function menuList() { /* {{{ */
		$user = $this->params['user'];
		$settings = $this->params['settings'];
		$dropfolderdir = $this->params['dropfolderdir'];
		$recursive = $this->params['recursive'];
		$cachedir = $this->params['cachedir'];
		$conversionmgr = $this->params['conversionmgr'];
		$previewwidth = $this->params['previewWidthMenuList'];
		$maxItemsInDropFolderMenu = $this->params['maxItemsInDropFolderMenu'];
		$previewconverters = $this->params['previewConverters'];
		$timeout = $this->params['timeout'];
		$xsendfile = $this->params['xsendfile'];
		$folder = $this->params['folder'];

		$previewer = new SeedDMS_Preview_Previewer($cachedir, $previewwidth, $timeout, $xsendfile);
		if ($conversionmgr)
			$previewer->setConversionMgr($conversionmgr);
		else
			$previewer->setConverters($previewconverters);

		// What this extension may delete (own drop folder, subfolders
		// only if enabled in its settings). The menu itself always
		// lists recursively (recursive=1 in the navigation bar).
		$dfdBase = SeedDMS_ExtDropFolderDelete_Files::baseDir($settings, $user);
		$dfdRecursive = SeedDMS_ExtDropFolderDelete_Files::recursive($settings);
		$dfdLinks = array();

		$c = 0;
		$menuitems['dropfolder'] = array('label' => '', 'children' => array());
		$dir = rtrim($dropfolderdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $user->getLogin();

		/* Check if we are still looking in the configured directory and
		 * not somewhere else, e.g. if the login was '../test'
		 */
		if (dirname($dir) == $dropfolderdir) {
			if (is_dir($dir)) {
				$files = $this->dfdGetFiles($dir, $recursive, $maxItemsInDropFolderMenu);
				usort($files, function ($a, $b) {
					return $b[3] <=> $a[3];
				});
				$finfo = finfo_open(FILEINFO_MIME_TYPE);
				foreach ($files as $file) {
					$entry = $file[1];
					$path = $file[0];
					$c++;
					$subitem = array('label' => '', 'attributes' => array(array('title', getMLText('menu_upload_from_dropfolder'))));
					// Path relative to the drop folder. The core uses
					// $path.'/'.$entry, which gives "/name" for files
					// directly in the drop folder ($path is '' then).
					$rel = $path === '' ? $entry : $path . '/' . $entry;
					if ($folder)
						$subitem['link'] = $settings->_httpRoot . 'out/out.AddDocument.php?folderid=' . $folder->getId() . "&dropfolderfileform1=" . urlencode($rel);

					// Marker so the trash link can be put behind this
					// entry's <a> after rendering, see below.
					if ($dfdBase !== '' && SeedDMS_ExtDropFolderDelete_Files::resolve($dfdBase, $rel, $dfdRecursive) !== false) {
						$dfdLinks[$c] = $rel;
						$subitem['label'] .= '<span class="dfd-mark" data-dfd="' . $c . '"></span>';
					}

					$mimetype = finfo_file($finfo, $dir . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $entry);
					if ($previewwidth) {
						$previewer->createRawPreview($dir . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $entry, 'dropfolder' . DIRECTORY_SEPARATOR, $mimetype);
						if ($previewer->hasRawPreview($dir . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $entry, 'dropfolder' . DIRECTORY_SEPARATOR)) {
							$subitem['label'] .= "<div class=\"dropfolder-menu-img\" style=\"display: none; overflow:hidden; position: absolute; left:-" . ($previewwidth + 10) . "px; border: 1px solid #888;background: white;\"><img filename=\"" . htmlspecialchars($entry) . "\" width=\"" . $previewwidth . "\" src=\"" . $settings->_httpRoot . "op/op.DropFolderPreview.php?filename=" . urlencode($path . DIRECTORY_SEPARATOR . $entry) . "&width=" . $previewwidth . "\" title=\"" . htmlspecialchars($mimetype) . "\"></div>";
						}
					}
					$subitem['label'] .= "<div class=\"dropfolder-menu-text\" style=\"margin-left:10px; margin-right: 10px; display:inline-block;\">" . htmlspecialchars($rel) . "<br /><span style=\"font-size: 85%;\">" . SeedDMS_Core_File::format_filesize($file[2]) . ", " . getLongReadableDate($file[3]) . "</span></div>";
					$menuitems['dropfolder']['children'][] = $subitem;
				}
			}
		}
		if (!$c)
			return;

		$menuitems['dropfolder']['label'] = getMLText('menu_dropfolder') . " (" . $c . ")";

		ob_start();
		self::showNavigationBar($menuitems, array('id' => 'main-menu-dropfolderlist', 'right' => true));
		$html = ob_get_clean();

		echo $this->dfdAddTrashLinks($html, $dfdLinks, $settings, $folder);
	} /* }}} */

	/**
	 * Puts a trash link behind each marked menu entry. The entry link
	 * and the trash link become siblings in a flex container, as
	 * links must not be nested:
	 *   <a ATTRS><span class="dfd-mark" ...></span>LABEL</a>
	 * becomes
	 *   <div style="display:flex"><a ATTRS style="flex:1">LABEL</a><a TRASH></a></div>
	 * align-items:stretch gives the trash link the full height of the
	 * row, so its hover background is as tall as the entry's.
	 * Only the entry's own <a> is matched, not the surrounding markup,
	 * because that differs between themes (bootstrap: <li><a>,
	 * bootstrap4: <a class="dropdown-item"> without <li>).
	 * An HTML comment "dropfolderdelete: X of Y" in the menu shows how
	 * many marked entries got an icon (view source of
	 * out/out.DropFolderChooser.php?action=menuList).
	 */
	private function dfdAddTrashLinks($html, $links, $settings, $folder) { /* {{{ */
		if (!$links)
			return $html . "\n<!-- dropfolderdelete: no deletable files -->\n";
		$base = $settings->_httpRoot . 'ext/dropfolderdelete/out/out.DropFolderDelete.php';
		$folderid = is_object($folder) ? $folder->getID() : 0;
		$done = 0;

		$out = preg_replace_callback(
			'#<a(\s[^>]*)?>(\s*)<span class="dfd-mark" data-dfd="(\d+)"></span>(.*?)</a>#s',
			function ($m) use ($links, $base, $folderid, &$done) {
				$i = (int) $m[3];
				if (!isset($links[$i]))
					return $m[0];
				$done++;
				$attrs = isset($m[1]) ? $m[1] : '';
				$flex = 'flex:1 1 auto; min-width:0;';
				if (preg_match('/\sstyle="([^"]*)"/', $attrs)) {
					$attrs = preg_replace('/\sstyle="([^"]*)"/', ' style="' . $flex . ' $1"', $attrs, 1);
				} else {
					$attrs .= ' style="' . $flex . '"';
				}
				// Same item class as the entry (e.g. dropdown-item), so
				// padding and hover match the theme.
				$class = 'dfd-delete';
				if (preg_match('/\sclass="([^"]*)"/', $m[1] ?? '', $cm)) {
					$class = trim($cm[1] . ' dfd-delete');
				}
				$url = $base . '?' . http_build_query(array_filter(array(
					'confirm' => $links[$i],
					'folderid' => $folderid ?: null,
					'from' => 'menu',
				)));
				return '<div class="dfd-row" style="display:flex; align-items:stretch;">'
					. '<a' . $attrs . '>' . $m[2] . $m[4] . '</a>'
					. '<a href="' . htmlspecialchars($url) . '" class="' . htmlspecialchars($class) . '" title="Aus dem Ablageordner löschen" style="flex:0 0 auto; width:auto; display:flex; align-items:center; padding-left:12px; padding-right:12px; color:#b94a48;"><i class="fa fa-trash"></i></a>'
					. '</div>';
			},
			$html
		);
		if ($out === null)
			$out = $html;
		return $out . "\n<!-- dropfolderdelete: " . $done . " of " . count($links) . " entries with trash icon -->\n";
	} /* }}} */
}

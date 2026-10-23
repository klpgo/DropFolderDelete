<?php
/***************************************************************
* SeedDMS Extension: dropfolderdelete
*
* Lists the files in the logged-in user's drop folder and lets the
* user delete them one by one (with a confirmation step). Files can
* be opened/downloaded first to check what they are.
*
* Place this whole "dropfolderdelete" directory below the "ext"
* directory of your SeedDMS installation, e.g.:
*
*   <seeddms-webroot>/ext/dropfolderdelete/conf.php
*   <seeddms-webroot>/ext/dropfolderdelete/class.dropfolderdelete.php
*   <seeddms-webroot>/ext/dropfolderdelete/out/out.DropFolderDelete.php
*   <seeddms-webroot>/ext/dropfolderdelete/views/bootstrap/class.DropFolderChooser.php
*
* Then enable it in Admin Tools -> Extension Manager.
*
* Requires a drop folder to be configured (Settings -> Server ->
* "Drop folder directory"). Each user's drop folder is the
* subdirectory named after the user's login.
***************************************************************/

$EXT_CONF['dropfolderdelete'] = array(
	'title' => 'Delete files from drop folder',
	'description' => 'Lists the files in your drop folder and lets you delete them individually.',
	'disable' => false,
	'version' => '1.1.2',
	'releasedate' => '2026-10-23',
	'author' => array(
		'name' => 'Klaus Gottschalk',
		'email' => 'klaus.gottschalk@kgem.de',
		'company' => '',
	),
	'config' => array(
		'recursive' => array(
			'title' => 'Dateien in Unterordnern des Ablageordners einbeziehen',
			'type' => 'checkbox',
		),
		'hideempty' => array(
			'title' => 'Zeile "Ablageordner" ausblenden, wenn der Ablageordner leer ist',
			'type' => 'checkbox',
		),
	),
	'constraints' => array(
		'depends' => array('php' => '7.2.0-', 'seeddms' => '5.1.0-'),
	),
	'icon' => 'icon.png',
	'changelog' => 'changelog.md',
	'changes' => array(
		'1.1.2' => array(
			'Drop folder menu shows files directly in the drop folder without leading slash ("xx.pdf" instead of "/xx.pdf"), also in the upload link',
			'Hover background of the trash icon has the full height of the menu entry',
		),
		'1.1.1' => array(
			'Trash icon now also shown with the bootstrap4 theme (menu entries are a.dropdown-item without li there)',
			'HTML comment in the drop folder menu shows how many entries got a trash icon (diagnostics)',
		),
		'1.1.0' => array(
			'Trash icon behind each file in the drop folder menu of the navigation bar, leads directly to the confirmation page',
			'After deleting from the menu, cancel and delete return to the folder view',
		),
		'1.0.0' => array(
			'Initial version',
			'List the files of the own drop folder with size and date, sortable by name, date and size',
			'Delete single files after a confirmation step',
			'Open/download a file before deleting it',
			'Link with file count in the Informationen box of the folder view',
		),
	),
	// Replaces the core view DropFolderChooser by a subclass that adds
	// the trash icons to the drop folder menu (only menuList() is
	// overridden). See views/bootstrap/class.DropFolderChooser.php.
	'views' => array(
		'DropFolderChooser' => array(
			'file' => 'class.DropFolderChooser.php',
			'name' => 'SeedDMS_View_DropFolderChooser_DropFolderDelete',
		),
	),
	'class' => array(
		'file' => 'class.dropfolderdelete.php',
		'name' => 'SeedDMS_ExtDropFolderDelete',
	),
);

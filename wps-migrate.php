<?php
/**
 * Plugin Name:       WPS Migrate
 * Plugin URI:        https://github.com/zouloux/wps
 * GitHub Plugin URI: https://github.com/zouloux/wps
 * Description:       Simplest Data migration
 * Author:            Alexis Bouhet
 * Author URI:        https://zouloux.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       WPS
 * Domain Path:       /cms
 * Version:           1.0.0
 * Copyright:         © 2025 Alexis Bouhet
 */

if ( !defined("ABSPATH") ) exit;
if ( !is_blog_installed() ) return;
if ( defined('WPS_MIGRATE_DISABLE') && WPS_MIGRATE_DISABLE ) return;

// ----------------------------------------------------------------------------- DEFAULTS

function wps_migrate_get_paths () {
	// Target uploads from env or automatically
	$uploadsPath = defined("WPS_MIGRATE_UPLOADS_PATH") ? WPS_MIGRATE_UPLOADS_PATH : "";
	if ( empty($uploadsPath) ) {
		$uploadsPath = wp_upload_dir()['basedir'];
	}
	// Target data direction from envs or from sqlite database directory
	$dataPath = defined("WPS_MIGRATE_DATA_PATH") ? WPS_MIGRATE_DATA_PATH : "";
	if ( empty($dataPath) ) {
		$dataPath = defined("WP_DB_DIR") && is_dir(WP_DB_DIR) ? WP_DB_DIR : "";
	}
	return array_filter( [$uploadsPath, $dataPath], fn ($path) => !empty($path) );
}

// ----------------------------------------------------------------------------- MENU PAGE

add_action('admin_menu', function () {
	add_options_page(
		'Data migration',
		'Data migration',
		'manage_options',
		'wps_migrate_page',
		'wps_migrate_admin_page'
	);
});


function wps_migrate_admin_page () {
	$paths = wps_migrate_get_paths();
	?>
	<div class="wrap">
		<h1>Data migration</h1>
		<br />
		<hr />
		<h2 class="title">Download data</h2>
		<form method="post">
			<a href="<?php echo admin_url('admin-post.php?action=wps_migrate_download_data_file'); ?>" class="button button-primary">
				Download archive
				<span class="dashicons dashicons-download"  style="margin-top: 3px"></span>
			</a>
		</form>
		<br />
		<h2 class="title">Upload data</h2>
		<form
			method="post" enctype="multipart/form-data"
			onSubmit="return confirm('Warning, this will override server files and this cannot be undone.')"
		>
			<input type="file" name="wps-migrate-archive" required />
			<br /><br />
			<button type="submit" name="wps-migrate-post" value="" class="button button-primary">
				<span>Upload archive</span>
				<span class="dashicons dashicons-upload" style="margin-top: 3px"></span>
			</button>
		</form>
		<br />
		<hr />
		<h2 class="title">Warning</h2>
		<p>It can completely <strong>break this website if not used correctly, and can cause data loss.</strong> Use with caution.</p>
		<br />
		<h2 class="title">Paths</h2>
		<?php foreach ($paths as $path) : ?>
			<div><?php echo $path ?></div>
		<?php endforeach ?>
	</div>
	<?php
}

// ----------------------------------------------------------------------------- DOWNLOAD

add_action('init', function () {
	if ( !is_admin() ) return;
	$checkMigration = function () {
		if ( !current_user_can('manage_options') )
			wp_die('You are not allowed to manage migrations.');
	};
	if ( isset($_GET["action"]) && $_GET["action"] === "wps_migrate_download_data_file" ) {
		$checkMigration();
		wps_migrate_download_handler();
	} else if ( isset($_POST["wps-migrate-post"]) && !empty($_FILES["wps-migrate-archive"]["tmp_name"]) ) {
		$checkMigration();
		wps_migrate_upload_data();
	}
});

function wps_migrate_split_path ( string $path ) {
	$splitPath = explode('/', rtrim($path, '/'));
	$lastElement = array_pop($splitPath);
	return [ implode('/', $splitPath), $lastElement ];
}

function wps_migrate_download_handler () {
	// Create a temp directory for migration files to download
	$tmpDir = "/tmp/".uniqid();
	mkdir($tmpDir, 0777, true);
	// Generate command which create the tar gz file from uploads and data directories
	$tarFileName = $tmpDir."/migration-data.tar.gz";
	$rawCommand = "tar -czf $tarFileName";
	$paths = wps_migrate_get_paths();
	foreach ($paths as $path)
		$rawCommand .= " -C ".implode(" ", wps_migrate_split_path($path));
	$command = escapeshellcmd($rawCommand);
	shell_exec($command);
	if ( !file_exists($tarFileName) )
		wp_die('Unable to generate tar file.');
	// Configure headers for downloading with date in filename
	$date = date('Y-m-d-H-i');
	header('Content-Description: File Transfer');
	header('Content-Type: application/x-gzip');
	header('Content-Disposition: attachment; filename="migration-data-' . $date . '.tar.gz"');
	header('Content-Transfer-Encoding: binary');
	header('Content-Length: ' . filesize($tarFileName));
	try {
		@ob_clean();
	} catch ( Exception $e ) {}
	flush();
	readfile($tarFileName);
	unlink($tarFileName);
	// Remove file in tmp directory
	shell_exec("rm -rf $tmpDir");
	exit;
}

//function safe_shell ( string $command ) {
//	echo "<pre>";
//	echo $command;
//	echo "</pre>";
//}

// Error function that remove the tmp files
function wps_migrate__die_and_clean ( string $tmpDir, string $message = null, $type = "error" ) {
	shell_exec("rm -rf $tmpDir");
	if ( !empty($message) ) {
		echo "<div class=\"error\"><p>$message</p></div>";
		exit;
	}
}

function wps_migrate_upload_data () {
	// Init temp directories
	$tmpDir = "/tmp/".uniqid();
	$uploadedFile = $tmpDir."/".basename($_FILES["wps-migrate-archive"]["name"]);
	// Extract will get the archive first level directories
	$extractedTmpDir = $tmpDir."/extracted";
	mkdir($extractedTmpDir, 0777, true);
	// Backup will be the "trash" of current data that will be replaced by the archive
	$backupTmpDir = $tmpDir."/backup";
	mkdir($backupTmpDir, 0777, true);
	// Move uploaded file to a tmp directory
	if ( !move_uploaded_file($_FILES["wps-migrate-archive"]["tmp_name"], $uploadedFile) ) {
		wps_migrate__die_and_clean($tmpDir, "Unable to upload file.");
	}
	// Run command to extract uploaded file in temp directory
	$command = escapeshellcmd("tar -xzf $uploadedFile -C $extractedTmpDir");
	try {
		shell_exec($command);
	}
	catch ( Exception $e ) {
		wps_migrate__die_and_clean($tmpDir, "Unable to extract archive.");
	}
	// Check archive structure validity
	$pathsToCheck = wps_migrate_get_paths();
	foreach ( $pathsToCheck as $pathToCheck ) {
		if ( empty($pathToCheck) ) continue;
		[, $dirName] = wps_migrate_split_path($pathToCheck);
		$path = "$extractedTmpDir/$dirName";
		if ( !file_exists($path) || !is_dir($path) ) {
			wps_migrate__die_and_clean($tmpDir, "Invalid archive format, missing $dirName directory.");
		}
	}
	foreach ( $pathsToCheck as $pathToCheck ) {
		[$dirPath, $dirName] = wps_migrate_split_path($pathToCheck);
		if (
			$dirPath === "/" || $dirName === "/"
			|| empty($dirPath) || empty($dirName)
			|| !str_starts_with($dirPath, "/") || str_starts_with($dirName, "/")
		) {
			wps_migrate__die_and_clean($tmpDir, "Invalid path configuration");
		}
		// Empty current directory
		// fixme : move to backup directory
		mkdir("$backupTmpDir/$dirName", 0777, true);
		shell_exec("rm -rf $dirPath/$dirName/*");
//		shell_exec("mv $dirPath/$dirName/* $backupTmpDir/$dirName/");
		// Copy from archive
		shell_exec("cp -R $extractedTmpDir/$dirName/* $dirPath/$dirName/");
	}
	wps_migrate__die_and_clean($tmpDir, "Data uploaded successfully.", "updated");
}

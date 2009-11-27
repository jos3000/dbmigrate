<?php

require_once('../inc.load_settings.php');

require_once('DBMigrate/DBMigrate.php');

$dbmigrate = new DBMigrate(
	DATABASE_HOST,
	DATABASE_USERNAME,
	DATABASE_PASSWORD,
	DATABASE_DATABASE,
	DATABASE_TEMP_DATABASE,
	DATABASE_BACKUP_FOLDER,
	MYSQLPATH,
	MYSQLDUMPPATH,
	'migrate/'
);

$dbmigrate->setLockFilePath(LOCK_DIR.'/database.lock');

$dbmigrate->interactive();

$dbmigrate->run();

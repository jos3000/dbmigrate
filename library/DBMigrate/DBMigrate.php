<?php

class DBMigrate {
	
	const MODE_USE_CURRENT_DATABASE = 1;
	const MODE_LOAD_BACKUP = 2;
	const MODE_START_FROM_SCRATCH = 3;
	
	const DO_BACKUP_YES = 1;
	const DO_BACKUP_NO = 2;
	
	# manditory settings

	private $host;		
	private $username;
	private $password;
	private $database;
	private $temp_database;
	
	private $path_backup_folder;

	private $path_mysql;
	private $path_mysql_dump;
	
	private $path_migrate_files;
	
	# optional settings
	
	private $path_lock_file = false;
	private $min_backup_size = 500;
	
	# user settings
	
	private $start_mode;
	private $do_backup;
	
	private $path_backup_to_load;
	private $path_backup_to_save;
	
	public function __construct($host,$username,$password,$database,$temp_database,$path_backup_folder,$path_mysql,$path_mysql_dump,$path_migrate_files){
		
		$this->host = $host;
		$this->username = $username;
		$this->password = $password;
		$this->database = $database;
		
		$this->temp_database = $temp_database;
		
		$this->path_backup_folder = $path_backup_folder;
		$this->path_mysql = $path_mysql;
		$this->path_mysql_dump = $path_mysql_dump;
		
		$this->path_migrate_files = $path_migrate_files;
		
	}
	
	public function setLockFilePath($path_lock_file){
		$this->path_lock_file = $path_lock_file;
	}
	
	# ask the user to choose from a fixed set of options
	
	private function ask_fixed($correct_array){
		do {
			$ans = strtolower(trim(fread(STDIN, 80))); // Read up to 3 characters or a newline
			if(in_array($ans,$correct_array)){
				$correct = false;
			}else{
				echo "This answer $ans is not one of the options. Please choose from (".join(',',$correct_array).')';
			}
		} while($correct);
		
		return $ans;
	}
	
	private function getBackupList($dir){
		$results = array();
		// create a handler for the directory
		$handler = opendir($dir);

		// keep going until all files in directory have been read
		$c = 1;
		while ($file = readdir($handler)) {
			// if $file isn't this directory or its parent,
			// add it to the results array
			if (substr($file,0,1) != '.' && is_file($dir.$file)){
				$results[] = array(
					'filename' => $file,
					'mod_time' => date("F d Y H:i:s", filemtime($dir.$file))
				);
			}
		}
		// tidy up: close the handler
		closedir($handler);
		// done!
		return $results;
		
	}
	
	public function interactive(){

		# Choose the start mode if we don't have it
		
		if(empty($this->start_mode)){
			echo "\nHow to you want to start? (a,b,c):\n";
			echo "a) Use current database state.\n";
			echo "b) Restore a database backup.\n";
			echo "c) Start the database from scratch.\n";
		
			$ans = $this->ask_fixed(array('a','b','c'));
			if('a' == $ans) $this->start_mode = self::MODE_USE_CURRENT_DATABASE;
			elseif('b' == $ans) $this->start_mode = self::MODE_LOAD_BACKUP;
			elseif('c' == $ans) $this->start_mode = self::MODE_START_FROM_SCRATCH;
		}
		
		if(self::MODE_LOAD_BACKUP == $this->start_mode && empty($this->path_backup_to_load)){
			echo "\nSelect database backup to install\n";
			echo "Type the backup number then press enter:\n";
			$backup_list = $this->getBackupList($this->path_backup_folder);
			foreach($backup_list AS $k => $v){
				echo $k.") ".$v['filename']."  ".$v['mod_time']."\n";
			}
			
			$ans = $this->ask_fixed(array_keys($backup_list));
			
			$this->path_backup_to_load = $this->path_backup_folder.$backup_list[$ans]['filename'];
		}
		
		while(empty($this->do_backup)){
			echo "\nDo you want to save a snapshot of your local database? (yes/no):";

			$ans = $this->ask_fixed(array('yes','no'));
			if('no' == $ans) {
				echo "Are you sure you don't want to make a backup? (yes/no):";
				$sure_ans = $this->ask_fixed(array('yes','no'));
				
				if('yes' == $sure_ans) {
					$this->do_backup = self::DO_BACKUP_NO;
				}
			} elseif('yes' == $ans){
				$this->do_backup = self::DO_BACKUP_YES;
			}
			
			# if do_backup is not set, we will loop here
		}
		
	}
	
	protected function run_output($str){
		echo $str;
	}
	
	protected function setLock($is_on){
		if($is_on){
			if(!empty($this->path_lock_file)){
				exec('touch '.$this->path_lock_file);
			}
		} else {
			if(!empty($this->path_lock_file) && file_exists($this->path_lock_file)){
				unlink($this->path_lock_file);
			}
		}
	}
	
	public function run(){
		
		$this->run_output("Starting migration\n");
		
		if($this->do_backup == self::DO_BACKUP_NO){
			$this->run_output("Skipping backup stage\n");
		} elseif($this->do_backup == self::DO_BACKUP_YES) {
			$this->run_output("Backing up existing database\n");
			
			$this->run_output("Locking site access\n");
			$this->setLock(true);
			
			# set default backup path
			if(empty($this->path_backup_to_save)) $this->path_backup_to_save = $this->path_backup_folder.'local_'.$this->database.date("_Y-m-d_").date("H")."h".date("i")."m.sql.gz";
			
			$this->run_output("Backing up to ".$this->path_backup_to_save."\n");
			
			exec($this->path_mysql_dump." \
			--user=".$this->username." \
			--password='".$this->password."' \
			--single-transaction \
			--flush-logs \
			--add-drop-table \
			--add-locks \
			--create-options \
			--disable-keys \
			--extended-insert \
			--quick \
			--set-charset ".$this->database." | gzip > ".$this->path_backup_to_save);

			if($this->checkFilesize($this->path_backup_to_save)){
				$this->run_output("Backup failed! ".$this->path_backup_to_save." filesize is less than 1M+\n");
				$this->run_output("Unlocking site access\n");
				$this->setLock(false);
				$this->run_output("Quiting\n");
				exit();
			} else {
				$this->run_output("Database backed up.\n");
			}
			
		} else {
			throw new Exception('backup state must be decided');
		}
		
		
		if(self::MODE_USE_CURRENT_DATABASE == $this->start_mode){
			$this->run_output("Working with existing database\n");
		} elseif(self::MODE_LOAD_BACKUP == $this->start_mode) {
			$this->run_output("Clearing database\n");
			$this->clearExistingDB();
			
			$this->run_output("Loading backup\n");
			
			$result = false;
			$output = array();
			$this->run_output("Installing from ".$this->path_backup_to_load);
			exec("gunzip -c ".$this->path_backup_to_load." | ".$this->path_mysql." --user=".$this->username." --password=".$this->password." ".$this->database, $output, $result);

			if($result === 0){
				echo "New database installed:\n";
			}else{
				throw new Exeception("Unable to install new database");
			}
			
			
		} elseif(self::MODE_START_FROM_SCRATCH == $this->start_mode) {
			$this->run_output("Clearing database\n");
			$this->clearExistingDB();

			$db = new PDO($this->getDSN($this->database), $this->username, $this->password);
			
			$db->exec(
				'CREATE TABLE dbmigrate (
					`id` varchar(5) NOT NULL,
					`filename` varchar(50) NOT NULL,
					`date` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
					PRIMARY KEY  (`id`)
				)'
			);
		} else {
			throw new Exception('start mode is invalid');
		}
		
		if(empty($db)) $db = new PDO($this->getDSN($this->database), $this->username, $this->password);
		
		# run db migrate files
		
		$file_list = glob($this->path_migrate_files."*.sql");
		$files_to_execute = array();
		
		foreach($file_list As $f){
			//Get ID from file name
			$id = (int)substr(substr($f, strpos($f,"/")+1), 0, strpos(substr($f, strpos($f,"/")+1),"_"));
			if($id == "xx"){
				echo $f." ignored\n";
			} else {
				$id = (int)$id;
				
				//Check if in DB already

				$query = $db->query("SELECT id FROM dbmigrate WHERE id = $id");
				if($query->fetch()){
					//Give notice
					$this->run_output($f." was executed before\n");
				} else {
					//Add to file list
					$files_to_execute[] = $f;
					$this->run_output($f."\n");
				}
				unset($query);
			}
		}
		
		/////////////////////////////////////////////////////////////////////
		//Backup database, then parse and execute files

		if(count($files_to_execute)){

			$this->run_output("\nExecuting files:\n");

			foreach($files_to_execute As $f){
				$this->run_output($f.":\n");

				list($id) = split('_',basename($f));
				
				$statements = $this->parseSQLFile($f);

				foreach($statements AS $statement){
					#$statement = str_replace(array_keys($replace),array_values($replace),$statement);

					echo $statement."\n";
					if($db->exec($statement) === false){
						//Show error info
						$err = $db->errorInfo();
						echo $err[2]."\n";
						return false;
					}
				}
				
				$filename = $db->quote($f);
				$ins="INSERT INTO dbmigrate (id, filename, date) VALUES ($id, $filename, Now())";
				$count = $db->exec($ins);
				
				if($count){
					echo $f." executed successfully\n\n";
				} else {
					echo "Error: ".$f." could not be executed!\n";
					$err = $db->errorInfo();
					echo $err[2]."\n";
					exit;
				}
			}
		} else {
			echo "Nothing to execute!\n";
			echo "The database is up to date\n";
		}

		//Done
		$this->run_output("\nMigration procedure complete!\n");

		$this->run_output("Unlocking site access\n");
		$this->setLock(false);
		
	}
	
	private function clearExistingDB(){
		$db_temp = new PDO($this->getDSN($this->temp_database), $this->username, $this->password);
		
		if($db_temp->exec('DROP DATABASE IF EXISTS '.$this->database) === false){
			throw new Exeception("unable to drop database");
		}
		
		if($db_temp->exec('CREATE DATABASE '.$this->database) === false){
			throw new Exeception("unable to drop database");
		}
		
	}
	
	private function getDSN($database){
		return "mysql:host=".$this->host.";dbname=".$database;
	}
	
	private function checkFilesize($file){
		if(filesize($file) <= $this->min_backup_size){
			return true;
		} else {
			return false;
		}
	}
	
	private function parseSQLFile($sql_file,$replace = array()){

		$filename = substr($sql_file, strpos($sql_file,"/")+1); //Get filename from full path
		$id = substr($filename, 0, strpos($filename,"_")); //Get ID from file name
		$file = @file($sql_file); //Read file
		$statement = ""; //Initiate first statement

		//Check if file is not blank
		if($file){
			//Go through each line

			$statements = array();

			foreach($file As $k=>$v){
				//Get line and trim
				$line = trim($v);

				//Check if line is not blank
				if($line != ""){

					//Check if it doesn't start with a hash
					if($line{0} != "#"){
						//If there is an inline hash, then remove the rest of the line after it
						if(strpos($line, "#") !== false){
							$line = substr($line, 0, strpos($line, "#"));
						}

						//Check if there is a ";" without a preceding "\"
						if((strpos($line, ";") !== false) && ($line{strpos($line, ";") - 1} != "\\")){
							//Check if the line terminates in a ";"
							if(substr(trim($line), -1) == ";"){
								//Execute statement and then reset it
								$statement .= " ".$line; //Append line to statement
								$statements[] = $statement;
								$statement = ""; //Reset statement
							} else {
								//Only execute up to ";"
								//Reset and start new statement after
								$statement .= " ".substrt($line, 0, strpos($line, ";")+1); //Append partial line to statement
								$statements[] = $statement;
								$statement = ""; //Reset statement
								$statement .= " ".substrt($line, strpos($line, ";")+1);
							}
						} else {
							$statement .= " ".$line; //Append line to statement

							//Used if there is a ";" after "\;" inline
							if(substr(trim($line), -1) == ";"){
								$statements[] = $statement;
								$statement = ""; //Reset statement
							}
						}
					}
				}
			}
			
			return $statements;
			
		} else {
			throw new Exception('File '.$f.' is empty');
		}

	}
	
	
}

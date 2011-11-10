<?php
set_time_limit(0);
date_default_timezone_set(date_default_timezone_get());
/*
  Usage :
    1. Do testing.
       php amazonS3RR.php test accessKey privateKey s3Bucket
    2. Do S3 action.
       php amazonS3RR.php ScheduleName
*/
// settings
define('CONFIG_FILE','/etc/config/amazonS3_schedule.conf');
define('DO_TEST_PASS','Pass');
define('DO_TEST_AUTH_FAIL','Auth Fail');
define('DO_TEST_BUCKET_FAIL','Bucket Fail');
define('TIMEOUT_AUTH',30);
define('TIMEOUT_TRANSFER',60);
define('DEBUG_MODE',false);
define('STATUS_READY',0);
define('STATUS_AUTH_FAIL',1);
define('STATUS_COPY_FAIL',2);
define('STATUS_NOW',3);
define('STATUS_SUCCESS',4);
define('STATUS_NAS_DIR_FAIL',5);
define('STATUS_S3_DIR_FAIL',6);

//define('STATUS_READY_MESG','');
define('STATUS_AUTH_FAIL_MESG','[Amazon S3] Job %s failed. (Authentication failure)');
define('STATUS_COPY_FAIL_MESG','[Amazon S3] Job %s failed. (%s errors)');
define('STATUS_COPY_TIMEOUT_MESG','[Amazon S3] Job %s failed. (Connection timeout)');
//define('STATUS_NOW_MESG','');
define('STATUS_SUCCESS_MESG','[Amazon S3] Job %s finished successfully.');
define('STATUS_NAS_DIR_FAIL_MESG','[Amazon S3] Job %s failed. (Local path is incorrect.)');
define('STATUS_S3_DIR_FAIL_MESG','[Amazon S3] Job %s failed. (S3 bucket is incorrect.)');
define('STATUS_S3_JOB_STARTED','[Amazon S3] Job %s started.');
define('STATUS_S3_JOB_PROGRESS','[Amazon S3] Job %s: Total of %s files in queue.');
define('STATUS_S3_TASK_UPLOAD_FAILED','[Amazon S3] Job %s: Failed to upload file [%s], Retried %s times');
define('STATUS_S3_TASK_DOWNLOAD_FAILED','[Amazon S3] Job %s: Failed to download file [%s], Retried %s times');
define('STATUS_S3_TASK_DELETE_FAILED','[Amazon S3] Job %s: Failed to remove file [%s].');
define('STATUS_S3_TASK_DELETE_SUCCESS','[Amazon S3] Job %s: %s files were removed from the server.');

// set php settings
if(DEBUG_MODE){
	ini_set('display_errors',1);
	error_reporting(E_ALL);
}else{
	ini_set('display_errors',0);
	error_reporting(E_ALL ^ E_NOTICE);
}

set_exception_handler('exception_handler');
set_error_handler("myErrorHandler");

$AmazonS3RR = '';
$copyFail = array();

// for response timeout
$responseTimeout = false;

// if $argv[2] is not empty , do testing.
if (!empty($argv[2]))
{
  // $argv    0      1       2         3         4
  // amazonS3RR.php test accessKey privateKey s3Bucket
  // $argv[1] must = test
  if ($argv[1] == 'test')
  {
    $r = DO_TEST_PASS;
    $timeout = TIMEOUT_AUTH;
    $S3 = new S3($argv[2],$argv[3]);
    if (!$S3->listBuckets())
    {
      // set status to auth fail
      $r = DO_TEST_AUTH_FAIL;
    } else if ($S3->getBucket($argv[4]) === false){
      // set status to bucket fail
      $r = DO_TEST_BUCKET_FAIL;
    }
    echo $r;
  }
  exit;
}
// end of testing

// start run schedule
$AmazonS3RR = new AmazonS3RR(CONFIG_FILE,$argv[1]);
$AmazonS3RR->runSchedule();

// main run s3 action class
class AmazonS3RR{
  public $configFile = '';
  public $scheduleName = '';
  public $scheduleSetting = Array();
  public $fileList = Array();
  public $totalSize = 0;

  public $jobName = '';
  private $__accessKey = '';
  private $__privateKey = '';
  public $bucketName = '';
  public $NasFileRoot = '/share';
  public $NasShareName = '';
  public $NasFolderName = '';

  public $S3Bucket = '';
  public $S3Object = '';
  public $usageType = '';

  public $NasSharePath = '';
  public $NasFolderPath = '';
  public $NasFolderPathLen = 0;

  public $doIncrementalBackup = 0;
  public $doDeleteExtrafiles = 0;

  public $maximumRetries = 0;

  public $S3 = null;

  function __construct($configFile,$scheduleName)
  {
    $this->configFile = $configFile;
    $this->scheduleName = $scheduleName;
  }

  // initial variables and S3
  function init()
  {
    // check schedule name
    if (empty($this->scheduleName))
    {
      $this->debugMesg('There is no parameter.');
      exit;
    }

    // check config file
    if (!file_exists($this->configFile))
    {
      $this->debugMesg('Config File '.$this->configFile.' is not exist.');
      exit;
    }

    // get Schedule settings
    $this->getScheduleByName($this->scheduleName);

    // new S3 class
    $this->S3 = new S3($this->__accessKey,$this->__privateKey);

    //set curl timeout
    global $timeout;
	$timeout = TIMEOUT_AUTH;

    // S3 Authetication
    if (!$this->S3->listBuckets())
    {
      // set status to auth fail
      $this->debugMesg('S3 Auth Fail');
      $this->setConf($this->scheduleName,'Status',STATUS_AUTH_FAIL);
      $this->writeSysLog(STATUS_AUTH_FAIL_MESG,1);
      exit;
    }

		//set curl timeout
		$timeout = TIMEOUT_TRANSFER;

    // check NAS and S3 forder
    if (!file_exists($this->NasSharePath) || !file_exists($this->NasFolderPath))
    {
      // set status to NAS dir fail
      $this->debugMesg('NAS Folder Fail');
      $this->setConf($this->scheduleName,'Status',STATUS_NAS_DIR_FAIL);
      $this->writeSysLog(STATUS_NAS_DIR_FAIL_MESG,1);
      exit;
    }
  }

  // run schedule
  function runSchedule()
  {
 	global $responseTimeout, $copyFail;
	
    $this->init();

    // $this->writePid();

    if ($this->usageType == 'upload')
	{
		$this->debugMesg("Okay, we're uploading...");
    	// set status to running
		$this->setConf($this->scheduleName,'Status',STATUS_NOW);

        $this->writeSysLog(STATUS_S3_JOB_STARTED, 4);

		// put data from nas to s3

		$this->debugMesg("Getting local file list... ");
		$this->fileList = $this->filesInDir($this->NasFolderPath);


		//getting remote file list
		$filterList = array();
		$filterPrefix = '';
		$deleteList = array();
		$updateList = array();

		$this->debugMesg("Getting remote file list for incremental backup... ");
		if ($this->S3Object != ''){
			$filterPrefix = $this->S3Object.'/';
		}
		$this->getAllfileList($filterPrefix, $filterList);
		
		$this->debugMesg("Filtering unnecessary files...[Total: ".count($this->fileList)."] ");
		$this->doFilter($this->fileList, $this->NasFolderPath, $filterList, $filterPrefix, $updateList, $deleteList); //removes unchanged files
		if($this->doIncrementalBackup == 1){
			$this->fileList = $updateList;
		}

		$this->debugMesg("Finishing processing the file lists ");


      // copy fileList to remote
      //$s3Root = $this->S3Bucket;

      //$lastFolder = $thisFolder = '';

	//remove
	$fileNum = count($deleteList);
	if($this->doDeleteExtraFiles ==1 && $fileNum > 0){
		$successCount = 0;
		$this->debugMesg('== Start Delete Job ('.$this->jobName.') - '.$fileNum.' file(s) to delete ==');
		for($i=0; $i<$fileNum; $i++){
			for($count = 0; $count<=$this->maximumRetries; $count++){
				if ( $this->S3->deleteObject($this->S3Bucket, $deleteList[$i]['key']) )
				{
					$this->debugMesg('['.($i+1).'/'.$fileNum.'] Success...'.$deleteList[$i]['key'].' is removed from the remote server');
					$successCount++;
					break;
				} else {
					if($count==($this->maximumRetries-1)){
						$copyFail[] = 'Fail to removed file from remote server. ['.$deleteList[$i]['key'].']';
					}
					$this->debugMesg('['.($i+1).'/'.$fileNum.'] Fail...'.$deleteList[$i]['key'].' is not removed from the remote server');
					$this->writeSysLog(sprintf(STATUS_S3_TASK_DELETE_FAILED, $this->jobName, $this->fileList[$i]['key']), 1);
				}
			}
		}
		$this->debugMesg('== Finish Delete Job ('.$this->jobName.') ==');
		$this->writeSysLog(sprintf(STATUS_S3_TASK_DELETE_SUCCESS, $this->jobName, $successCount), 4);
	}

      // copy
	  $fileNum = count($this->fileList);
      $this->writeSysLog(sprintf(STATUS_S3_JOB_PROGRESS, $this->jobName, $fileNum), 4);
	  $this->debugMesg('== Start '.$this->usageType.' Job ('.$this->jobName.') - '.$fileNum.' file(s) to upload ==');

      for($i=0;$i<$fileNum;$i++)
      {
		if($this->fileList[$i]['size'] == 0) continue;
	  
        $destPath = substr($this->fileList[$i]['key'],$this->NasFolderPathLen);

        if ($this->S3Object != '')
        	$destObject = $this->S3Object.'/'.$destPath;
        else
        	$destObject = $destPath;

		for($count = 0; $count<=$this->maximumRetries; $count++){
			if ( $this->S3->putObjectFile($this->fileList[$i]['key'], $this->S3Bucket, $destObject, S3::ACL_PRIVATE) )
			{
			  $this->debugMesg('['.($i+1).'/'.$fileNum.'] Success...'.$this->fileList[$i]['key'].' to '.$this->S3Bucket.'/'.$destObject.'');
			  break;
			} else {
			  if($count==($this->maximumRetries)){
                $this->writeSysLog(sprintf(STATUS_S3_TASK_UPLOAD_FAILED, $this->jobName, $this->fileList[$i]['key'], $count), 1);
				$copyFail[] = 'Fail to upload file to remote server. ['.$this->fileList[$i]['key'].']';
			  }

			  $this->debugMesg('['.($i+1).'/'.$fileNum.'] Fail...'.$this->fileList[$i]['key'].' to '.$this->S3Bucket.'/'.$destObject." ");
			}
		}
      }

		// set status     
		if (count($copyFail) > 0){
			$this->setConf($this->scheduleName,'Status',STATUS_COPY_FAIL);
			// $this->setConf($this->scheduleName,'Error Count',count($copyFail));

			if ($responseTimeout)
				$this->writeSysLog(STATUS_COPY_TIMEOUT_MESG,1);
			else
				$this->writeSysLog(sprintf(STATUS_COPY_FAIL_MESG, $this->jobName, count($copyFail)),1);

		}else{
			$this->setConf($this->scheduleName,'Status',STATUS_SUCCESS);
			$this->writeSysLog(STATUS_SUCCESS_MESG,4);
		}
		$this->setConf($this->scheduleName,'Finished Time',time());
		$this->debugMesg('== Finish '.$this->usageType.' Job ('.$this->jobName.') ==');

    } else if ($this->usageType == 'download') {
      // set status to running
      $this->setConf($this->scheduleName,'Status',STATUS_NOW);

      $this->writeSysLog(STATUS_S3_JOB_STARTED, 4);

      $thisFolder = $lastFolder = '';

      // get all file list
	  $filePrefix='';
      if ($this->S3Object == '')
      	$this->getAllfileList('', $this->fileList);
	  else{
			$filePrefix=$this->S3Object.'/';
			$this->getAllfileList($filePrefix, $this->fileList);
	  }

	  $fileNum = count($this->fileList);
	  $this->debugMesg('== Start '.$this->usageType.' Job ('.$this->jobName.') - '.$fileNum.' file(s) to download ==');
      $this->writeSysLog(sprintf(STATUS_S3_JOB_PROGRESS, $this->jobName, $fileNum), 4);

	  $i = 0;
      foreach ($this->fileList as $file)
      {

		//determined where to cut off, if S3Object is not empty, shift it right by 1
		if($this->S3Object != '')
			$thisPath = str_replace('//', '/', $this->NasFolderPath.substr(str_replace('_$folder$', '', $file['key']),(strlen($this->S3Object)+1) ));
		else
			$thisPath = str_replace('//', '/', $this->NasFolderPath.substr(str_replace('_$folder$', '', $file['key']),(strlen($this->S3Object)) ));
        

		$this->debugMesg('['.(++$i).'/'.$fileNum.'] Getting '.$this->bucketName.'/'.str_replace('_$folder$', '', $file['key']).' to '.$thisPath.' now...');

		if(preg_match('/\/$/', $thisPath) == 1){
			$thisFolder = $thisPath;
		}elseif(stripos($file['key'], '_$folder$')!=false){
			$thisFolder = $thisPath.'/';
		}else{
			$thisFolder = dirname($thisPath).'/';
		}
        //echo $file->key."\n".$thisPath."\n";
        if (!file_exists($thisFolder))
        {
          mkdir($thisFolder,0777,true);
          chmod($thisFolder,0777);
          $this->debugMesg('mkdir '.$thisFolder);
        }

		//Skips the getting file potion because this is just a folder and it has already been created.
		if(preg_match('/\/$/', $file['key']) == 1 || stripos($file['key'], '_$folder$')!=false){
			continue;
		}

		for($count = 0; $count<=$this->maximumRetries; $count++){
			// base on $NasFolderPath
			if( $this->S3->getObject($this->bucketName,$file['key'],$thisPath) === false )
			{
			  // fail
			  if($count==($this->maximumRetries)){
                $this->writeSysLog(sprintf(STATUS_S3_TASK_DOWNLOAD_FAILED, $this->jobName, $file['key'], $count), 1);
				$copyFail[] = 'Fail to download file from remote server. ['.$this->bucketName.'/'.str_replace('_$folder$', '', $file['key']).']';
			  }

			  $this->debugMesg('['.($i).'/'.$fileNum.'] Fail...'.$this->bucketName.'/'.str_replace('_$folder$', '', $file['key']).' to '.$thisPath);
			} else {
			  // success
			  chmod($thisPath,0666);
			  $this->debugMesg('['.($i).'/'.$fileNum.'] Success...'.$this->bucketName.'/'.str_replace('_$folder$', '', $file['key']).' to '.$thisPath);
			  break;
			}
		}

        $lastFolder = $thisFolder;
      }

      // set status
      if (count($copyFail)>0)
      {
        $this->setConf($this->scheduleName,'Status',STATUS_COPY_FAIL);

        if ($responseTimeout)
			$this->writeSysLog(STATUS_COPY_TIMEOUT_MESG,1);
		else
			$this->writeSysLog(sprintf(STATUS_COPY_FAIL_MESG, $this->jobName, count($copyFail)),1);
			
		$this->debugMesg(print_r($copyFail));
	  }else {
			$this->setConf($this->scheduleName,'Status',STATUS_SUCCESS);
			$this->writeSysLog(STATUS_SUCCESS_MESG,4);
	  }
	  $this->setConf($this->scheduleName,'Finished Time',time());
	  $this->debugMesg('== Finish '.$this->usageType.' Job ('.$this->jobName.') ==');
	}	

  }

  function getScheduleByName($scheduleName)
  {
    $fArray = file($this->configFile);
    $fCol = count($fArray);
    $findFlag = true;
    $findSchedule = false;
    $i = 0;

    // find schedule settings
    while($findFlag)
    {
      $thisCol = trim($fArray[$i]);

      if (!$findSchedule)
      {
        if ($thisCol == '['.$scheduleName.']')
          $findSchedule = true;
      } else {
      //echo $thisCol."\n";
        if (substr($thisCol,0,1) == '[')
        {
          $findFlag = false;
        } else {
          $tmpArray = explode('=',$thisCol);
          $this->scheduleSetting[trim($tmpArray[0])] = trim($tmpArray[1]);
        }
      }

      if (($i+1) == $fCol)
        $findFlag = false;
      $i++;
    }

    if (!$findSchedule)
    {
      $this->debugMesg('Schedule Name "'.$scheduleName.'" is not exist.');
      exit;
    }

    // initial value
    $this->jobName = $this->scheduleSetting['Name'];
    $this->__accessKey = $this->scheduleSetting['Access Key'];
    $this->__privateKey = $this->scheduleSetting['Private Key'];

    $this->bucketName = $this->scheduleSetting['S3 Bucket'];
    $this->NasShareName = $this->scheduleSetting['Local Volume'];
    $this->NasFolderName = $this->scheduleSetting['Local Path'];
    $this->S3Bucket = $this->scheduleSetting['S3 Bucket'];
    $this->S3Object = $this->scheduleSetting['S3 Object'];
    $this->usageType = $this->scheduleSetting['Type'];
    $this->NasSharePath = $this->NasFileRoot.'/'.$this->NasShareName.'/';
    $this->NasFolderPath = $this->NasSharePath. ($this->NasFolderName == '' ? '' : $this->NasFolderName.'/');
    $this->NasFolderPathLen = strlen($this->NasFolderPath);
	$this->doIncrementalBackup = $this->scheduleSetting['Increamental Backup'];
	$this->doDeleteExtraFiles = $this->scheduleSetting['Remove Extra Files'];
	$this->maximumRetries = $this->scheduleSetting['Maximum Retries'];
  }

  // get all files in directory
  function filesInDir($tdir, &$tempList = array())
  {
    $dirs = scandir($tdir);
	if(count($dirs)==2){	//special case, this is an empty folder
		$tempList[] = array(
				'key'=>$tdir,
				'size'=>0,
				'lastModified'=>filemtime($tdir)
			);
	}else{
		foreach($dirs as $file)
		{
		  if (($file == '.')||($file == '..'))
		  {
		  } else if (is_dir($tdir.$file)) {
			$this->filesInDir($tdir.$file.'/', $tempList);
		  }else{
			$filesize = filesize($tdir.$file);
			if($filesize === false){
				trigger_error('File ['.$tdir.$file.'] exceeds system limitation (2GB)', E_USER_NOTICE);
				$tempList[] = array(
					'key'=>$tdir.$file,
					'size'=>0,
					'lastModified'=>0
				);
			}else{
				$tempList[] = array(
					'key'=>$tdir.$file,
					'size'=>filesize($tdir.$file),
					'lastModified'=>filemtime($tdir.$file)
				);
			}
		  }
		}
	}
    return $tempList;
  }

  // get all files in directory , prefix is like folder/
  function getAllfileList($prefix='', &$tempList)
  {
	$contents = $this->S3->getBucket($this->bucketName,$prefix, null, null, '/', true);
	$contentPrefixes = array();	
	
    foreach($contents as $content)
    {
		if(isset($content['prefix'])){
			$contentPrefixes[] = $content['prefix'];
		}else{
			$tempList[] = array(
				'key'=>(String)$content['name'],
				'size'=>(String)$content['size'],
				'lastModified'=>$content['time']
			);
		}
    }
	
	foreach($contentPrefixes as $prefix){
		$this->getAllfileList($prefix, $tempList);
	}
	
  }

  function setConf($scheduleName,$param,$value)
  {
    $fArray = file($this->configFile);
    $fCol = count($fArray);
    $findFlag = true;
    $findSchedule = false;
    $i = 0;

    // find schedule settings
    while($findFlag)
    {
      $thisCol = trim($fArray[$i]);

      if (!$findSchedule)
      {
        if ($thisCol == '['.$scheduleName.']')
          $findSchedule = true;
      } else {
        if (substr($thisCol,0,1) == '[')
        {
          $findFlag = false;
        } else {
          $tmpArray = explode('=',$thisCol);
          if (trim($tmpArray[0]) == $param)
          {
            //echo $param.' = '.$value;
            $fArray[$i] = $param.' = '.$value."\n";
          }
        }
      }

      if (($i+1) == $fCol)
        $findFlag = false;
      $i++;
    }

    $fp = fopen($this->configFile,'w+');
    fwrite($fp,implode('',$fArray));
    fclose($fp);

    return;
  }

  function doFilter(&$filesList, $filePrefix, &$filtersList, $filtersPrefix, &$updateList, &$deleteList){

	//print_r($filesList);
	//echo "\n${filePrefix}\n";
	//print_r($filtersList[2]);
	//echo "\n${filtersPrefix}\n";

	$fileIndexes = array();
	$filterIndexes = array();
	
	foreach($filtersList as $filter){
		$filterIndexes[] = substr($filter['key'], strlen($filtersPrefix));
	}

	//creates the updating list, the files that needs to be uploaded/downloaded
	foreach($filesList as &$file){
		$key = substr($file['key'], strlen($filePrefix));
		$fileIndexes[] = $key;
		if(in_array($key, $filterIndexes)){
			$length = count($filterIndexes);
			for($i=0; $i<$length; $i++){
				if($key == $filterIndexes[$i]){
					//found the object, now check to see if it needs to be updated.
					$indexFound = true;
					if(preg_match('/\/$/', $key)) continue; //this is a folder, skips checking the size and mod date
					if($file['size']!=$filtersList[$i]['size'] || $file['lastModified']>$filtersList[$i]['lastModified']){
						$updateList[] = &$file;
					}
					break; //found the object, so break either way
				}
			}
		}else{
			//not on the filter list, so this is new upload/download
			$updateList[] = &$file;
		}
	}

	//creates the removing list, the files that needs to be removed because they are on the filter indexes but not in the file list
	$deletedIndexes = array_diff($filterIndexes, $fileIndexes);
	//print_r($deletedIndexes);
	foreach($deletedIndexes as $index){
		$keyIndex = array_search($index, $filterIndexes);
		$deleteList[] = &$filtersList[$keyIndex];
	}
	//print_r($deleteList);

  }

  function debugMesg($s)
  {
    if (DEBUG_MODE)
      echo "\n".$s."\n";
  }

  // write to system log
  function writeSysLog($m,$n)
  {
    $m = sprintf($m,$this->jobName);
    exec('/sbin/write_log "'.$m.'" '.$n);
  }

  function writePid(){
    exec('echo '.getmypid().' >/tmp/amazon_s3_'.$this->scheduleName.'.pid');
  }
}


// If you want to use PECL Fileinfo for MIME types:
//if (!extension_loaded('fileinfo') && @dl('fileinfo.so')) $_ENV['MAGIC'] = '/usr/share/file/magic';

// Check for CURL
//if (!extension_loaded('curl') && !@dl(PHP_SHLIB_SUFFIX == 'so' ? 'curl.so' : 'php_curl.dll'))
//	exit("\nERROR: CURL extension not loaded\n\n");

/**
* $Id: amazonS3RR.php,v 1.24 2010-12-21 02:24:53 johnsonlee Exp $
*
* Copyright (c) 2008, Donovan Schönknecht.  All rights reserved.
*
* Redistribution and use in source and binary forms, with or without
* modification, are permitted provided that the following conditions are met:
*
* - Redistributions of source code must retain the above copyright notice,
*   this list of conditions and the following disclaimer.
* - Redistributions in binary form must reproduce the above copyright
*   notice, this list of conditions and the following disclaimer in the
*   documentation and/or other materials provided with the distribution.
*
* THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
* AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
* IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
* ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
* LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
* CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
* SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
* INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
* CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
* ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
* POSSIBILITY OF SUCH DAMAGE.
*
* Amazon S3 is a trademark of Amazon.com, Inc. or its affiliates.
*/

/**
* Amazon S3 PHP class
*
* @link http://undesigned.org.za/2007/10/22/amazon-s3-php-class
* @version 0.4.0
*/
class S3 {
	// ACL flags
	const ACL_PRIVATE = 'private';
	const ACL_PUBLIC_READ = 'public-read';
	const ACL_PUBLIC_READ_WRITE = 'public-read-write';
	const ACL_AUTHENTICATED_READ = 'authenticated-read';

	public static $useSSL = false;

	private static $__accessKey; // AWS Access key
	private static $__secretKey; // AWS Secret key


	/**
	* Constructor - if you're not using the class statically
	*
	* @param string $accessKey Access key
	* @param string $secretKey Secret key
	* @param boolean $useSSL Enable SSL
	* @return void
	*/
	public function __construct($accessKey = null, $secretKey = null, $useSSL = true) {
		if ($accessKey !== null && $secretKey !== null)
			self::setAuth($accessKey, $secretKey);
		self::$useSSL = $useSSL;
	}


	/**
	* Set AWS access key and secret key
	*
	* @param string $accessKey Access key
	* @param string $secretKey Secret key
	* @return void
	*/
	public static function setAuth($accessKey, $secretKey) {
		self::$__accessKey = $accessKey;
		self::$__secretKey = $secretKey;
	}


	/**
	* Get a list of buckets
	*
	* @param boolean $detailed Returns detailed bucket list when true
	* @return array | false
	*/
	public static function listBuckets($detailed = false) {
		$rest = new S3Request('GET', '', '');
		$rest = $rest->getResponse();
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::listBuckets(): [%s] %s", $rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}
		$results = array();
		if (!isset($rest->body->Buckets)) return $results;

		if ($detailed) {
			if (isset($rest->body->Owner, $rest->body->Owner->ID, $rest->body->Owner->DisplayName))
			$results['owner'] = array(
				'id' => (string)$rest->body->Owner->ID, 'name' => (string)$rest->body->Owner->ID
			);
			$results['buckets'] = array();
			foreach ($rest->body->Buckets->Bucket as $b)
				$results['buckets'][] = array(
					'name' => (string)$b->Name, 'time' => strtotime((string)$b->CreationDate)
				);
		} else
			foreach ($rest->body->Buckets->Bucket as $b) $results[] = (string)$b->Name;

		return $results;
	}


	/*
	* Get contents for a bucket
	*
	* If maxKeys is null this method will loop through truncated result sets
	*
	* @param string $bucket Bucket name
	* @param string $prefix Prefix
	* @param string $marker Marker (last file listed)
	* @param string $maxKeys Max keys (maximum number of keys to return)
	* @param string $delimiter Delimiter
	* @param boolean $returnCommonPrefixes Set to true to return CommonPrefixes
	* @return array | false
	*/
	public static function getBucket($bucket, $prefix = null, $marker = null, $maxKeys = null, $delimiter = null, $returnCommonPrefixes = false) {
		$rest = new S3Request('GET', $bucket, '');
		if ($prefix !== null && $prefix !== '') $rest->setParameter('prefix', $prefix);
		if ($marker !== null && $marker !== '') $rest->setParameter('marker', $marker);
		if ($maxKeys !== null && $maxKeys !== '') $rest->setParameter('max-keys', $maxKeys);
		if ($delimiter !== null && $delimiter !== '') $rest->setParameter('delimiter', $delimiter);
		$response = $rest->getResponse();
		if ($response->error === false && $response->code !== 200)
			$response->error = array('code' => $response->code, 'message' => 'Unexpected HTTP status');
		if ($response->error !== false) {
			trigger_error(sprintf("S3::getBucket(): [%s] %s", $response->error['code'], $response->error['message']), E_USER_WARNING);
			return false;
		}

		$results = array();

		$nextMarker = null;
		if (isset($response->body, $response->body->Contents))
		foreach ($response->body->Contents as $c) {
			$results[(string)$c->Key] = array(
				'name' => (string)$c->Key,
				'time' => strtotime((string)$c->LastModified),
				'size' => (int)$c->Size,
				'hash' => substr((string)$c->ETag, 1, -1)
			);
			$nextMarker = (string)$c->Key;
		}

		if ($returnCommonPrefixes && isset($response->body, $response->body->CommonPrefixes))
			foreach ($response->body->CommonPrefixes as $c)
				$results[(string)$c->Prefix] = array('prefix' => (string)$c->Prefix);

		if (isset($response->body, $response->body->IsTruncated) &&
		(string)$response->body->IsTruncated == 'false') return $results;

		if (isset($response->body, $response->body->NextMarker))
			$nextMarker = (string)$response->body->NextMarker;

		// Loop through truncated results if maxKeys isn't specified
		if ($maxKeys == null && $nextMarker !== null && (string)$response->body->IsTruncated == 'true')
		do {
			$rest = new S3Request('GET', $bucket, '');
			if ($prefix !== null && $prefix !== '') $rest->setParameter('prefix', $prefix);
			$rest->setParameter('marker', $nextMarker);
			if ($delimiter !== null && $delimiter !== '') $rest->setParameter('delimiter', $delimiter);

			if (($response = $rest->getResponse(true)) == false || $response->code !== 200) break;

			if (isset($response->body, $response->body->Contents))
			foreach ($response->body->Contents as $c) {
				$results[(string)$c->Key] = array(
					'name' => (string)$c->Key,
					'time' => strtotime((string)$c->LastModified),
					'size' => (int)$c->Size,
					'hash' => substr((string)$c->ETag, 1, -1)
				);
				$nextMarker = (string)$c->Key;
			}

			if ($returnCommonPrefixes && isset($response->body, $response->body->CommonPrefixes))
				foreach ($response->body->CommonPrefixes as $c)
					$results[(string)$c->Prefix] = array('prefix' => (string)$c->Prefix);

			if (isset($response->body, $response->body->NextMarker))
				$nextMarker = (string)$response->body->NextMarker;

		} while ($response !== false && (string)$response->body->IsTruncated == 'true');

		return $results;
	}


	/**
	* Put a bucket
	*
	* @param string $bucket Bucket name
	* @param constant $acl ACL flag
	* @param string $location Set as "EU" to create buckets hosted in Europe
	* @return boolean
	*/
	public static function putBucket($bucket, $acl = self::ACL_PRIVATE, $location = false) {
		$rest = new S3Request('PUT', $bucket, '');
		$rest->setAmzHeader('x-amz-acl', $acl);

		if ($location !== false) {
			$dom = new DOMDocument;
			$createBucketConfiguration = $dom->createElement('CreateBucketConfiguration');
			$locationConstraint = $dom->createElement('LocationConstraint', strtoupper($location));
			$createBucketConfiguration->appendChild($locationConstraint);
			$dom->appendChild($createBucketConfiguration);
			$rest->data = $dom->saveXML();
			$rest->size = strlen($rest->data);
			$rest->setHeader('Content-Type', 'application/xml');
		}
		$rest = $rest->getResponse();

		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::putBucket({$bucket}, {$acl}, {$location}): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}
		return true;
	}


	/**
	* Delete an empty bucket
	*
	* @param string $bucket Bucket name
	* @return boolean
	*/
	public static function deleteBucket($bucket) {
		$rest = new S3Request('DELETE', $bucket);
		$rest = $rest->getResponse();
		if ($rest->error === false && $rest->code !== 204)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::deleteBucket({$bucket}): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}
		return true;
	}


	/**
	* Create input info array for putObject()
	*
	* @param string $file Input file
	* @param mixed $md5sum Use MD5 hash (supply a string if you want to use your own)
	* @return array | false
	*/
	public static function inputFile($file, $md5sum = true) {
		//modded by Johnson
		if(is_dir($file)){
			return array(
				'data' => $file
			);
		}else{
			if (!file_exists($file) || !is_file($file) || !is_readable($file)) {
				trigger_error('S3::inputFile(): Unable to open input file: '.$file, E_USER_WARNING);
				return false;
			}
			return array(
				'file' => $file,
				'size' => filesize($file),
				'md5sum' => $md5sum !== false ? (is_string($md5sum) ? $md5sum : base64_encode(md5_file($file, true))) : ''
			);
		}
	}


	/**
	* Create input array info for putObject() with a resource
	*
	* @param string $resource Input resource to read from
	* @param integer $bufferSize Input byte size
	* @param string $md5sum MD5 hash to send (optional)
	* @return array | false
	*/
	public static function inputResource(&$resource, $bufferSize, $md5sum = '') {
		if (!is_resource($resource) || $bufferSize < 0) {
			trigger_error('S3::inputResource(): Invalid resource or buffer size', E_USER_WARNING);
			return false;
		}
		$input = array('size' => $bufferSize, 'md5sum' => $md5sum);
		$input['fp'] =& $resource;
		return $input;
	}


	/**
	* Put an object
	*
	* @param mixed $input Input data
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param constant $acl ACL constant
	* @param array $metaHeaders Array of x-amz-meta-* headers
	* @param array $requestHeaders Array of request headers or content type as a string
	* @return boolean
	*/
	public static function putObject($input, $bucket, $uri, $acl = self::ACL_PRIVATE, $metaHeaders = array(), $requestHeaders = array()) {
		if ($input === false) return false;
		$rest = new S3Request('PUT', $bucket, $uri);

		if (is_string($input)) $input = array(
			'data' => $input, 'size' => strlen($input),
			'md5sum' => base64_encode(md5($input, true))
		);

		// Data
		if (isset($input['fp']))
			$rest->fp =& $input['fp'];
		elseif (isset($input['file']))
			$rest->fp = @fopen($input['file'], 'rb');
		elseif (isset($input['data']))
			$rest->data = $input['data'];

		// Content-Length (required)
		if (isset($input['size']) && $input['size'] >= 0)
			$rest->size = $input['size'];
		else {
			if (isset($input['file']))
				$rest->size = filesize($input['file']);
			elseif (isset($input['data']))
				$rest->size = strlen($input['data']);
		}

		// Custom request headers (Content-Type, Content-Disposition, Content-Encoding)
		if (is_array($requestHeaders))
			foreach ($requestHeaders as $h => $v) $rest->setHeader($h, $v);
		elseif (is_string($requestHeaders)) // Support for legacy contentType parameter
			$input['type'] = $requestHeaders;

		// Content-Type
		if (!isset($input['type'])) {
			if (isset($requestHeaders['Content-Type']))
				$input['type'] =& $requestHeaders['Content-Type'];
			elseif (isset($input['file']))
				$input['type'] = self::__getMimeType($input['file']);
			else
				$input['type'] = 'application/octet-stream';
		}

		// We need to post with Content-Length and Content-Type, MD5 is optional
		if ($rest->size >= 0 && ($rest->fp !== false || $rest->data !== false)) {
			$rest->setHeader('Content-Type', $input['type']);
			if (isset($input['md5sum'])) $rest->setHeader('Content-MD5', $input['md5sum']);

			$rest->setAmzHeader('x-amz-acl', $acl);
			foreach ($metaHeaders as $h => $v) $rest->setAmzHeader('x-amz-meta-'.$h, $v);
			$rest->getResponse();
		} else
			$rest->response->error = array('code' => 0, 'message' => 'Missing input parameters');

		if ($rest->response->error === false && $rest->response->code !== 200)
			$rest->response->error = array('code' => $rest->response->code, 'message' => 'Unexpected HTTP status');
		if ($rest->response->error !== false) {
			trigger_error(sprintf("S3::putObject(): [%s] %s", $rest->response->error['code'], $rest->response->error['message']), E_USER_WARNING);
			return false;
		}
		return true;
	}


	/**
	* Put an object from a file (legacy function)
	*
	* @param string $file Input file path
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param constant $acl ACL constant
	* @param array $metaHeaders Array of x-amz-meta-* headers
	* @param string $contentType Content type
	* @return boolean
	*/
	public static function putObjectFile($file, $bucket, $uri, $acl = self::ACL_PRIVATE, $metaHeaders = array(), $contentType = null) {
		return self::putObject(self::inputFile($file), $bucket, $uri, $acl, $metaHeaders, $contentType);
	}


	/**
	* Put an object from a string (legacy function)
	*
	* @param string $string Input data
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param constant $acl ACL constant
	* @param array $metaHeaders Array of x-amz-meta-* headers
	* @param string $contentType Content type
	* @return boolean
	*/
	public static function putObjectString($string, $bucket, $uri, $acl = self::ACL_PRIVATE, $metaHeaders = array(), $contentType = 'text/plain') {
		return self::putObject($string, $bucket, $uri, $acl, $metaHeaders, $contentType);
	}


	/**
	* Get an object
	*
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param mixed $saveTo Filename or resource to write to
	* @return mixed
	*/
	public static function getObject($bucket, $uri, $saveTo = false) {
		$rest = new S3Request('GET', $bucket, $uri);
		if ($saveTo !== false) {
			if (is_resource($saveTo))
				$rest->fp =& $saveTo;
			else
				if (($rest->fp = @fopen($saveTo, 'wb')) !== false)
					$rest->file = realpath($saveTo);
				else
					$rest->response->error = array('code' => 0, 'message' => 'Unable to open save file for writing: '.$saveTo);
		}
		if ($rest->response->error === false) $rest->getResponse();

		if ($rest->response->error === false && $rest->response->code !== 200)
			$rest->response->error = array('code' => $rest->response->code, 'message' => 'Unexpected HTTP status');
		if ($rest->response->error !== false) {
			trigger_error(sprintf("S3::getObject({$bucket}, {$uri}): [%s] %s",
			$rest->response->error['code'], $rest->response->error['message']), E_USER_WARNING);
			return false;
		}
		return $rest->response;
	}


	/**
	* Get object information
	*
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param boolean $returnInfo Return response information
	* @return mixed | false
	*/
	public static function getObjectInfo($bucket, $uri, $returnInfo = true) {
		$rest = new S3Request('HEAD', $bucket, $uri);
		$rest = $rest->getResponse();
		if ($rest->error === false && ($rest->code !== 200 && $rest->code !== 404))
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::getObjectInfo({$bucket}, {$uri}): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}
		return $rest->code == 200 ? $returnInfo ? $rest->headers : true : false;
	}

	/**
	* Copy an object
	*
	* @param string $bucket Source bucket name
	* @param string $uri Source object URI
	* @param string $bucket Destination bucket name
	* @param string $uri Destination object URI
	* @param constant $acl ACL constant
	* @param array $metaHeaders Optional array of x-amz-meta-* headers
	* @param array $requestHeaders Optional array of request headers (content type, disposition, etc.)
	* @return mixed | false
	*/
	public static function copyObject($srcBucket, $srcUri, $bucket, $uri, $acl = self::ACL_PRIVATE, $metaHeaders = array(), $requestHeaders = array()) {
		$rest = new S3Request('PUT', $bucket, $uri);
		$rest->setHeader('Content-Length', 0);
		foreach ($requestHeaders as $h => $v) $rest->setHeader($h, $v);
		foreach ($metaHeaders as $h => $v) $rest->setAmzHeader('x-amz-meta-'.$h, $v);
		$rest->setAmzHeader('x-amz-acl', $acl);
		$rest->setAmzHeader('x-amz-copy-source', sprintf('/%s/%s', $srcBucket, $srcUri));
		if (sizeof($requestHeaders) > 0 || sizeof($metaHeaders) > 0)
			$rest->setAmzHeader('x-amz-metadata-directive', 'REPLACE');
		$rest = $rest->getResponse();
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::copyObject({$srcBucket}, {$srcUri}, {$bucket}, {$uri}): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}
		return isset($rest->body->LastModified, $rest->body->ETag) ? array(
			'time' => strtotime((string)$rest->body->LastModified),
			'hash' => substr((string)$rest->body->ETag, 1, -1)
		) : false;
	}


	/**
	* Set logging for a bucket
	*
	* @param string $bucket Bucket name
	* @param string $targetBucket Target bucket (where logs are stored)
	* @param string $targetPrefix Log prefix (e,g; domain.com-)
	* @return boolean
	*/
	public static function setBucketLogging($bucket, $targetBucket, $targetPrefix = null) {
		// The S3 log delivery group has to be added to the target bucket's ACP
		if ($targetBucket !== null && ($acp = self::getAccessControlPolicy($targetBucket, '')) !== false) {
			// Only add permissions to the target bucket when they do not exist
			$aclWriteSet = false;
			$aclReadSet = false;
			foreach ($acp['acl'] as $acl)
			if ($acl['type'] == 'Group' && $acl['uri'] == 'http://acs.amazonaws.com/groups/s3/LogDelivery') {
				if ($acl['permission'] == 'WRITE') $aclWriteSet = true;
				elseif ($acl['permission'] == 'READ_ACP') $aclReadSet = true;
			}
			if (!$aclWriteSet) $acp['acl'][] = array(
				'type' => 'Group', 'uri' => 'http://acs.amazonaws.com/groups/s3/LogDelivery', 'permission' => 'WRITE'
			);
			if (!$aclReadSet) $acp['acl'][] = array(
				'type' => 'Group', 'uri' => 'http://acs.amazonaws.com/groups/s3/LogDelivery', 'permission' => 'READ_ACP'
			);
			if (!$aclReadSet || !$aclWriteSet) self::setAccessControlPolicy($targetBucket, '', $acp);
		}

		$dom = new DOMDocument;
		$bucketLoggingStatus = $dom->createElement('BucketLoggingStatus');
		$bucketLoggingStatus->setAttribute('xmlns', 'http://s3.amazonaws.com/doc/2006-03-01/');
		if ($targetBucket !== null) {
			if ($targetPrefix == null) $targetPrefix = $bucket . '-';
			$loggingEnabled = $dom->createElement('LoggingEnabled');
			$loggingEnabled->appendChild($dom->createElement('TargetBucket', $targetBucket));
			$loggingEnabled->appendChild($dom->createElement('TargetPrefix', $targetPrefix));
			// TODO: Add TargetGrants?
			$bucketLoggingStatus->appendChild($loggingEnabled);
		}
		$dom->appendChild($bucketLoggingStatus);

		$rest = new S3Request('PUT', $bucket, '');
		$rest->setParameter('logging', null);
		$rest->data = $dom->saveXML();
		$rest->size = strlen($rest->data);
		$rest->setHeader('Content-Type', 'application/xml');
		$rest = $rest->getResponse();
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::setBucketLogging({$bucket}, {$uri}): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}
		return true;
	}


	/**
	* Get logging status for a bucket
	*
	* This will return false if logging is not enabled.
	* Note: To enable logging, you also need to grant write access to the log group
	*
	* @param string $bucket Bucket name
	* @return array | false
	*/
	public static function getBucketLogging($bucket) {
		$rest = new S3Request('GET', $bucket, '');
		$rest->setParameter('logging', null);
		$rest = $rest->getResponse();
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::getBucketLogging({$bucket}): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}
		if (!isset($rest->body->LoggingEnabled)) return false; // No logging
		return array(
			'targetBucket' => (string)$rest->body->LoggingEnabled->TargetBucket,
			'targetPrefix' => (string)$rest->body->LoggingEnabled->TargetPrefix,
		);
	}


	/**
	* Disable bucket logging
	*
	* @param string $bucket Bucket name
	* @return boolean
	*/
	public static function disableBucketLogging($bucket) {
		return self::setBucketLogging($bucket, null);
	}


	/**
	* Get a bucket's location
	*
	* @param string $bucket Bucket name
	* @return string | false
	*/
	public static function getBucketLocation($bucket) {
		$rest = new S3Request('GET', $bucket, '');
		$rest->setParameter('location', null);
		$rest = $rest->getResponse();
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::getBucketLocation({$bucket}): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}
		return (isset($rest->body[0]) && (string)$rest->body[0] !== '') ? (string)$rest->body[0] : 'US';
	}


	/**
	* Set object or bucket Access Control Policy
	*
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param array $acp Access Control Policy Data (same as the data returned from getAccessControlPolicy)
	* @return boolean
	*/
	public static function setAccessControlPolicy($bucket, $uri = '', $acp = array()) {
		$dom = new DOMDocument;
		$dom->formatOutput = true;
		$accessControlPolicy = $dom->createElement('AccessControlPolicy');
		$accessControlList = $dom->createElement('AccessControlList');

		// It seems the owner has to be passed along too
		$owner = $dom->createElement('Owner');
		$owner->appendChild($dom->createElement('ID', $acp['owner']['id']));
		$owner->appendChild($dom->createElement('DisplayName', $acp['owner']['name']));
		$accessControlPolicy->appendChild($owner);

		foreach ($acp['acl'] as $g) {
			$grant = $dom->createElement('Grant');
			$grantee = $dom->createElement('Grantee');
			$grantee->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
			if (isset($g['id'])) { // CanonicalUser (DisplayName is omitted)
				$grantee->setAttribute('xsi:type', 'CanonicalUser');
				$grantee->appendChild($dom->createElement('ID', $g['id']));
			} elseif (isset($g['email'])) { // AmazonCustomerByEmail
				$grantee->setAttribute('xsi:type', 'AmazonCustomerByEmail');
				$grantee->appendChild($dom->createElement('EmailAddress', $g['email']));
			} elseif ($g['type'] == 'Group') { // Group
				$grantee->setAttribute('xsi:type', 'Group');
				$grantee->appendChild($dom->createElement('URI', $g['uri']));
			}
			$grant->appendChild($grantee);
			$grant->appendChild($dom->createElement('Permission', $g['permission']));
			$accessControlList->appendChild($grant);
		}

		$accessControlPolicy->appendChild($accessControlList);
		$dom->appendChild($accessControlPolicy);

		$rest = new S3Request('PUT', $bucket, $uri);
		$rest->setParameter('acl', null);
		$rest->data = $dom->saveXML();
		$rest->size = strlen($rest->data);
		$rest->setHeader('Content-Type', 'application/xml');
		$rest = $rest->getResponse();
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::setAccessControlPolicy({$bucket}, {$uri}): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}
		return true;
	}


	/**
	* Get object or bucket Access Control Policy
	*
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @return mixed | false
	*/
	public static function getAccessControlPolicy($bucket, $uri = '') {
		$rest = new S3Request('GET', $bucket, $uri);
		$rest->setParameter('acl', null);
		$rest = $rest->getResponse();
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::getAccessControlPolicy({$bucket}, {$uri}): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}

		$acp = array();
		if (isset($rest->body->Owner, $rest->body->Owner->ID, $rest->body->Owner->DisplayName)) {
			$acp['owner'] = array(
				'id' => (string)$rest->body->Owner->ID, 'name' => (string)$rest->body->Owner->DisplayName
			);
		}
		if (isset($rest->body->AccessControlList)) {
			$acp['acl'] = array();
			foreach ($rest->body->AccessControlList->Grant as $grant) {
				foreach ($grant->Grantee as $grantee) {
					if (isset($grantee->ID, $grantee->DisplayName)) // CanonicalUser
						$acp['acl'][] = array(
							'type' => 'CanonicalUser',
							'id' => (string)$grantee->ID,
							'name' => (string)$grantee->DisplayName,
							'permission' => (string)$grant->Permission
						);
					elseif (isset($grantee->EmailAddress)) // AmazonCustomerByEmail
						$acp['acl'][] = array(
							'type' => 'AmazonCustomerByEmail',
							'email' => (string)$grantee->EmailAddress,
							'permission' => (string)$grant->Permission
						);
					elseif (isset($grantee->URI)) // Group
						$acp['acl'][] = array(
							'type' => 'Group',
							'uri' => (string)$grantee->URI,
							'permission' => (string)$grant->Permission
						);
					else continue;
				}
			}
		}
		return $acp;
	}


	/**
	* Delete an object
	*
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @return boolean
	*/
	public static function deleteObject($bucket, $uri) {
		$rest = new S3Request('DELETE', $bucket, $uri);
		$rest = $rest->getResponse();
		if ($rest->error === false && $rest->code !== 204)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::deleteObject(): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}
		return true;
	}


	/**
	* Get a query string authenticated URL
	*
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param integer $lifetime Lifetime in seconds
	* @param boolean $hostBucket Use the bucket name as the hostname
	* @param boolean $https Use HTTPS ($hostBucket should be false for SSL verification)
	* @return string
	*/
	public static function getAuthenticatedURL($bucket, $uri, $lifetime, $hostBucket = false, $https = false) {
		$expires = time() + $lifetime;
		$uri = str_replace('%2F', '/', rawurlencode($uri)); // URI should be encoded (thanks Sean O'Dea)
		return sprintf(($https ? 'https' : 'http').'://%s/%s?AWSAccessKeyId=%s&Expires=%u&Signature=%s',
		$hostBucket ? $bucket : $bucket.'.s3.amazonaws.com', $uri, self::$__accessKey, $expires,
		urlencode(self::__getHash("GET\n\n\n{$expires}\n/{$bucket}/{$uri}")));
	}

	/**
	* Get upload POST parameters for form uploads
	*
	* @param string $bucket Bucket name
	* @param string $uriPrefix Object URI prefix
	* @param constant $acl ACL constant
	* @param integer $lifetime Lifetime in seconds
	* @param integer $maxFileSize Maximum filesize in bytes (default 5MB)
	* @param string $successRedirect Redirect URL or 200 / 201 status code
	* @param array $amzHeaders Array of x-amz-meta-* headers
	* @param array $headers Array of request headers or content type as a string
	* @param boolean $flashVars Includes additional "Filename" variable posted by Flash
	* @return object
	*/
	public static function getHttpUploadPostParams($bucket, $uriPrefix = '', $acl = self::ACL_PRIVATE, $lifetime = 3600, $maxFileSize = 5242880, $successRedirect = "201", $amzHeaders = array(), $headers = array(), $flashVars = false) {
		// Create policy object
		$policy = new stdClass;
		$policy->expiration = gmdate('Y-m-d\TH:i:s\Z', (time() + $lifetime));
		$policy->conditions = array();
		$obj = new stdClass; $obj->bucket = $bucket; array_push($policy->conditions, $obj);
		$obj = new stdClass; $obj->acl = $acl; array_push($policy->conditions, $obj);

		$obj = new stdClass; // 200 for non-redirect uploads
		if (is_numeric($successRedirect) && in_array((int)$successRedirect, array(200, 201)))
			$obj->success_action_status = (string)$successRedirect;
		else // URL
			$obj->success_action_redirect = $successRedirect;
		array_push($policy->conditions, $obj);

		array_push($policy->conditions, array('starts-with', '$key', $uriPrefix));
		if ($flashVars) array_push($policy->conditions, array('starts-with', '$Filename', ''));
		foreach (array_keys($headers) as $headerKey)
			array_push($policy->conditions, array('starts-with', '$'.$headerKey, ''));
		foreach ($amzHeaders as $headerKey => $headerVal) {
			$obj = new stdClass; $obj->{$headerKey} = (string)$headerVal; array_push($policy->conditions, $obj);
		}
		array_push($policy->conditions, array('content-length-range', 0, $maxFileSize));
		$policy = base64_encode(str_replace('\/', '/', json_encode($policy)));

		// Create parameters
		$params = new stdClass;
		$params->AWSAccessKeyId = self::$__accessKey;
		$params->key = $uriPrefix.'${filename}';
		$params->acl = $acl;
		$params->policy = $policy; unset($policy);
		$params->signature = self::__getHash($params->policy);
		if (is_numeric($successRedirect) && in_array((int)$successRedirect, array(200, 201)))
			$params->success_action_status = (string)$successRedirect;
		else
			$params->success_action_redirect = $successRedirect;
		foreach ($headers as $headerKey => $headerVal) $params->{$headerKey} = (string)$headerVal;
		foreach ($amzHeaders as $headerKey => $headerVal) $params->{$headerKey} = (string)$headerVal;
		return $params;
	}

	/**
	* Create a CloudFront distribution
	*
	* @param string $bucket Bucket name
	* @param boolean $enabled Enabled (true/false)
	* @param array $cnames Array containing CNAME aliases
	* @param string $comment Use the bucket name as the hostname
	* @return array | false
	*/
	public static function createDistribution($bucket, $enabled = true, $cnames = array(), $comment = '') {
		self::$useSSL = true; // CloudFront requires SSL
		$rest = new S3Request('POST', '', '2008-06-30/distribution', 'cloudfront.amazonaws.com');
		$rest->data = self::__getCloudFrontDistributionConfigXML($bucket.'.s3.amazonaws.com', $enabled, $comment, (string)microtime(true), $cnames);
		$rest->size = strlen($rest->data);
		$rest->setHeader('Content-Type', 'application/xml');
		$rest = self::__getCloudFrontResponse($rest);

		if ($rest->error === false && $rest->code !== 201)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::createDistribution({$bucket}, ".(int)$enabled.", '$comment'): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		} elseif ($rest->body instanceof SimpleXMLElement)
			return self::__parseCloudFrontDistributionConfig($rest->body);
		return false;
	}


	/**
	* Get CloudFront distribution info
	*
	* @param string $distributionId Distribution ID from listDistributions()
	* @return array | false
	*/
	public static function getDistribution($distributionId) {
		self::$useSSL = true; // CloudFront requires SSL
		$rest = new S3Request('GET', '', '2008-06-30/distribution/'.$distributionId, 'cloudfront.amazonaws.com');
		$rest = self::__getCloudFrontResponse($rest);

		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::getDistribution($distributionId): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		} elseif ($rest->body instanceof SimpleXMLElement) {
			$dist = self::__parseCloudFrontDistributionConfig($rest->body);
			$dist['hash'] = $rest->headers['hash'];
			return $dist;
		}
		return false;
	}


	/**
	* Update a CloudFront distribution
	*
	* @param array $dist Distribution array info identical to output of getDistribution()
	* @return array | false
	*/
	public static function updateDistribution($dist) {
		self::$useSSL = true; // CloudFront requires SSL
		$rest = new S3Request('PUT', '', '2008-06-30/distribution/'.$dist['id'].'/config', 'cloudfront.amazonaws.com');
		$rest->data = self::__getCloudFrontDistributionConfigXML($dist['origin'], $dist['enabled'], $dist['comment'], $dist['callerReference'], $dist['cnames']);
		$rest->size = strlen($rest->data);
		$rest->setHeader('If-Match', $dist['hash']);
		$rest = self::__getCloudFrontResponse($rest);

		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::updateDistribution({$dist['id']}, ".(int)$enabled.", '$comment'): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		} else {
			$dist = self::__parseCloudFrontDistributionConfig($rest->body);
			$dist['hash'] = $rest->headers['hash'];
			return $dist;
		}
		return false;
	}


	/**
	* Delete a CloudFront distribution
	*
	* @param array $dist Distribution array info identical to output of getDistribution()
	* @return boolean
	*/
	public static function deleteDistribution($dist) {
		self::$useSSL = true; // CloudFront requires SSL
		$rest = new S3Request('DELETE', '', '2008-06-30/distribution/'.$dist['id'], 'cloudfront.amazonaws.com');
		$rest->setHeader('If-Match', $dist['hash']);
		$rest = self::__getCloudFrontResponse($rest);

		if ($rest->error === false && $rest->code !== 204)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::deleteDistribution({$dist['id']}): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}
		return true;
	}


	/**
	* Get a list of CloudFront distributions
	*
	* @return array
	*/
	public static function listDistributions() {
		self::$useSSL = true; // CloudFront requires SSL
		$rest = new S3Request('GET', '', '2008-06-30/distribution', 'cloudfront.amazonaws.com');
		$rest = self::__getCloudFrontResponse($rest);

		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false) {
			trigger_error(sprintf("S3::listDistributions(): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		} elseif ($rest->body instanceof SimpleXMLElement && isset($rest->body->DistributionSummary)) {
			$list = array();
			if (isset($rest->body->Marker, $rest->body->MaxItems, $rest->body->IsTruncated)) {
				//$info['marker'] = (string)$rest->body->Marker;
				//$info['maxItems'] = (int)$rest->body->MaxItems;
				//$info['isTruncated'] = (string)$rest->body->IsTruncated == 'true' ? true : false;
			}
			foreach ($rest->body->DistributionSummary as $summary) {
				$list[(string)$summary->Id] = self::__parseCloudFrontDistributionConfig($summary);
			}
			return $list;
		}
		return array();
	}


	/**
	* Get a DistributionConfig DOMDocument
	*
	* @internal Used to create XML in createDistribution() and updateDistribution()
	* @param string $bucket Origin bucket
	* @param boolean $enabled Enabled (true/false)
	* @param string $comment Comment to append
	* @param string $callerReference Caller reference
	* @param array $cnames Array of CNAME aliases
	* @return string
	*/
	private static function __getCloudFrontDistributionConfigXML($bucket, $enabled, $comment, $callerReference = '0', $cnames = array()) {
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;
		$distributionConfig = $dom->createElement('DistributionConfig');
		$distributionConfig->setAttribute('xmlns', 'http://cloudfront.amazonaws.com/doc/2008-06-30/');
		$distributionConfig->appendChild($dom->createElement('Origin', $bucket));
		$distributionConfig->appendChild($dom->createElement('CallerReference', $callerReference));
		foreach ($cnames as $cname)
			$distributionConfig->appendChild($dom->createElement('CNAME', $cname));
		if ($comment !== '') $distributionConfig->appendChild($dom->createElement('Comment', $comment));
		$distributionConfig->appendChild($dom->createElement('Enabled', $enabled ? 'true' : 'false'));
		$dom->appendChild($distributionConfig);
		return $dom->saveXML();
	}


	/**
	* Parse a CloudFront distribution config
	*
	* @internal Used to parse the CloudFront DistributionConfig node to an array
	* @param object &$node DOMNode
	* @return array
	*/
	private static function __parseCloudFrontDistributionConfig(&$node) {
		$dist = array();
		if (isset($node->Id, $node->Status, $node->LastModifiedTime, $node->DomainName)) {
			$dist['id'] = (string)$node->Id;
			$dist['status'] = (string)$node->Status;
			$dist['time'] = strtotime((string)$node->LastModifiedTime);
			$dist['domain'] = (string)$node->DomainName;
		}
		if (isset($node->CallerReference))
			$dist['callerReference'] = (string)$node->CallerReference;
		if (isset($node->Comment))
			$dist['comment'] = (string)$node->Comment;
		if (isset($node->Enabled, $node->Origin)) {
			$dist['origin'] = (string)$node->Origin;
			$dist['enabled'] = (string)$node->Enabled == 'true' ? true : false;
		} elseif (isset($node->DistributionConfig)) {
			$dist = array_merge($dist, self::__parseCloudFrontDistributionConfig($node->DistributionConfig));
		}
		if (isset($node->CNAME)) {
			$dist['cnames'] = array();
			foreach ($node->CNAME as $cname) $dist['cnames'][(string)$cname] = (string)$cname;
		}
		return $dist;
	}


	/**
	* Grab CloudFront response
	*
	* @internal Used to parse the CloudFront S3Request::getResponse() output
	* @param object &$rest S3Request instance
	* @return object
	*/
	private static function __getCloudFrontResponse(&$rest) {
		$rest->getResponse();
		if ($rest->response->error === false && isset($rest->response->body) &&
		is_string($rest->response->body) && substr($rest->response->body, 0, 5) == '<?xml') {
			$rest->response->body = simplexml_load_string($rest->response->body);
			// Grab CloudFront errors
			if (isset($rest->response->body->Error, $rest->response->body->Error->Code,
			$rest->response->body->Error->Message)) {
				$rest->response->error = array(
					'code' => (string)$rest->response->body->Error->Code,
					'message' => (string)$rest->response->body->Error->Message
				);
				unset($rest->response->body);
			}
		}
		return $rest->response;
	}


	/**
	* Get MIME type for file
	*
	* @internal Used to get mime types
	* @param string &$file File path
	* @return string
	*/
	public static function __getMimeType(&$file) {
		$type = false;
		// Fileinfo documentation says fileinfo_open() will use the
		// MAGIC env var for the magic file
		if (extension_loaded('fileinfo') && isset($_ENV['MAGIC']) &&
		($finfo = finfo_open(FILEINFO_MIME, $_ENV['MAGIC'])) !== false) {
			if (($type = finfo_file($finfo, $file)) !== false) {
				// Remove the charset and grab the last content-type
				$type = explode(' ', str_replace('; charset=', ';charset=', $type));
				$type = array_pop($type);
				$type = explode(';', $type);
				$type = trim(array_shift($type));
			}
			finfo_close($finfo);

		// If anyone is still using mime_content_type()
		} elseif (function_exists('mime_content_type'))
			$type = trim(mime_content_type($file));

		if ($type !== false && strlen($type) > 0) return $type;

		// Otherwise do it the old fashioned way
		static $exts = array(
			'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'png' => 'image/png',
			'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'ico' => 'image/x-icon',
			'swf' => 'application/x-shockwave-flash', 'pdf' => 'application/pdf',
			'zip' => 'application/zip', 'gz' => 'application/x-gzip',
			'tar' => 'application/x-tar', 'bz' => 'application/x-bzip',
			'bz2' => 'application/x-bzip2', 'txt' => 'text/plain',
			'asc' => 'text/plain', 'htm' => 'text/html', 'html' => 'text/html',
			'css' => 'text/css', 'js' => 'text/javascript',
			'xml' => 'text/xml', 'xsl' => 'application/xsl+xml',
			'ogg' => 'application/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/x-wav',
			'avi' => 'video/x-msvideo', 'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg',
			'mov' => 'video/quicktime', 'flv' => 'video/x-flv', 'php' => 'text/x-php'
		);
		$ext = strtolower(pathInfo($file, PATHINFO_EXTENSION));
		return isset($exts[$ext]) ? $exts[$ext] : 'application/octet-stream';
	}


	/**
	* Generate the auth string: "AWS AccessKey:Signature"
	*
	* @internal Used by S3Request::getResponse()
	* @param string $string String to sign
	* @return string
	*/
	public static function __getSignature($string) {
		return 'AWS '.self::$__accessKey.':'.self::__getHash($string);
	}


	/**
	* Creates a HMAC-SHA1 hash
	*
	* This uses the hash extension if loaded
	*
	* @internal Used by __getSignature()
	* @param string $string String to sign
	* @return string
	*/
	private static function __getHash($string) {
		return base64_encode(extension_loaded('hash') ?
		hash_hmac('sha1', $string, self::$__secretKey, true) : pack('H*', sha1(
		(str_pad(self::$__secretKey, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) .
		pack('H*', sha1((str_pad(self::$__secretKey, 64, chr(0x00)) ^
		(str_repeat(chr(0x36), 64))) . $string)))));
	}

}

final class S3Request {
	private $verb, $bucket, $uri, $resource = '', $parameters = array(),
	$amzHeaders = array(), $headers = array(
		'Host' => '', 'Date' => '', 'Content-MD5' => '', 'Content-Type' => ''
	);
	public $fp = false, $size = 0, $data = false, $response;


	/**
	* Constructor
	*
	* @param string $verb Verb
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @return mixed
	*/
	function __construct($verb, $bucket = '', $uri = '', $defaultHost = 's3.amazonaws.com') {
		$this->verb = $verb;
		$this->bucket = strtolower($bucket);
		$this->uri = $uri !== '' ? '/'.str_replace('%2F', '/', rawurlencode($uri)) : '/';

		if ($this->bucket !== '') {
			$this->headers['Host'] = $this->bucket.'.'.$defaultHost;
			$this->resource = '/'.$this->bucket.$this->uri;
		} else {
			$this->headers['Host'] = $defaultHost;
			//$this->resource = strlen($this->uri) > 1 ? '/'.$this->bucket.$this->uri : $this->uri;
			$this->resource = $this->uri;
		}
		$this->headers['Date'] = gmdate('D, d M Y H:i:s T');

		$this->response = new STDClass;
		$this->response->error = false;
	}


	/**
	* Set request parameter
	*
	* @param string $key Key
	* @param string $value Value
	* @return void
	*/
	public function setParameter($key, $value) {
		$this->parameters[$key] = $value;
	}


	/**
	* Set request header
	*
	* @param string $key Key
	* @param string $value Value
	* @return void
	*/
	public function setHeader($key, $value) {
		$this->headers[$key] = $value;
	}


	/**
	* Set x-amz-meta-* header
	*
	* @param string $key Key
	* @param string $value Value
	* @return void
	*/
	public function setAmzHeader($key, $value) {
		$this->amzHeaders[$key] = $value;
	}


	/**
	* Get the S3 response
	*
	* @return object | false
	*/
	public function getResponse() {
		$query = '';
		if (sizeof($this->parameters) > 0) {
			$query = substr($this->uri, -1) !== '?' ? '?' : '&';
			foreach ($this->parameters as $var => $value)
				if ($value == null || $value == '') $query .= $var.'&';
				// Parameters should be encoded (thanks Sean O'Dea)
				else $query .= $var.'='.rawurlencode($value).'&';
			$query = substr($query, 0, -1);
			$this->uri .= $query;

			if (array_key_exists('acl', $this->parameters) ||
			array_key_exists('location', $this->parameters) ||
			array_key_exists('torrent', $this->parameters) ||
			array_key_exists('logging', $this->parameters))
				$this->resource .= $query;
		}
		$url = ((S3::$useSSL && extension_loaded('openssl')) ?
		'https://':'http://').$this->headers['Host'].$this->uri;
		//var_dump($this->bucket, $this->uri, $this->resource, $url);

		// Basic setup
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_USERAGENT, 'S3/php');

		if (S3::$useSSL) {
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 1);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		}

		curl_setopt($curl, CURLOPT_URL, $url);

		// Headers
		$headers = array(); $amz = array();
		foreach ($this->amzHeaders as $header => $value)
			if (strlen($value) > 0) $headers[] = $header.': '.$value;
		foreach ($this->headers as $header => $value)
			if (strlen($value) > 0) $headers[] = $header.': '.$value;

		// Collect AMZ headers for signature
		foreach ($this->amzHeaders as $header => $value)
			if (strlen($value) > 0) $amz[] = strtolower($header).':'.$value;

		// AMZ headers must be sorted
		if (sizeof($amz) > 0) {
			sort($amz);
			$amz = "\n".implode("\n", $amz);
		} else $amz = '';

		// Authorization string (CloudFront stringToSign should only contain a date)
		$headers[] = 'Authorization: ' . S3::__getSignature(
			$this->headers['Host'] == 'cloudfront.amazonaws.com' ? $this->headers['Date'] :
			$this->verb."\n".$this->headers['Content-MD5']."\n".
			$this->headers['Content-Type']."\n".$this->headers['Date'].$amz."\n".$this->resource
		);

		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
		curl_setopt($curl, CURLOPT_WRITEFUNCTION, array(&$this, '__responseWriteCallback'));
		curl_setopt($curl, CURLOPT_HEADERFUNCTION, array(&$this, '__responseHeaderCallback'));
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

		// Request types
		switch ($this->verb) {
			case 'GET': break;
			case 'PUT': case 'POST': // POST only used for CloudFront
				if ($this->fp !== false) {
					curl_setopt($curl, CURLOPT_PUT, true);
					curl_setopt($curl, CURLOPT_INFILE, $this->fp);
					if ($this->size >= 0)
						curl_setopt($curl, CURLOPT_INFILESIZE, $this->size);
				} elseif ($this->data !== false) {
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->verb);
					curl_setopt($curl, CURLOPT_POSTFIELDS, $this->data);
					if ($this->size >= 0)
						curl_setopt($curl, CURLOPT_BUFFERSIZE, $this->size);
				} else
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->verb);
			break;
			case 'HEAD':
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'HEAD');
				curl_setopt($curl, CURLOPT_NOBODY, true);
			break;
			case 'DELETE':
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
			break;
			default: break;
		}

		// Execute, grab errors
		if (curl_exec($curl))
			$this->response->code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		else
			$this->response->error = array(
				'code' => curl_errno($curl),
				'message' => curl_error($curl),
				'resource' => $this->resource
			);

		@curl_close($curl);

		// Parse body into XML
		if ($this->response->error === false && isset($this->response->headers['type']) &&
		$this->response->headers['type'] == 'application/xml' && isset($this->response->body)) {
			$this->response->body = simplexml_load_string($this->response->body);

			// Grab S3 errors
			if (!in_array($this->response->code, array(200, 204)) &&
			isset($this->response->body->Code, $this->response->body->Message)) {
				$this->response->error = array(
					'code' => (string)$this->response->body->Code,
					'message' => (string)$this->response->body->Message
				);
				if (isset($this->response->body->Resource))
					$this->response->error['resource'] = (string)$this->response->body->Resource;
				unset($this->response->body);
			}
		}

		// Clean up file resources
		if ($this->fp !== false && is_resource($this->fp)) fclose($this->fp);

		return $this->response;
	}

	/**
	* CURL write callback
	*
	* @param resource &$curl CURL resource
	* @param string &$data Data
	* @return integer
	*/
	private function __responseWriteCallback(&$curl, &$data) {
		if ($this->response->code == 200 && $this->fp !== false)
			return fwrite($this->fp, $data);
		else
			$this->response->body .= $data;
		return strlen($data);
	}


	/**
	* CURL header callback
	*
	* @param resource &$curl CURL resource
	* @param string &$data Data
	* @return integer
	*/
	private function __responseHeaderCallback(&$curl, &$data) {
		if (($strlen = strlen($data)) <= 2) return $strlen;
		if (substr($data, 0, 4) == 'HTTP')
			$this->response->code = (int)substr($data, 9, 3);
		else {
			list($header, $value) = explode(': ', trim($data), 2);
			if ($header == 'Last-Modified')
				$this->response->headers['time'] = strtotime($value);
			elseif ($header == 'Content-Length')
				$this->response->headers['size'] = (int)$value;
			elseif ($header == 'Content-Type')
				$this->response->headers['type'] = $value;
			elseif ($header == 'ETag')
				$this->response->headers['hash'] = $value{0} == '"' ? substr($value, 1, -1) : $value;
			elseif (preg_match('/^x-amz-meta-.*$/', $header))
				$this->response->headers[$header] = is_numeric($value) ? (int)$value : $value;
		}
		return $strlen;
	}

}

/****************************************************************************************************/
/*          Error handling functions
/****************************************************************************************************/

// write to system log
function writeSysLog($m,$n){
    exec('/sbin/write_log "'.str_replace('\'','',$m).'" '.$n);
}

function exception_handler($exception) {
	global $AmazonS3RR;
	global $copyFail;
	if($AmazonS3RR!='') $AmazonS3RR->setConf($AmazonS3RR->scheduleName,'Status',STATUS_COPY_FAIL);
	writeSysLog("[Amazon S3] Exception: ".$exception->getMessage(), 1);
	$copyFail[] = "[Amazon S3] Exception: ".$exception->getMessage();
}


function myErrorHandler($errno, $errstr, $errfile, $errline)
{
    global $copyFail;
    switch ($errno) {
        case E_USER_ERROR:
            writeSysLog("[Amazon S3] User Error: [$errno] $errstr on line $errline", 1);
            $copyFail[] = "[Amazon S3] User Error: [$errno] $errstr on line $errline";
            break;
		
        case E_USER_NOTICE:
            writeSysLog($errstr, 1);
            $copyFail[] = $errstr;
			break;
			
        case E_USER_WARNING:	
		case E_NOTICE:
		case E_WARNING:
            break;

        default:
            writeSysLog("[Amazon S3] Error: [$errno] $errstr on line $errline", 1);
            // writeSysLog("[Amazon S3] Error: testtest", 1);
            $copyFail[] = "[Amazon S3] Error: [$errno] $errstr on line $errline";
            break;
    }
    return false;
}
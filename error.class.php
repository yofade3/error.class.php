<?php
//
//  error.class.php
//  LandingPage
//
//  Created by Fabian Albrecht on 2016-05-09.
//  Copyright 2016 Fabian Albrecht. All rights reserved.
//

namespace falbrecht;


class error {
	
	const STD_ERROR_LOG_PATH = __DIR__."/error.log";
	
	// Error Message Stack
	private $errorStack;
	// Couldn't updated ones
	private $failedErrorStack;
	// Error loglevel. 0 = none, 1 = moderat (standart), 2 = hard
	private $logLevel;
	// Logfile path to save the stack
	private $logFile;
	// More options array
	private $options;
	
	private $client;
	
	private $DateTime;
	
	
	
	
	
	
	public function __construct( $logLevel = 1, $logFile = self::STD_ERROR_LOG_PATH, $options = NULL ) 
	{
		$today = new \DateTime('now');
		
		if( is_string($logFile) )
			$options['logfile'] = $logFile;
		else
			$options['logfile'] = self::STD_ERROR_LOG_PATH;
		
		if( $options ) 
			foreach( $options as $key => $value )
				$this->options[$key] = $value;
		if( $this->options['mysql'] == $this->options['file'] AND !is_string($logFile) )
			$this->options['file'] = false;
		
		$this->errorStack 		= array();
		$this->failedErrorStack = array();
		$this->logLevel 		= $logLevel;
		$this->client 			= array($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
		$this->DateTime			= $today->format('Y-m-d H:i:s');
	
		
		if( $this->options['mysql'] AND ( is_array($logFile) AND count($logFile)>3 ) )
		{
			if( count($logFile) == 4 ) $logFile[] = 'falbrecht_error'; else $logFile[4] = filter_var($logFile[4], FILTER_SANITIZE_STRING);
			$this->logFile 		= 	( 	
										$this->initDatabase( $logFile ) > 0 ? 
										$this->logFile : 
										$this->reInit( array( 'mysql'=>false,'file'=>true, 'logfile'=>$options['logfile'] ) )
									);
		} 
		if( $this->options['file'] )
		{
			$this->logFile 		= ( ( file_exists($this->options['logfile']) OR touch($this->options['logfile']) ) ? fopen($this->options['logfile'], 'a') : __DIR__."/error.log" );
		}
		
		
		return $this;
	}
	
	public function __destruct() 
	{
		if( $this->options['mysql'] ) 
		{
			if( !is_null($this->logFile['prepared']['insert']) AND is_object($this->logFile['prepared']['insert']) )
				$this->logFile['prepared']['insert']->close();
			
			if( !is_null($this->logFile['link']) AND is_object($this->logFile['link']) )
				$this->logFile['link']->close();
			
		} elseif( $this->options['file'] ) 
		{
			fclose($this->logFile);
		}
		
		
		return true;
	}
	
	
	
	
	
	
	
	
	
	public function reInit( $options = NULL ) 
	{
		$this->options 			= $options;
		
	
		if( !empty($options['mysql']) AND ( is_array($options['credentials']) AND count($options['credentials'])==5 ) )
			$this->logFile 		= $this->initDatabase( $options['credentials'] );
		elseif( !empty($options['file']) AND $options['logfile'] )
			$this->logFile 		= ( ( file_exists($options['logfile']) OR touch($options['logfile']) ) ? fopen($options['logfile'], 'a') : false );
		
		
		
		$this->tryFailedErrorStack();
		
		return $this;
	}
	
	
	
	
	
	
	public function add( $errno, $errmsg = '', $client = '', $dateTime = '' ) 
	{
		if( empty($client) ) $client = $this->client;
		if( empty($dateTime) ) $dateTime = $this->DateTime;
		
		
		if( $errno == 9999 ) 
		{
			$this->failedErrorStack[] = $lastError = ( count($this->errorStack)-1 );
			
			$i = 1;
			while( $this->errorStack[ ($lastError-$i) ][0] === 9999 ) 
			{
				if( $i == 4 )
					return -1;
				$i++;
			}
		}
		
		
		$array = $this->errorStack[] = array($errno, $errmsg, $client, $dateTime);

		if( $this->options['mysql'] )
			return ( $this->addToDatabase( $array ) ? false : -1 );
		elseif( $this->options['file'] )
			return ( $this->addToLogFile( $array ) ? false : -1 );
		
		
	}
	
	
	
	
	
	
	
	public function count() 
	{
		return count($this->errorStack);
	}
	
	
	
	
	public function getMessagesAsArray() 
	{
		$notSaved = array();
		
		foreach( $this->failedErrorStack as $key=>$id )
			$notSaved[] = copy($this->errorStack[$id]);
		
		
		return array('all'=>$this->errorStack, 'notSaved'=>$notSaved);
	}
	
	
	
	
	public function debugMe() 
	{
		var_dump($this);
	}
	
	
	
	
	

	
	
	
	protected function initDatabase( $credentials ) 
	{
		$mysqli = new \mysqli( $credentials[0], $credentials[1], $credentials[2], $credentials[3] );
		
		if( $mysqli->connect_errno )
			return $this->add(10, $mysqli->connect_error, NULL, $this->DateTime);
		
		if(! ($mysqli->query("SELECT `datetime`,`errno`,`errmsg`,`client` FROM `".$credentials[4]."` LIMIT 1")) ) 
		{
			$createTable = $mysqli->query(
				"CREATE TABLE `".$credentials[4]."` (
  			  	`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  			  	`datetime` datetime DEFAULT NULL,
  			  	`errno` int(11) DEFAULT NULL,
  			  	`errmsg` tinytext,
  			  	`client` varchar(255) DEFAULT NULL,
  			  	PRIMARY KEY (`id`)
				) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=latin1");
			if( $mysqli->errno )
				return $this->add(12, $mysqli->error, NULL, $this->DateTime);
			$mysqli->query("INSERT INTO `".$credentials[4]."` (`datetime`,`errno`,`errmsg`,`client`) VALUES ('".$this->DateTime."',0,'Installed table successfully!','script message')");
		}
		
		
		$prepare['insert'] = $mysqli->prepare("INSERT INTO `".$credentials[4]."` (`datetime`, `errno`,`errmsg`,`client`) VALUES (?, ?, ?, ?)");
		
		
		
		return $this->logFile = array( 'link' => $mysqli, 'prepared' => $prepare );
	}
	
	
	
	
	
	
	protected function addToLogFile( $error ) 
	{
		if( $error[0] < $this->logLevel )
			return false;
		
		if(! $error[3] )
			$error[3] = $this->DateTime;
		
		$serialized = serialize( array( $error[3], $error[0], $error[1], $error[2] ) );
		
		$wrote=fwrite($this->logFile, serialize( array( $error[3], $error[0], $error[1], $error[2] ) )."\n", 8400 );
		
		if( $wrote )  
			return true;
		else {
			$this->failedErrorStack[] = $lastError = ( count($this->errorStack)-1 );
			return $this->add(9999, "Couldn't write to file. Wrote ".$wrote." bytes"); 
		}
			
			
	}
	
	
	
	
	
	
	protected function addToDatabase( $error ) 
	{
		if( $error[0] < $this->logLevel )
			return false;
		if( is_null($this->logFile['prepared']['insert']) OR !$this->logFile['prepared']['insert'] ) {
			$this->failedErrorStack[] = $lastError = ( count($this->errorStack)-1 );
			return false;
		}
			
		
		$today = ( $error[3] ? $error[3] : $this->DateTime );
		$errno = $error[0];
		$errmsg = $error[1];
		$client = serialize($error[2]);		
		
		$this->logFile['prepared']['insert']->bind_param("ssss", $this->DateTime, $errno, $errmsg, $client );
		
		
		
		return ($this->logFile['prepared']['insert']->execute() ? true : $this->add(9999, $this->logFile['link']->error) );
	}
	
	
	
	
	
	
	private function tryFailedErrorStack() 
	{
		if( count($this->failedErrorStack) == 0 )
			return true;
		
		
		$return = 1;
		foreach( $this->failedErrorStack as $key => $errorId ) {
			$res = $this->add( 
						$this->errorStack[$errorId][0], 
						$this->errorStack[$errorId][1], 
						$this->errorStack[$errorId][2], 
						$this->errorStack[$errorId][3] 
					);
			if( !($res === -1) ) {
				unset($this->failedErrorStack[$key]);
				unset($this->errorStack[$key]);
			} else { 
				$return--;
			}
		}
		
		
		
		
		return $return;
	}
	
	
}
?>
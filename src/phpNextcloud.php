<?php

//Declaring namespace
namespace LaswitchTech\phpNextcloud;

//Import phpConfigurator class into the global namespace
use LaswitchTech\phpConfigurator\phpConfigurator;

//Import phpLogger class into the global namespace
use LaswitchTech\phpLogger\phpLogger;

//Import Object classes into the global namespace
use LaswitchTech\phpNextcloud\Objects\File;
use LaswitchTech\phpNextcloud\Objects\Calendar;
use LaswitchTech\phpNextcloud\Objects\Contact;

//Import Exception class into the global namespace
use \Exception;

//Import SimpleXMLElement class into the global namespace
use \SimpleXMLElement;

class phpNextcloud {

	// Nextcloud Configurations
	private $URL = null; // Server URL
	private $Username = null; // Server Username
	private $Password = null; // Server Password
	private $Path = null; // Server Default Upload Directory

	// Objects
	public $File = null;
	public $Calendar = null;
	public $Contact = null;

	// Cache
	private $Cache = null;

	// Logger
	private $Logger = null;

	// Configurator
	private $Configurator = null;

	/**
	 * Create a new phpNextcloud instance.
	 *
	 * @param  string|null  $field
	 * @return void
	 * @throws Exception
	 */
	public function __construct($url = null, $username = null, $password = null, $path = null){

		// Initialize Configurator
		$this->Configurator = new phpConfigurator('nextcloud');
		$this->Configurator->add('logger');

		// Initiate phpLogger
		$this->Logger = new phpLogger('nextcloud');

		// Retrieve Nextcloud Settings
		$this->URL = $this->Configurator->get('nextcloud', 'url') ?: $this->URL;
		$this->Username = $this->Configurator->get('nextcloud', 'username') ?: $this->Username;
		$this->Password = $this->Configurator->get('nextcloud', 'password') ?: $this->Password;
		$this->Path = $this->Configurator->get('nextcloud', 'path') ?: $this->Path;

		// Override Nextcloud Settings
		$this->URL = $url ?: $this->URL;
		$this->Username = $username ?: $this->Username;
		$this->Password = $password ?: $this->Password;
		$this->Path = $path ?: $this->Path;

		// Validate Nextcloud Settings
		if(!$this->URL){
			throw new Exception("Nextcloud URL is not set.");
		}
		if(!$this->Username){
			throw new Exception("Nextcloud Username is not set.");
		}
		if(!$this->Password){
			throw new Exception("Nextcloud Password is not set.");
		}

		// Initialize Object Classes
		$this->File = new File($this->URL, $this->Username, $this->Password, $this->Path);
		$this->Calendar = new Calendar($this->URL, $this->Username, $this->Password);
		$this->Contact = new Contact($this->URL, $this->Username, $this->Password);
	}

	/**
	 * Configure Library.
	 *
	 * @param  string  $option
	 * @param  bool|int  $value
	 * @return void
	 * @throws Exception
	 */
	public function config($option, $value){
		try {
			if(is_string($option)){
				switch($option){
					case"url":
					case"username":
					case"password":
						if(is_string($value)){

							// Save to Configurator
							$this->Configurator->set('nextcloud',$option, $value);
						} else{
							throw new Exception("2nd argument must be a string.");
						}
						break;
					case"level":
						if(is_int($value)){

							// Save to Configurator
							$this->Configurator->set('logger',$option, $value);
						} else{
							throw new Exception("2nd argument must be an integer.");
						}
						break;
					default:
						throw new Exception("unable to configure $option.");
						break;
				}
			} else{
				throw new Exception("1st argument must be as string.");
			}
		} catch (Exception $e) {
			$this->Logger->error('Error: '.$e->getMessage());
		}

		return $this;
	}
}

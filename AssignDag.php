<?php
/// A module to assign DAGs for multisite public surveys.
/** 
 *  AssignDag
 *  - CLASS for ... - creates .
 *    + key functions
 *  
 *  
 *  - WUSM - Washington University School of Medicine. 
 * @author Sairam Sutari
 * @version 1.0
 * @date 20180115
 * @copyright &copy; 2018 Washington University, School of Medicine, Institute for Infomatics <a href="https://redcap.wustl.edu">redcap.wustl.edu</a>
 * @todo Further documentation done to all the methods and so on should be done sometime.
 */
/**
  Reference:
    This is a hook script that allows a query string to be passed along with a Public Survey URL.
    The record will be assigned to a DAG provided as a query string.
 
    The redcap_save_record hook is used for this hook.
    redcap_save_record executes immediately on Save / Submit / Next Page.
    Otherwise if it's a multi-page survey the query string doesn't carry over.
    
    This script can be applied on a per project basis or globally to all projects.
 
    After enabling this script, the public survey URL can be provided as below:
    Append the query string &dag=YOUR_DAG_UNIQUE_NAME to the end of the URL
    i.e. https://redcapURL/surveys/index.php?s=84MY9NREFJ&dag=YOUR_DAG_UNIQUE_NAME
 
    To make the links easier to share, you may wish to use a URL shortener like goo.gl
 
    10/19/2017
 
    Andrew Carroll
    University of Michigan
 
**/
namespace WashingtonUniversity\AssignDag;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

include_once 'LoggingHandler.php';
include_once 'WebHandler.php';


use HtmlPage;
use REDCap;

class AssignDag extends AbstractExternalModule
{
	use WebHandler;
	use LoggingHandler;

	private $projectTitle;

	// system config settings
	private $debug_mode_log;
	
	// project config settings
	private $debug_mode_project;
	
	CONST MODULE_VERSION = '1.1';
	CONST PROJECT_NAME = 'AssignDag';

	// **********************************************************************	
	// **********************************************************************	
	// **********************************************************************	

	/**
	 * - set up our defaults.
	 */
	function __construct($projectId = null)
	{
		parent::__construct();
		
		$this->version = self::MODULE_VERSION;
		$this->projectId = null;
		
		$this->debug_mode_project = false;
		$this->debug_mode_log = $this->getSystemSetting('debug_mode_log');
		
		$this->projectTitle = self::PROJECT_NAME;

		// load project level settings if we are in a project
		if ($projectId !== null) {
			
			$this->projectId = $projectId;
			$this->debug_mode_project = $this->getProjectSetting('debug_mode_project');
		} else {
			; // nothing
		}
	}

	/**
	 * view - the front end part, display what we have put together.
	 */
	public function viewHtml($msg = 'view', $flag = '')
	{
		$HtmlPage = new HtmlPage(); 

		// html header
		if ($flag == 'project') {
			$HtmlPage->ProjectHeader();
		} else {   // system
			$HtmlPage->setPageTitle($this->projectName);
			$HtmlPage->PrintHeaderExt();
		}
		
	  echo $msg;
		
		// html footer
		if ($flag == 'project') {
			$HtmlPage->ProjectFooter();
		} else {   // system
			$HtmlPage->PrintFooterExt();
		}
	}

	/**
	 * loadSystemConfig - set up our system configs.
	 */
	public function loadSystemConfig()
	{
		$this->debug_mode = $this->getSystemSetting('debug_mode');
	}

	/**
	 * loadProjectConfig - set up our project configs.
	 */
	public function loadProjectConfig()
	{
		$this->debug_mode_project = $this->getProjectSetting('debug_mode_project');
	}

	/**
	 * loadProjectConfigDefaults - set up our project defaults.
	 */
	public function loadProjectConfigDefaults()
	{
		$this->debug_mode_project = 0;
	}

	/**
	 * loadConfig - set up configs.
	 */
	public function loadConfig($flag = 'system')
	{
		// always load system level settings
		$this->loadSystemConfig();

		// if we are a project load the project level settings
		if ($flag == 'project') {
			$this->loadProjectConfig();
		} else {   // project defaults
			$this->loadProjectConfigDefaults();
		}
	}

	/**
	 * redcap_save_record - .
	 */
	public function redcap_save_record($projectId, $recordId, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
	{
		if($this->debugLog)
		{
			$msg .= 'redcap_save_record PID: ';
			$msg .= $projectId;
			$msg .= ' ';
			$msg .= 'REC: ';
			$msg .= $recordId;
			
			$this->debugLog($msg);
		}

		$this->assignDAG($projectId, $recordId);
	}

	/**
	 * parseOutUrlInfo - peel out the dat bits.
	 */
	public function parseOutUrlInfo()
	{
		// Select the query portion of the referring URL
		$referrer_query = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY);
		 
		// Parse the query string out into a parameter => value array
		parse_str($referrer_query, $parameter_array);
		 
		// Extract the value for the dag parameter
		$provided_dag = $parameter_array['dag'];
		 
		return $provided_dag;
	}
	
	/**
	 * assignDAG - DAG handling.    & GROUP ID 
	 */
	public function assignDAG($project_id, $record)
	{
		$nl = "\n";
		$br = '<br>';
		$msg = '';
		
		if ($this->debug_mode_log || $this->debug_mode_project) 
		{
			$msg .= 'PROJECT: ';
			$msg .= $project_id;
			$msg .= $br . $nl;
			$msg .= '';
			$msg .= 'record: ';
			$msg .= $record;
			$msg .= $br . $nl;

			$this->debugLog($msg);
		}
		
		// Get the list of DAG unique group names used in this project
		$groups = REDCap::getGroupNames(true);
		 
		// Check to see if the particular project uses data access groups.
		// If not, return false.
		if(empty($groups)) { 
			if ($this->debug_mode_log || $this->debug_mode_project) {
				$this->debugLog('NO GROUPS');
			}
			return false; 
		}
		 
		// Check to make sure the record is not currently assigned to a DAG.
		// This shouldn't come up, but just to be safe...
		// If record is already assigned to a DAG, return false
		if($group_id != null) { 
			if ($this->debug_mode_log || $this->debug_mode_project) {
				$this->debugLog('DAG is currently assigned?');
			}
			return false; 
		}
		 
		$provided_dag = $this->parseOutUrlInfo();
		
		if ($this->debug_mode_log || $this->debug_mode_project) {
			$msg = '';
			$msg .= 'provided_dag: ';
			$msg .= $provided_dag;
			$this->debugLog($msg);
		}

		// Check to see if a DAG was provided in the query string
		// If not, return false
		if($provided_dag == null) { 
			return false; 
		}
		 
		// Check to see if the provided DAG is valid
		// If not, return false
		if(!in_array($provided_dag, $groups)) { 
			if ($this->debug_mode_log || $this->debug_mode_project) {
				$this->debugLog(print_r($groups, true));
				$this->debugLog('given DAG: ['. $provided_dag . ']');
				$this->debugLog('DAG NOT IN GROUPS LIST');
			}
			return false; 
		}
		 
		// Get the current record ID field and record
		// This makes it super easy to save the record back in
		$record_id_field = REDCap::getRecordIdField();
		$recordData = REDCap::getData('array', $record, $record_id_field);
		
		// Save the record to the provided DAG
		REDCap::saveData('array', $recordData, 'normal', 'YMD', 'flat', $provided_dag);
		 
		// Finally return true
		return true;		
	}
 

} // *** end class

?>

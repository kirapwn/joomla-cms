<?php
/**
* @version		$Id$
* @package		Joomla.Framework
* @subpackage	Database
* @copyright	Copyright (C) 2005 - 2009 Open Source Matters, Inc. All rights reserved.
* @license		GNU General Public License, see LICENSE.php
*/

// No direct access
defined('JPATH_BASE') or die();

/**
 * Database connector class
 *
 * @abstract
 * @package		Joomla.Framework
 * @subpackage	Database
 * @since		1.0
 */
abstract class JDatabase extends JClass
{
	/**
	 * The database driver name
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * The query sql string
	 *
	 * @var string
	 **/
	protected $_sql			= '';

	/**
	 * The database error number
	 *
	 * @var int
	 **/
	protected $_errorNum		= 0;

	/**
	 * The database error message
	 *
	 * @var string
	 */
	protected $_errorMsg		= '';

	/**
	 * The prefix used on all database tables
	 *
	 * @var string
	 */
	protected $_table_prefix	= '';

	/**
	 * The connector resource
	 *
	 * @var resource
	 */
	protected $_resource		= '';

	/**
	 * The last query cursor
	 *
	 * @var resource
	 */
	protected $_cursor		= null;

	/**
	 * Debug option
	 *
	 * @var boolean
	 */
	protected $_debug			= 0;

	/**
	 * The limit for the query
	 *
	 * @var int
	 */
	protected $_limit			= 0;

	/**
	 * The for offset for the limit
	 *
	 * @var int
	 */
	protected $_offset		= 0;

	/**
	 * The number of queries performed by the object instance
	 *
	 * @var int
	 */
	protected $_ticker		= 0;

	/**
	 * A log of queries
	 *
	 * @var array
	 */
	protected $_log			= null;

	/**
	 * The null/zero date string
	 *
	 * @var string
	 */
	protected $_nullDate		= null;

	/**
	 * Quote for named objects
	 *
	 * @var string
	 */
	protected $_nameQuote		= null;

	/**
	 * UTF-8 support
	 *
	 * @var boolean
	 * @since	1.5
	 */
	protected $_utf			= 0;

	/**
	 * The fields that are to be quote
	 *
	 * @var array
	 * @since	1.5
	 */
	protected $_quoted	= null;

	/**
	 *  Legacy compatibility
	 *
	 * @var bool
	 * @since	1.5
	 */
	protected $_hasQuoted	= null;

	/**
	* Database object constructor
	*
	* @access	public
	* @param	array	List of options used to configure the connection
	* @since	1.5
	*/
	protected function __construct($options)
	{
		$prefix = array_key_exists('prefix', $options) ? $options['prefix'] : 'jos_';

		// Determine utf-8 support
		$this->_utf = $this->hasUTF();

		//Set charactersets (needed for MySQL 4.1.2+)
		if ($this->_utf){
			$this->setUTF();
		}

		$this->_table_prefix	= $prefix;
		$this->_ticker			= 0;
		$this->_errorNum		= 0;
		$this->_log				= array();
		$this->_quoted			= array();
		$this->_hasQuoted		= false;
	}

	/**
	 * Returns a reference to the global Database object, only creating it
	 * if it doesn't already exist.
	 *
	 * The 'driver' entry in the parameters array specifies the database driver
	 * to be used (defaults to 'mysql' if omitted). All other parameters are
	 * database driver dependent.
	 *
	 * @param array Parameters to be passed to the database driver
	 * @return JDatabase A database object
	 * @since 1.5
	*/
	public static function &getInstance($options = array())
	{
		static $instances;

		if (!isset($instances)) {
			$instances = array();
		}

		$signature = serialize($options);

		if (empty($instances[$signature]))
		{
			$driver		= array_key_exists('driver', $options) 		? $options['driver']	: 'mysql';
			$select		= array_key_exists('select', $options)		? $options['select']	: true;
			$database	= array_key_exists('database', $options)	? $options['database']	: null;

			$driver = preg_replace('/[^A-Z0-9_\.-]/i', '', $driver);
			$path	= dirname(__FILE__).DS.'database'.DS.$driver.'.php';

			if (file_exists($path)) {
				require_once $path;
			} else {
				JError::setErrorHandling(E_ERROR, 'die'); //force error type to die
				$error = JError::raiseError(500, JTEXT::_('Unable to load Database Driver:') .$driver);
				return $error;
			}

			$adapter	= 'JDatabase'.$driver;
			$instance	= new $adapter($options);

			if ($error = $instance->getErrorMsg())
			{
				JError::setErrorHandling(E_ERROR, 'ignore'); //force error type to die
				$error = JError::raiseError(500, JTEXT::_('Unable to connect to the database:') .$error);
				return $error;
			}


			$instances[$signature] = & $instance;
		}

		return $instances[$signature];
	}

	/**
	 * Database object destructor
	 *
	 * @abstract
	 * @access private
	 * @return boolean
	 * @since 1.5
	 */
	public function __destruct()
	{
		return true;
	}

	/**
	 * Get the database connectors
	 *
	 * @access public
	 * @return array An array of available session handlers
	 */
	public function getConnectors()
	{
		jimport('joomla.filesystem.folder');
		$handlers = JFolder::files(dirname(__FILE__).DS.'database', '.php$');

		$names = array();
		foreach($handlers as $handler)
		{
			$name = substr($handler, 0, strrpos($handler, '.'));
			$class = 'JDatabase'.ucfirst($name);

			if (!class_exists($class)) {
				require_once dirname(__FILE__).DS.'database'.DS.$name.'.php';
			}

			if (call_user_func_array(array(trim($class), 'test'), null)) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * Test to see if the MySQLi connector is available
	 *
	 * @static
	 * @access public
	 * @return boolean  True on success, false otherwise.
	 */
	abstract public static function test();

	/**
	 * Determines if the connection to the server is active.
	 *
	 * @access	public
	 * @return	boolean
	 * @since	1.5
	 */
	abstract public function connected();

	/**
	 * Determines UTF support
	 *
	 * @abstract
	 * @access public
	 * @return boolean
	 * @since 1.5
	 */
	abstract public function hasUTF();

	/**
	 * Custom settings for UTF support
	 *
	 * @abstract
	 * @access public
	 * @since 1.5
	 */
	abstract public function setUTF();

	/**
	 * Adds a field or array of field names to the list that are to be quoted
	 *
	 * @access public
	 * @param mixed Field name or array of names
	 * @since 1.5
	 */
	public function addQuoted($quoted)
	{
		if (is_string($quoted)) {
			$this->_quoted[] = $quoted;
		} else {
			$this->_quoted = array_merge($this->_quoted, (array)$quoted);
		}
		$this->_hasQuoted = true;
	}

	/**
	 * Splits a string of queries into an array of individual queries
	 *
	 * @access public
	 * @param string The queries to split
	 * @return array queries
	 */
	public function splitSql($queries)
	{
		$start = 0;
		$open = false;
		$open_char = '';
		$end = strlen($queries);
		$query_split = array();
		for($i=0;$i<$end;$i++) {
			$current = substr($queries,$i,1);
			if (($current == '"' || $current == '\'')) {
				$n = 2;
				while(substr($queries,$i - $n + 1, 1) == '\\' && $n < $i) {
					$n ++;
				}
				if ($n%2==0) {
					if ($open) {
						if ($current == $open_char) {
							$open = false;
							$open_char = '';
						}
					} else {
						$open = true;
						$open_char = $current;
					}
				}
			}
			if (($current == ';' && !$open)|| $i == $end - 1) {
				$query_split[] = substr($queries, $start, ($i - $start + 1));
				$start = $i + 1;
			}
		}

		return $query_split;
	}



	/**
	 * Checks if field name needs to be quoted
	 *
	 * @access public
	 * @param string The field name
	 * @return bool
	 */
	public function isQuoted($fieldName)
	{
		if ($this->_hasQuoted) {
			return in_array($fieldName, $this->_quoted);
		} else {
			return true;
		}
	}

	/**
	 * Sets the debug level on or off
	 *
	 * @access public
	 * @param int 0 = off, 1 = on
	 */
	public function debug($level) {
		$this->_debug = intval($level);
	}

	/**
	 * Get the database UTF-8 support
	 *
	 * @access public
	 * @return boolean
	 * @since 1.5
	 */
	public function getUTFSupport() {
		return $this->_utf;
	}

	/**
	 * Get the error number
	 *
	 * @access public
	 * @return int The error number for the most recent query
	 */
	public function getErrorNum() {
		return $this->_errorNum;
	}


	/**
	 * Get the error message
	 *
	 * @access public
	 * @return string The error message for the most recent query
	 */
	public function getErrorMsg($escaped = false)
	{
		if ($escaped) {
			return addslashes($this->_errorMsg);
		} else {
			return $this->_errorMsg;
		}
	}

	/**
	 * Get a database escaped string
	 *
	 * @param	string	The string to be escaped
	 * @param	boolean	Optional parameter to provide extra escaping
	 * @return	string
	 * @access	public
	 * @abstract
	 */
	abstract public function getEscaped($text, $extra = false);

	/**
	 * Get a database error log
	 *
	 * @access public
	 * @return array
	 */
	public function getLog()
	{
		return $this->_log;
	}

	/**
	 * Get the total number of queries made
	 *
	 * @access public
	 * @return array
	 */
	public function getTicker()
	{
		return $this->_ticker;
	}

	/**
	 * Quote an identifier name (field, table, etc)
	 *
	 * @access	public
	 * @param	string	The name
	 * @return	string	The quoted name
	 */
	public function nameQuote($s)
	{
		// Only quote if the name is not using dot-notation
		if (strpos('.', $s) === false)
		{
			$q = $this->_nameQuote;
			if (strlen($q) == 1) {
				return $q . $s . $q;
			} else {
				return $q{0} . $s . $q{1};
			}
		}
		else {
			return $s;
		}
	}
	/**
	 * Get the database table prefix
	 *
	 * @access public
	 * @return string The database prefix
	 */
	public function getPrefix()
	{
		return $this->_table_prefix;
	}

	/**
	 * Get the database null date
	 *
	 * @access public
	 * @return string Quoted null/zero date string
	 */
	public function getNullDate()
	{
		return $this->_nullDate;
	}

	/**
	 * Sets the SQL query string for later execution.
	 *
	 * This function replaces a string identifier <var>$prefix</var> with the
	 * string held is the <var>_table_prefix</var> class variable.
	 *
	 * @access public
	 * @param string The SQL query
	 * @param string The offset to start selection
	 * @param string The number of results to return
	 * @param string The common table prefix
	 */
	public function setQuery($sql, $offset = 0, $limit = 0, $prefix='#__')
	{
		$this->_sql		= $this->replacePrefix((string)$sql, $prefix);
		$this->_limit	= (int) $limit;
		$this->_offset	= (int) $offset;
	}

	/**
	 * This function replaces a string identifier <var>$prefix</var> with the
	 * string held is the <var>_table_prefix</var> class variable.
	 *
	 * @access public
	 * @param string The SQL query
	 * @param string The common table prefix
	 */
	public function replacePrefix($sql, $prefix='#__')
	{
		$sql = trim($sql);

		$escaped = false;
		$quoteChar = '';

		$n = strlen($sql);

		$startPos = 0;
		$literal = '';
		while ($startPos < $n) {
			$ip = strpos($sql, $prefix, $startPos);
			if ($ip === false) {
				break;
			}

			$j = strpos($sql, "'", $startPos);
			$k = strpos($sql, '"', $startPos);
			if (($k !== FALSE) && (($k < $j) || ($j === FALSE))) {
				$quoteChar	= '"';
				$j			= $k;
			} else {
				$quoteChar	= "'";
			}

			if ($j === false) {
				$j = $n;
			}

			$literal .= str_replace($prefix, $this->_table_prefix,substr($sql, $startPos, $j - $startPos));
			$startPos = $j;

			$j = $startPos + 1;

			if ($j >= $n) {
				break;
			}

			// quote comes first, find end of quote
			while (TRUE) {
				$k = strpos($sql, $quoteChar, $j);
				$escaped = false;
				if ($k === false) {
					break;
				}
				$l = $k - 1;
				while ($l >= 0 && $sql{$l} == '\\') {
					$l--;
					$escaped = !$escaped;
				}
				if ($escaped) {
					$j	= $k+1;
					continue;
				}
				break;
			}
			if ($k === FALSE) {
				// error in the query - no end quote; ignore it
				break;
			}
			$literal .= substr($sql, $startPos, $k - $startPos + 1);
			$startPos = $k+1;
		}
		if ($startPos < $n) {
			$literal .= substr($sql, $startPos, $n - $startPos);
		}
		return $literal;
	}

	/**
	 * Get the active query
	 *
	 * @access public
	 * @return string The current value of the internal SQL vairable
	 */
	public function getQuery()
	{
		return $this->_sql;
	}

	/**
	 * Execute the query
	 *
	 * @abstract
	 * @access public
	 * @return mixed A database resource if successful, FALSE if not.
	 */
	abstract public function query();

	/**
	 * Get the affected rows by the most recent query
	 *
	 * @abstract
	 * @access public
	 * @return int The number of affected rows in the previous operation
	 * @since 1.0.5
	 */
	abstract public function getAffectedRows();

	/**
	* Execute a batch query
	*
	* @abstract
	* @access public
	* @return mixed A database resource if successful, FALSE if not.
	*/
	abstract public function queryBatch($abort_on_error=true, $p_transaction_safe = false);

	/**
	 * Diagnostic function
	 *
	 * @abstract
	 * @access public
	 */
	abstract public function explain();

	/**
	 * Get the number of rows returned by the most recent query
	 *
	 * @abstract
	 * @access public
	 * @param object Database resource
	 * @return int The number of rows
	 */
	abstract public function getNumRows($cur=null);

	/**
	 * This method loads the first field of the first row returned by the query.
	 *
	 * @abstract
	 * @access public
	 * @return The value returned in the query or null if the query failed.
	 */
	abstract public function loadResult();

	/**
	 * Load an array of single field results into an array
	 *
	 * @abstract
	 */
	abstract public function loadResultArray($numinarray = 0);

	/**
	* Fetch a result row as an associative array
	*
	* @abstract
	*/
	abstract public function loadAssoc();

	/**
	 * Load a associactive list of database rows
	 *
	 * @abstract
	 * @access public
	 * @param string The field name of a primary key
	 * @return array If key is empty as sequential list of returned records.
	 */
	abstract public function loadAssocList($key='');

	/**
	 * This global function loads the first row of a query into an object
	 *
	 *
	 * @abstract
	 * @access public
	 * @param object
	 */
	abstract public function loadObject();

	/**
	* Load a list of database objects
	*
	* @abstract
	* @access public
	* @param string The field name of a primary key
	* @return array If <var>key</var> is empty as sequential list of returned records.

	* If <var>key</var> is not empty then the returned array is indexed by the value
	* the database key.  Returns <var>null</var> if the query fails.
	*/
	abstract public function loadObjectList($key='');

	/**
	 * Load the first row returned by the query
	 *
	 * @abstract
	 * @access public
	 * @return The first row of the query.
	 */
	abstract public function loadRow();

	/**
	* Load a list of database rows (numeric column indexing)
	*
	* If <var>key</var> is not empty then the returned array is indexed by the value
	* the database key.  Returns <var>null</var> if the query fails.
	*
	* @abstract
	* @access public
	* @param string The field name of a primary key
	* @return array
	*/
	abstract public function loadRowList($key='');

	/**
	 * Inserts a row into a table based on an objects properties
	 * @param	string	The name of the table
	 * @param	object	An object whose properties match table fields
	 * @param	string	The name of the primary key. If provided the object property is updated.
	 */
	abstract public function insertObject($table, &$object, $keyName = NULL);

	/**
	 * Update an object in the database
	 *
	 * @abstract
	 * @access public
	 * @param string
	 * @param object
	 * @param string
	 * @param boolean
	 */
	abstract public function updateObject($table, &$object, $keyName, $updateNulls=true);

	/**
	 * Print out an error statement
	 *
	 * @param boolean If TRUE, displays the last SQL statement sent to the database
	 * @return string A standised error message
	 */
	public function stderr($showSQL = false)
	{
		if ($this->_errorNum != 0) {
			return "DB function failed with error number $this->_errorNum"
			."<br /><font color=\"red\">$this->_errorMsg</font>"
			.($showSQL ? "<br />SQL = <pre>$this->_sql</pre>" : '');
		} else {
			return "DB function reports no errors";
		}
	}

	/**
	 * Get the ID generated from the previous INSERT operation
	 *
	 * @abstract
	 * @access public
	 * @return mixed
	 */
	abstract public function insertid();

	/**
	 * Get the database collation
	 *
	 * @abstract
	 * @access public
	 * @return string Collation in use
	 */
	abstract public function getCollation();

	/**
	 * Get the version of the database connector
	 *
	 * @abstract
	 */
	public function getVersion()
	{
		return 'Not available for this connector';
	}

	/**
	 * List tables in a database
	 *
	 * @abstract
	 * @access public
	 * @return array A list of all the tables in the database
	 */
	abstract public function getTableList();

	/**
	 * Shows the CREATE TABLE statement that creates the given tables
	 *
	 * @abstract
	 * @access	public
	 * @param 	array|string 	A table name or a list of table names
	 * @return 	array A list the create SQL for the tables
	 */
	abstract public function getTableCreate($tables);

	/**
	 * Retrieves information about the given tables
	 *
	 * @abstract
	 * @access	public
	 * @param 	array|string 	A table name or a list of table names
	 * @param	boolean			Only return field types, default true
	 * @return	array An array of fields by table
	 */
	abstract public function getTableFields($tables, $typeonly = true);

	/**
	* Get a quoted database escaped string
	*
	* @param	string	A string
	* @param	boolean	Default true to escape string, false to leave the string unchanged
	* @return	string
	* @access public
	*/
	public function quote($text, $escaped = true)
	{
		return '\''.($escaped ? $this->getEscaped($text) : $text).'\'';
	}

	/**
	 * ADODB compatability function
	 *
	 * @access	public
	 * @param	string SQL
	 * @since	1.5
	 * @deprecated
	 */
	public function GetCol($query)
	{
		$this->setQuery($query);
		return $this->loadResultArray();
	}
	/**
	 * ADODB compatability function
	 *
	 * @access	public
	 * @param	string SQL
	 * @return	object
	 * @since	1.5
	 * @deprecated
	 */
	public function Execute($query)
	{
		jimport('joomla.database.recordset');

		$query = trim($query);
		$this->setQuery($query);
		if (eregi('^select', $query)) {
			$result = $this->loadRowList();
			return new JRecordSet($result);
		} else {
			$result = $this->query();
			if ($result === false) {
				return false;
			} else {
				return new JRecordSet(array());
			}
		}
	}
	/**
	 * ADODB compatability function
	 *
	 * @access public
	 * @since 1.5
	 * @deprecated
	 */
	public function SelectLimit($query, $count, $offset=0)
	{
		jimport('joomla.database.recordset');

		$this->setQuery($query, $offset, $count);
		$result = $this->loadRowList();
		return new JRecordSet($result);
	}
	/**
	 * ADODB compatability function
	 *
	 * @access public
	 * @since 1.5
	 * @deprecated
	 */
	public function PageExecute($sql, $nrows, $page, $inputarr=false, $secs2cache=0)
	{
		jimport('joomla.database.recordset');

		$this->setQuery($sql, $page*$nrows, $nrows);
		$result = $this->loadRowList();
		return new JRecordSet($result);
	}
	
	/**
	 * ADODB compatability function
	 *
	 * @access public
	 * @param string SQL
	 * @return array
	 * @since 1.5
	 * @deprecated
	 */
	public function GetRow($query)
	{
		$this->setQuery($query);
		$result = $this->loadRowList();
		return $result[0];
	}
	/**
	 * ADODB compatability function
	 *
	 * @access public
	 * @param string SQL
	 * @return mixed
	 * @since 1.5
	 * @deprecated
	 */
	public function GetOne($query)
	{
		$this->setQuery($query);
		$result = $this->loadResult();
		return $result;
	}
	/**
	 * ADODB compatability function
	 *
	 * @since 1.5
	 * @deprecated
	 */
	public function BeginTrans()
	{
	}
	/**
	 * ADODB compatability function
	 *
	 * @since 1.5
	 * @deprecated
	 */
	public function RollbackTrans()
	{
	}
	/**
	 * ADODB compatability function
	 *
	 * @since 1.5
	 * @deprecated
	 */
	public function CommitTrans()
	{
	}
	/**
	 * ADODB compatability function
	 *
	 * @since 1.5
	 * @deprecated
	 */
	public function ErrorMsg()
	{
		return $this->getErrorMsg();
	}
	/**
	 * ADODB compatability function
	 *
	 * @since 1.5
	 * @deprecated
	 */
	public function ErrorNo()
	{
		return $this->getErrorNum();
	}
	/**
	 * ADODB compatability function
	 *
	 * @since 1.5
	 * @deprecated
	 */
	public function GenID($foo1=null, $foo2=null)
	{
		return '0';
	}
}

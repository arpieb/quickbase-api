<?php
/**
 * @file
 * 
 * Contains an API wrapper class for the QuickBase HTTP API:
 * http://www.quickbase.com/api-guide/index.html
 * 
 * Function naming conventions:
 *   CamelCase:   public method
 *   camelCase:   protected method
 *   _camelCase:  private method
 *   __xyz:       PHP magic method
 */

class QuickBaseAPI {
  // Properties passed in via constructor and/or Authenticate method
  var $user;
  var $pwd;
  var $realm;
  var $hours;
  var $apptoken;

  // Properties set on successful call to Authenticate method
  var $ticket = NULL;
  var $userid = NULL;

  // Properties set when an error occurs at the XML, cURL or API level
  var $errno = 0;
  var $errmsg = '';
  
  // Internal debugging properties
  var $debug = FALSE;

  //////////////////////////////////////////////////////////////////////
  // Construct/destruct methods
  //////////////////////////////////////////////////////////////////////
  /**
   * Constructor for class
   * 
   * @param params  Array of name-value pairs to set for automated authentication; can be overridden at any time by calling Authenticate directly with alternate values
   *                username    Username
   *                password    Password
   *                realm       Domain realm
   *                apptoken    Application token (optional)
   *                hours       Ticket lifetime (optional)
   */
  public function __construct($params) {
    if (!empty($params['username'])) {
      $this->username = $params['username'];
    }
    if (!empty($params['password'])) {
      $this->password = $params['password'];
    }
    if (!empty($params['realm'])) {
      $this->realm = $params['realm'];
    }
    if (!empty($params['apptoken'])) {
      $this->apptoken = $params['apptoken'];
    }
    if (!empty($params['hours'])) {
      $this->hours = $params['hours'];
    }
  }

  public function __destruct() {
  }

  //////////////////////////////////////////////////////////////////////
  // Internal protected methods
  //////////////////////////////////////////////////////////////////////
  /**
   * Executes an XML-based POST request against the QuickBase service and returns the results; backbone of the class
   * 
   * @param fn          QuickBase function to call
   * @param params      Array of name-value pairs for the function call
   * @param dbid        Database ID to execute the function against (optional)
   * @param parse_resp  If TRUE, attempt to parse resp as XML.  FALSE, return as-is.
   * 
   * @return FALSE on error, SimpleXMLElement on success.  Error code and message are stored on the object in the properties errno and errmsg, respectively.
   */
  protected function sendRequest($fn, $params = NULL, $dbid = NULL, $parse_resp = TRUE) {
    $resp = FALSE;

    // Construct request XML from params
    if (!$params) {
      $params = array();
    }
    if (empty($params['ticket']) && $this->ticket) {
      $params['ticket'] = $this->ticket;
    }
    if (empty($params['apptoken']) && !empty($this->apptoken)) {
      $params['apptoken'] = $this->apptoken;
    }
    $this->debugOut('Request params prior to XML construction', $params);
    $xml = new SimpleXMLElement('<qdbapi></qdbapi>');
    foreach ($params as $name => $value) {
      if (isset($value)) {
        // Check to see if this value is actually an array
        if (is_array($value)) {
          foreach ($value as $item_key => $item_value) {
            // If the nested item is an array, look for 'data' and 'attributes' keys
            if (is_array($item_value)) {
              if (isset($item_value['data'])) {
                $child = $xml->addChild($name, $item_value['data']);

                // Check for attributes, add to the element
                if (isset($item_value['attributes']) && is_array($item_value['attributes'])) {
                  foreach ($item_value['attributes'] as $attr_name => $attr_value) {
                    $child->addAttribute($attr_name, $attr_value);
                  }
                }
              }
            }

            // Otherwise, this is an item that has multiple straight-up values
            else {
              $xml->addChild($name, $item_value);
            }
          }
        }

        // Otherwise, this is a simple name-value pair
        else {
          $xml->addChild($name, $value);
        }
      }
    }
    $this->debugOut('Request XML element tree', $xml);
    $xml = $xml->asXML();
    $this->debugOut('Request XML', htmlspecialchars($xml));

    // Make sure we have valid XML before proceeding
    if (FALSE !== $xml) {
      // Construct request URL
      $realm = ($this->realm) ? $this->realm : 'www';
      $path  = ($dbid) ? "db/{$dbid}" : '';
      $url = "https://{$realm}.quickbase.com/{$path}";

      // Construct HTTP headers
      $headers = array();
      $headers[] = 'Content-Type: application/xml';
      $headers[] = 'Content-Length: ' . strlen($xml);
      $headers[] = 'QUICKBASE-ACTION: ' . $fn;

      $ch = curl_init($url);
      if (FALSE !== $ch) {
        // Set cURL options
        $options = array(
          CURLOPT_SSL_VERIFYPEER => FALSE,
          CURLOPT_RETURNTRANSFER => TRUE,
          CURLOPT_FOLLOWLOCATION => FALSE,
          CURLOPT_POST => TRUE,
          CURLOPT_HTTPHEADER => $headers,
          CURLOPT_POSTFIELDS => $xml,
        );
        curl_setopt_array($ch, $options);

        // Execute the request and handle returned data
        $resp_data = curl_exec($ch);

        // Check for "successful" cURL request
        if (FALSE !== $resp_data) {
          // Check for successful HTTP request
          $resp_info = curl_getinfo($ch);
          if (2 == ($resp_info['http_code'] / 100)) {
            // Check to see if we're supposed to parse this as XML
            if ($parse_resp) {
              try {
                $resp = new SimpleXMLElement($resp_data);

                // Check service-level error code
                if (0 != $resp->errcode) {
                  $this->setError('QuickBase API', $resp->errtext, $resp->errcode, $url);
                  $resp = FALSE;
                }
              }
              catch (Exception $e) {
                $this->setError('SimpleXML', $e->getMessage() . ': ' . $resp_data, $e->getCode(), $url);
              }
            }
            else {
              $resp = $resp_data;
            }
          }
          else {
            $this->setError('HTTP', 'Received non-2xx response code', $resp_info['http_code'], $url);
          }
        }
        else {
          $this->setError('cURL', curl_error($ch), curl_errno($ch), $url);
        }

        // Perform cleanup
        curl_close($ch);
      }
      else {
        $this->setError('cURL', 'Unable to initialize cURL interface', NULL, $url);
      }
    }
    else {
      $this->setError('SimpleXML', 'Unable to construct XML from provided parameters: ' . print_r($params, TRUE), NULL, $url);
    }

    return $resp;
  }

  /**
   * Formats error message text
   * 
   * @param comp    Component reporting error
   * @param msg     Message text
   * @param code    Error code (optional)
   * @param url     URL that generated the error (optional)
   */
   protected function setError($comp, $msg, $code = NULL, $url = NULL) {
     $this->errno = $code;
     $this->errmsg = "{$comp}: " . (($code) ? "[{$code}] " : '') . "{$msg}" . (($url) ? " [{$url}]" : '');
   }

  /**
   * Method to generate debug output
   * 
   * @param label   Label to associate with the data being dumped
   * @param data    Data to dump; objects and arrays OK
   * 
   * @return None
   */
  protected function debugOut($label, $data) {
    if ($this->debug) {
      echo "{$label}:\n<pre>\n" . print_r($data, TRUE) . "\n</pre>\n";
    }
  }

  //////////////////////////////////////////////////////////////////////
  // Public API
  //////////////////////////////////////////////////////////////////////
  /**
   * Enables debugging output; off by default
   * 
   * @param debug   TRUE to enable debug output, FALSE to disable
   * 
   * @return None
   */
  public function Debug($debug = TRUE) {
    $this->debug = $debug;
  }

  /**
   * http://www.quickbase.com/api-guide/add_field.html
   * 
   * @param label     Allows you to enter the name of the new field
   * @param type      The QuickBase field type
   * @param options   Other options, name-value pairs:
   *                  add_to_forms    Specifies whether the field you are adding should appear at the end of any form with form properties set to "Auto-Add new fields
   *                  mode            Specifies whether the field is a formula field or a lookup field
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function AddField($dbid, $label, $type, $options = array()) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'label' => $label,
        'type' => $type,
      );
      $params += $options;
      $resp = $this->sendRequest('API_AddField', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/add_record.html
   * 
   * @param dbid      Database ID to execute query against
   * @param record    Array of field values in the format:
   *                  array(
   *                    <fid|name> => <value|array('data' => DATA[, 'attributes' => array(NAME => VAL)])>
   *                  );
   * @param options   Array of name-value pairs that can override the values set during construction
   *                  disprec       Set this parameter to 1 to specify that the new record should be displayed within the QuickBase application
   *                  fform         Set this parameter to 1 if you are invoking API_AddRecord from within an HTML form that has checkboxes and want those checkboxes to set QuickBase checkbox fields
   *                  ignoreError   Set this parameter to 1 to specify that no error should be returned when a built-in field (for example, Record ID#) is written-to in an API_AddRecord call
   *                  msInUTC       Allows you to specify that QuickBase should interpret all date/time stamps passed in as milliseconds using Coordinated Universal Time (UTC) rather than using the local application time
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function AddRecord($dbid, $record, $options = array()) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array();
      foreach ($record as $fid => $value) {
        $tmp_field = array();
        // Set value up in data key
        if (is_array($value)) {
          $tmp_field = $value;
        }
        else {
          $tmp_field['data'] = $value;
        }

        // Set fid or name up in correct attribute
        $tmp_field['attributes'][is_numeric($fid) ? 'fid' : 'name'] = $fid;

        // Add to params array
        $params['field'][] = $tmp_field;
      }

      // Merge passed-in options as-is
      $params += $options;
      $resp = $this->sendRequest('API_AddRecord', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/add_replace_dbpage.html
   * 
   * @param dbid        Database ID to execute query against
   * @param pageid      Allows you to specify a new page to add or an existing page to replace
   *                    ID    Update existing page
   *                    Name  Add new page
   * @param pagetype    Specifies the type of page
   *                    1 - for XSL stylesheets or HTML pages
   *                    3 – for Exact Forms
   * @param pagebody    Contains the contents of the page you are adding
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function AddReplaceDBPage($dbid, $pageid, $pagetype, $pagebody) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'pagetype' => $pagetype,
        'pagebody' => $pagebody,
      );
      $params[is_numeric($pageid) ? 'pageid' : 'pagename'] = $pageid;
      $resp = $this->sendRequest('API_AddReplaceDBPage', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/add_user_to_role.html
   * 
   * @param dbid      Database ID to execute query against
   * @param userid    The userid of the user to be added to the access role
   * @param roleid    The ID of the access role being assigned to the user
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function AddUserToRole($dbid, $userid, $roleid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'userid' => $userid,
        'roleid' => $roleid,
      );
      $resp = $this->sendRequest('API_AddUserToRole', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/authenticate.html
   * 
   * @param params  Array of name-value pairs that can override the values set during construction
   *                username    Username
   *                password    Password
   *                realm       Domain realm
   *                apptoken    Application token (optional)
   *                hours       Ticket lifetime (optional)
   * 
   * @return Returns TRUE on success, FALSE on error
   */
  public function Authenticate($params = array()) {
    if (!$this->ticket) {
      // Copy off any passed-in overrides for values set at construction
      if (!empty($params['username'])) {
        $this->username = $params['username'];
      }
      if (!empty($params['password'])) {
        $this->password = $params['password'];
      }
      if (!empty($params['realm'])) {
        $this->realm = $params['realm'];
      }
      if (!empty($params['apptoken'])) {
        $this->apptoken = $params['apptoken'];
      }
      if (!empty($params['hours'])) {
        $this->hours = $params['hours'];
      }

      // Assemble params for call
      $params = array(
        'username' => $this->username,
        'password' => $this->password,
        'hours' => $this->hours,
      );
      $resp = $this->sendRequest('API_Authenticate', $params, 'main');
      if (FALSE !== $resp && 0 == $resp->errcode) {
        $this->ticket = $resp->ticket;
        $this->userid = $resp->userid;
      }
    }

    return ($this->ticket) ? TRUE : FALSE;
  }

  /**
   * http://www.quickbase.com/api-guide/change_record_owner.html
   * 
   * @param dbid        Database ID to execute query against
   * @param rid         The record ID. Every record in every table has a unique rid.
   * @param newowner    Specifies the user to whom you are transferring ownership:
   *                    the user's QuickBase user name
   *                    the user's email address
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function ChangeRecordOwner($dbid, $rid, $newowner) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'rid' => $rid,
        'newowner' => $newowner,
      );
      $resp = $this->sendRequest('API_ChangeRecordOwner', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/change_user_role.html
   * 
   * @param dbid        Database ID to execute query against
   * @param userid      The userid of the user to be added to the access role
   * @param roleid      The user’s current role in the application
   * @param newroleid   If this parameter is supplied but is left blank, the role is set to None
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function ChangeUserRole($dbid, $userid, $roleid, $newroleid = '') {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'userid' => $userid,
        'roleid' => $roleid,
        'newroleid' => $newroleid,
      );
      $resp = $this->sendRequest('API_ChangeUserRole', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/clone_database.html
   * 
   * @param dbid            Database ID to execute query against
   * @param newdbname       Specifies a name for the new application
   * @param newdbdesc       Specifies the description for the new application
   * @param keepData        Set this parameter to TRUE if you want to copy the application's data
   * @param excludefiles    Specifies that you do not want to copy file attachments when you copy an application
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function CloneDatabase($dbid, $newdbname, $newdbdesc = '', $keepData = FALSE, $excludefiles = TRUE) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'newdbname' => $newdbname,
        'newdbdesc' => $newdbdesc,
      );
      if ($keepData) {
        $params['keepData'] = 1;
        if ($excludefiles) {
          $params['excludefiles'] = 1;
        }
      }
      $resp = $this->sendRequest('API_CloneDatabase', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/API_CopyMasterDetail.htm
   */
  public function CopyMasterDetail() {}

  /**
   * http://www.quickbase.com/api-guide/create_database.html
   * 
   * @param dbname            The name of the new application
   * @param dbdesc            The description for the new application
   * @param createapptoken    Set this parameter to 1 to generate an application token for your applications
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function CreateDatabase($dbname, $dbdesc = '', $createapptoken = FALSE) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'dbname' => $dbname,
        'dbdesc' => $dbdesc,
      );
      if ($createapptoken) {
        $params['createapptoken'] = 1;
      }
      $resp = $this->sendRequest('API_CreateDatabase', $params, 'main');
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/create_table.html
   * 
   * @param dbid    Database ID to execute query against
   * @param tname   The name you want to use for the name of the table
   * @param pnoun   The name you want to use for records in the table (optional)
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function CreateTable($dbid, $tname, $pnoun = NULL) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'dbid' => $dbid,
        'tname' => $tname,
      );
      if ($pnoun) {
        $params['pnoun'] = $pnoun;
      }
      $resp = $this->sendRequest('API_CreateTable', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/delete_database.html
   * 
   * @param dbid        Database ID to execute query against
   * @param apptoken    A valid application token, if the application requires application tokens
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function DeleteDatabase($dbid, $apptoken = NULL) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array();
      if ($apptoken) {
        $params['apptoken'] = $apptoken;
      }
      $resp = $this->sendRequest('API_DeleteDatabase', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/delete_field.html
   * 
   * @param dbid    Database ID to execute query against
   * @param fid     The field ID of the field to be deleted
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function DeleteField($dbid, $fid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'fid' => $fid
      );
      $resp = $this->sendRequest('API_DeleteField', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/delete_record.html
   * 
   * @param dbid    Database ID to execute query against
   * @param rid     The record ID of the record to be deleted
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function DeleteRecord($dbid, $rid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'rid' => $rid
      );
      $resp = $this->sendRequest('API_DeleteRecord', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/do_query.html
   * 
   * @param dbid      Database ID to execute query against
   * @param query     Can be one of the following:
   *                  Array of one or more queries (e.g. {'5'.CT.'Ragnar Lodbrok'})
   *                  Query ID (numeric)
   *                  Query name (alphanumeric)
   * @param clist     Can be one of the following:
   *                  Array of field IDs to be returned
   *                  'a' for all fields
   *                  NULL or empty array to return default fields
   * @param slist     Can be one of the following:
   *                  Array of field IDs to sort on
   *                  NULL or empty array to use default sort fields
   * @param fmt       Set this parameter to "structured" to specify that the query should return structured data
   * @param options   Array of optional query options, name-value pairs:
   *                  returnpercentage    Specifies whether Numeric - Percent values in the returned data will be percentage format (10% is shown as 10) or decimal format (10% is shown as .1)
   *                  options             Specifies return options for the query (see API call page)
   *                  includeRids         Specifies that the record IDs of each record should be returned
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function DoQuery($dbid, $query, $clist = array(), $slist = array(), $fmt = 'structured', $options = array()) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $params = array(
        'fmt' => $fmt,
      );

      // Build out query portion
      if (is_numeric($query)) {
        // Handle query ID
        $params['qid'] = $query;
      }
      elseif (is_array($query)) {
        // Handle array of queries
        $params['query'] = $query;
      }
      else {
        // Assume anything else is a query name
        $params['qname'] = $query;
      }

      // Build return and sort lists
      if (!empty($clist)) {
        $param['clist'] = implode('.', $clist);
      }
      if (!empty($slist)) {
        $param['slist'] = implode('.', $slist);
      }
      
      // Add options
      if (!empty($options)) {
        foreach ($options as $opt_name => $opt_value) {
          $param[$opt_name] = $opt_value;
        }
      }

      // Execute query
      $resp = $this->sendRequest('API_DoQuery', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/do_query_count.html
   * 
   * @param dbid      Database ID to execute query against
   * @param query     Can be one of the following:
   *                  Array of one or more queries (e.g. {'5'.CT.'Ragnar Lodbrok'})
   *                  Query ID (numeric)
   *                  Query name (alphanumeric)
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function DoQueryCount($dbid, $query) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $params = array();

      // Build out query portion
      if (is_numeric($query)) {
        // Handle qeury ID
        $params['qid'] = $query;
      }
      elseif (is_array($query)) {
        // Handle array of queries
        $params['query'] = $query;
      }
      else {
        // Assume anything else is a query name
        $params['qname'] = $query;
      }

      // Execute query
      $resp = $this->sendRequest('API_DoQueryCount', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/edit_record.html
   */
  public function EditRecord() {}

  /**
   * http://www.quickbase.com/api-guide/field_add_choices.html
   */
  public function FieldAddChoices() {}

  /**
   * http://www.quickbase.com/api-guide/field_remove_choices.html
   */
  public function FieldRemoveChoices() {}

  /**
   * http://www.quickbase.com/api-guide/find_db_by_name.html
   */
  public function FindDBByName() {}

  /**
   * http://www.quickbase.com/api-guide/gen_add_record_form.html
   */
  public function GenAddRecordForm() {}

  /**
   * http://www.quickbase.com/api-guide/gen_results_table.html
   */
  public function GenResultsTable() {}

  /**
   * http://www.quickbase.com/api-guide/getancestorinfo.html
   * 
   * @param dbid  Database ID to execute query against
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GetAncestorInfo($dbid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $resp = $this->sendRequest('API_GetAncestorInfo', NULL, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/get_app_dtm_info.html
   * 
   * @param dbid  Database ID to execute query against
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GetAppDTMInfo($dbid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'dbid' => $dbid,
      );
      $resp = $this->sendRequest('API_GetAppDTMInfo', $params, 'main');
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/get_db_info.html
   * 
   * @param dbid  Database ID to execute query against
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GetDBInfo($dbid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $resp = $this->sendRequest('API_GetDBInfo', NULL, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/get_db_page.html
   * 
   * @param dbid    Database ID to execute query against
   * @param pageID  The ID of the page. You can also use the pagename.
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GetDBPage($dbid, $pageID) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'pageID' => $pageID,
      );
      $resp = $this->sendRequest('API_GetDBPage', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/getdbvar.html
   * 
   * @param dbid      Database ID to execute query against
   * @param varname   The name of the variable in the target application
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GetDBvar($dbid, $varname) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'varname' => $varname,
      );
      $resp = $this->sendRequest('API_GetDBvar', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/getnumrecords.html
   * 
   * @param dbid      Database ID to execute query against
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GetNumRecords($dbid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $resp = $this->sendRequest('API_GetNumRecords', NULL, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/getrecordashtml.html
   * 
   * @param dbid      Database ID to execute query against
   * @param rid       The record ID of the record to be edited
   * @param options   Other options, name-value pairs:
   *                  jht     Set to 1 to return the HTML for a table as a JavaScript function named qdbWrite()
   *                  dfid    The dform id of the form used to generate the HTML
   * 
   * @return Returns FALSE on error, HTML fragment if successful
   */
  public function GetRecordAsHTML($dbid, $rid, $options = array()) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'rid' => $rid,
      );
      $params += $options;
      $resp = $this->sendRequest('API_GetRecordAsHTML', $params, $dbid, FALSE);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/getrecordinfo.html
   * 
   * @param dbid      Database ID to execute query against
   * @param rid       The record ID of the record to be edited
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GetRecordInfo($dbid, $rid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'rid' => $rid,
      );
      $resp = $this->sendRequest('API_GetRecordInfo', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/getroleinfo.html
   * 
   * @param dbid      Database ID to execute query against
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GetRoleInfo($dbid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $resp = $this->sendRequest('API_GetRoleInfo', NULL, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/getschema.html
   * 
   * @param dbid  Database ID to execute query against
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GetSchema($dbid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $resp = $this->sendRequest('API_GetSchema', NULL, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/getuserinfo.html
   * 
   * @param email   Supply the email address (as registered with QuickBase) of the user whose information you want. You can also supply the user’s user name.
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GetUserInfo($email) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'email' => $email,
      );
      $resp = $this->sendRequest('API_GetUserInfo', NULL, 'main');
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/getuserrole.html
   * 
   * @param dbid      Database ID to execute query against
   * @param userid    The user ID of the user whose current role you want to retrieve
   * @param inclgrps  Set this parameter to TRUE if you want the call to return roles assigned to groups to which the user belongs
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GetUserRole($dbid, $userid, $inclgrps = FALSE) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'userid' => $userid,
      );
      if ($inclgrps) {
        $params['inclgrps'] = 1;
      }
      $resp = $this->sendRequest('API_GetUserRole', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/granteddbs.html
   * 
   * @param params  Array of name-value pairs that can override the values set during construction
   *                adminOnly           Optional. Returns only tables where the user making the request has administration privileges.
   *                excludeparents      Specifies whether you want application-level dbids returned
   *                includeancestors    Set this parameter to 1 to include ancestor and oldest ancestor information
   *                withembeddedtables  Specifies whether you want child table dbids to be returned
   * 
   * @return Returns FALSE on error, or a complete response object on success 
   */
  public function GrantedDBs($params = array()) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $resp = $this->sendRequest('API_GrantedDBs', $params, 'main');
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/importfromcsv.html
   */
  public function ImportFromCSV() {}

  /**
   * http://www.quickbase.com/api-guide/provisionuser.html
   */
  public function ProvisionUser() {}

  /**
   * http://www.quickbase.com/api-guide/purgerecords.html
   */
  public function PurgeRecords() {}

  /**
   * http://www.quickbase.com/api-guide/removeuserfromrole.html
   */
  public function RemoveUserFromRole() {}

  /**
   * http://www.quickbase.com/api-guide/renameapp.html
   */
  public function RenameApp() {}

  /**
   * http://www.quickbase.com/api-guide/runimport.html
   */
  public function RunImport() {}

  /**
   * http://www.quickbase.com/api-guide/sendinvitation.html
   */
  public function SendInvitation() {}

  /**
   * http://www.quickbase.com/api-guide/setdbvar.html
   */
  public function SetDBvar() {}

  /**
   * http://www.quickbase.com/api-guide/setfieldproperties.html
   */
  public function SetFieldProperties() {}

  /**
   * http://www.quickbase.com/api-guide/setkeyfield.html
   */
  public function SetKeyField() {}

  /**
   * http://www.quickbase.com/api-guide/signout.html
   */
  public function SignOut() {}

  /**
   * http://www.quickbase.com/api-guide/uploadfile.html
   */
  public function UploadFile() {}

  /**
   * http://www.quickbase.com/api-guide/userroles.html
   */
  public function UserRoles() {}
};

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
   * 
   * @param dbid        Database ID to execute query against
   * @param destrid     The record id of the destination record to which you want the records copied
   * @param sourcerid   The record id of the source record from which you want to copy detail records
   * @param copyfid     The field id of a text field used in the name of the new record, if destrid = 0
   * @param recurse     Set this parameter to true to copy all detail records associated with the master record's detail records recursively
   * @param relfids     A list of report link field ids that specify the relationships you want to be copied
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function CopyMasterDetail($dbid, $destrid, $sourcerid, $copyfid = NULL, $recurse = TRUE, $relfids = array()) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'destrid' => $destrid,
        'sourcerid' => $sourcerid,
      );
      if (0 == $destrid && !empty($copyfid)) {
        $params['copyfid'] = $copyfid;
      }
      if ($recurse) {
        $params['recurse'] = 'true';
      }
      if (!empty($relfids)) {
        $params['relfids'] = implode(',', $relfids);
      }

      $resp = $this->sendRequest('API_CopyMasterDetail', $params, $dbid);
    }
    return $resp;
  }

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
   * @param options   Array of optional query options, name-value pairs:
   *                  returnpercentage    Specifies whether Numeric - Percent values in the returned data will be percentage format (10% is shown as 10) or decimal format (10% is shown as .1)
   *                  options             Specifies return options for the query (see API call page)
   *                  includeRids         Specifies that the record IDs of each record should be returned
   * @param fmt       Set this parameter to "structured" to specify that the query should return structured data
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function DoQuery($dbid, $query = NULL, $clist = array(), $slist = array(), $options = array(), $fmt = 'structured') {
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
      elseif (!empty($query)) {
        // Assume anything else is a query name
        $params['qname'] = $query;
      }

      // Build return and sort lists
      if (!empty($clist)) {
        $params['clist'] = implode('.', $clist);
      }
      if (!empty($slist)) {
        $params['slist'] = implode('.', $slist);
      }
      
      // Add options
      if (!empty($options)) {
        $params += $options;
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
  public function DoQueryCount($dbid, $query = NULL) {
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
      elseif (!empty($query)) {
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
   * 
   * @param dbid      Database ID to execute query against
   * @param rid       The record ID of the record to be edited. You can obtain the recordID of any record in a query
   * @param record    Array of field values in the format:
   *                  array(
   *                    <fid|name> => <value|array('data' => DATA[, 'attributes' => array(NAME => VAL)])>
   *                  );
   * @param update_id You can obtain the update ID for a record using the API_GetRecordInfo for the record you are editing
   * @param options   Array of name-value pairs that can override the values set during construction
   *                  disprec       Set this parameter to 1 to specify that the new record should be displayed within the QuickBase application
   *                  fform         Set this parameter to 1 if you are invoking API_AddRecord from within an HTML form that has checkboxes and want those checkboxes to set QuickBase checkbox fields
   *                  ignoreError   Set this parameter to 1 to specify that no error should be returned when a built-in field (for example, Record ID#) is written-to in an API_AddRecord call
   *                  msInUTC       Allows you to specify that QuickBase should interpret all date/time stamps passed in as milliseconds using Coordinated Universal Time (UTC) rather than using the local application time
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function EditRecord($dbid, $rid, $record, $update_id = NULL, $options = array()) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'rid' => $rid,
      );

      // Assemble field elements
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

      // Add update ID if provided
      if (!empty($update_id)) {
        $params['update_id'] = $update_id;
      }

      // Merge passed-in options as-is
      $params += $options;

      $resp = $this->sendRequest('API_EditRecord', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/field_add_choices.html
   * 
   * @param dbid      Database ID to execute query against
   * @param fid       The field ID of the multiple choice field to which you want to add choices
   * @param choices   List of choices to add to multiple-choice text field
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function FieldAddChoices($dbid, $fid, $choices) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'fid' => $fid,
      );

      // Add choice elements
      $params['choice'] = $choices;

      $resp = $this->sendRequest('API_FieldAddChoices', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/field_remove_choices.html
   * 
   * @param dbid      Database ID to execute query against
   * @param fid       The field ID of the multiple choice field to which you want to remove choices
   * @param choices   List of choices to remove from multiple-choice text field
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function FieldRemoveChoices($dbid, $fid, $choices) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'fid' => $fid,
      );

      // Add choice elements
      $params['choice'] = $choices;

      $resp = $this->sendRequest('API_FieldRemoveChoices', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/find_db_by_name.html
   * 
   * @param dbname        The name of the application you want to find
   * @param parentsOnly   Ensures an app ID is returned, regardless of whether the application contains a single table or not
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function FindDBByName($dbname, $parentsOnly = TRUE) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'dbname' => $dbname,
      );

      if ($parentsOnly) {
        $params['ParentsOnly'] = 1;
      }

      $resp = $this->sendRequest('API_FindDBByName', $params, 'main');
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/gen_add_record_form.html
   * 
   * @param dbid      Database ID to execute query against
   * @param fields    Array of field values in the format:
   *                  array(
   *                    <fid|name> => <value>
   *                  );
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GenAddRecordForm($dbid, $fields = array()) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array();
      foreach ($fields as $fid => $fvalue) {
        // If we have a numeric field ID
        if (is_numeric($fid)) {
          $params["_fid_{$fid}"] = $fvalue;
        }
        
        // Otherwise, we have a field name
        else {
          $params['field'][] = array(
            'data' => $fvalue,
            'attributes' => array(
              'name' => $fid,
            ),
          );
        }
      }

      $resp = $this->sendRequest('API_GenAddRecordForm', $params, $dbid, FALSE);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/gen_results_table.html
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
   * @param options   Array of optional query options, name-value pairs:
   *                  returnpercentage    Specifies whether Numeric - Percent values in the returned data will be percentage format (10% is shown as 10) or decimal format (10% is shown as .1)
   *                  options             Specifies return options for the query (see API call page)
   *                  includeRids         Specifies that the record IDs of each record should be returned
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function GenResultsTable($dbid, $query = NULL, $clist = array(), $slist = array(), $options = array()) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $params = array();

      // Build out query portion
      if (is_numeric($query)) {
        // Handle query ID
        $params['qid'] = $query;
      }
      elseif (is_array($query)) {
        // Handle array of queries
        $params['query'] = $query;
      }
      elseif (!empty($query)) {
        // Assume anything else is a query name
        $params['qname'] = $query;
      }

      // Build return and sort lists
      if (!empty($clist)) {
        $params['clist'] = implode('.', $clist);
      }
      if (!empty($slist)) {
        $params['slist'] = implode('.', $slist);
      }
      
      // Add options
      if (!empty($options)) {
        $params += $options;
      }

      // Execute query
      $resp = $this->sendRequest('API_GenResultsTable', $params, $dbid, FALSE);
    }
    return $resp;
  }

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
      $resp = $this->sendRequest('API_GrantedDBs', $params, 'main');
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/importfromcsv.html
   * 
   * @param dbid          Database ID to execute query against
   * @param records_csv   An aggregate containing the actual records you are importing
   * @param clist         Use this parameter only if you are updating existing record. Do not use this parameter  if you are adding new records!
   *                      A period-delimited list of field IDs to which the CSV columns map.  (See docs for more details.)
   * @param options       Other options, name-value pairs:
   *                      clist_output  Specifies which fields should be returned in addition to the record ID and updated ID
   *                      skipfirst     Set this parameter to 1 to prevent QuickBase from importing the first row of data in a CSV file
   *                      msInUTC       Allows you to specify that QuickBase should interpret all date/time stamps passed in as milliseconds using Coordinated Universal Time (UTC) rather than using the local application time
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function ImportFromCSV($dbid, $records_csv, $clist = array(), $options = array()) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'records_csv' => $records_csv,
      );
      if (!empty($clist)) {
        $params['clist'] = implode('.', $clist);
      }
      if (!empty($options['clist_output'])) {
        $params['clist_output'] = implode('.', $options['clist_output']);
      }
      if (!empty($options['skipfirst'])) {
        $params['skipfirst'] = 1;
      }
      if (!empty($options['msInUTC'])) {
        $params['msInUTC'] = 1;
      }

      $resp = $this->sendRequest('API_ImportFromCSV', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/provisionuser.html
   * 
   * @param dbid      Database ID to execute query against
   * @param email     The email address of the person to whom you are granting access
   * @param fname     The first name of the new QuickBase user
   * @param lname     The last name of the new QuickBase user
   * @param roleid    The role ID of the role you want to assign this user to
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function ProvisionUser($dbid, $email, $fname, $lname, $roleid = NULL) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array(
        'email' => $email,
        'fname' => $fname,
        'lname' => $lname,
      );
      if ($roleid) {
        $params['roleid'] = $roleid;
      }

      $resp = $this->sendRequest('API_ProvisionUser', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/purgerecords.html
   * 
   * @param dbid      Database ID to execute query against
   * @param query     Can be one of the following:
   *                  Array of one or more queries (e.g. {'5'.CT.'Ragnar Lodbrok'})
   *                  Query ID (numeric)
   *                  Query name (alphanumeric)
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function PurgeRecords($dbid, $query = NULL) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $params = array();

      // Build out query portion
      if (is_numeric($query)) {
        // Handle query ID
        $params['qid'] = $query;
      }
      elseif (is_array($query)) {
        // Handle array of queries
        $params['query'] = $query;
      }
      elseif (!empty($query)) {
        // Assume anything else is a query name
        $params['qname'] = $query;
      }

      // Execute query
      $resp = $this->sendRequest('API_PurgeRecords', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/removeuserfromrole.html
   * 
   * @param dbid      Database ID to execute query against
   * @param userid    The ID of user you want removed from the role
   * @param roleid    The ID of the role from which you want the user removed
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function RemoveUserFromRole($dbid, $userid, $roleid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $params = array(
        'userid' => $userid,
        'roleid' => $roleid,
      );

      // Execute query
      $resp = $this->sendRequest('API_RemoveUserFromRole', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/renameapp.html
   * 
   * @param dbid        Database ID to execute query against
   * @param newappname  The name you want to assign to the application
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function RenameApp($dbid, $newappname) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $params = array(
        'newappname' => $newappname,
      );

      // Execute query
      $resp = $this->sendRequest('API_RenameApp', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/runimport.html
   * 
   * @param dbid  Database ID to execute query against
   * @param id    The ID of the saved import that you want to execute
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function RunImport($dbid, $id) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $params = array(
        'id' => $id,
      );

      // Execute query
      $resp = $this->sendRequest('API_RunImport', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/sendinvitation.html
   * 
   * @param dbid      Database ID to execute query against
   * @param userid    The ID of the QuickBase user you are inviting to your application
   * @param usertext  The message you want to display in your email invitation
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function SendInvitation($dbid, $userid, $usertext = NULL) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $params = array(
        'userid' => $userid,
      );
      if (!empty($usertext)) {
        $params['usertext'] = $usertext;
      }

      // Execute query
      $resp = $this->sendRequest('API_SendInvitation', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/setdbvar.html
   * 
   * @param dbid      Database ID to execute query against
   * @param varname   The name you want the DBVar to have
   * @param value     The value you want to set in the DBVar
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function SetDBvar($dbid, $varname, $value) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $params = array(
        'varname' => $varname,
        'value' => $value,
      );

      // Execute query
      $resp = $this->sendRequest('API_SetDBvar', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/setfieldproperties.html
   * 
   * @param dbid        Database ID to execute query against
   * @param fid         Field ID of the field to be changed
   * @param properties  Name/value pairs for the properties to be set.  See docs for properties.
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function SetFieldProperties($dbid, $fid, $properties = array()) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $params = array(
        'fid' => $fid,
      );

      // Add properties
      $params += $properties;

      // Execute query
      $resp = $this->sendRequest('API_SetFieldProperties', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/setkeyfield.html
   * 
   * @param dbid        Database ID to execute query against
   * @param fid         The field ID of the table field to be used as the key field
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function SetKeyField($dbid, $fid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $params = array(
        'fid' => $fid,
      );

      // Execute query
      $resp = $this->sendRequest('API_SetKeyField', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/signout.html
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function SignOut() {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $resp = $this->sendRequest('API_SignOut', NULL, 'main');
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/uploadfile.html
   */
  public function UploadFile() {}

  /**
   * http://www.quickbase.com/api-guide/userroles.html
   * 
   * @param dbid        Database ID to execute query against
   * 
   * @return Returns FALSE on error, response object on success
   */
  public function UserRoles($dbid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      $resp = $this->sendRequest('API_UserRoles', NULL, $dbid);
    }
    return $resp;
  }
};

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
   * Executes an XML-based POST request against the QuickBase service and returns the results
   * 
   * @param fn      QuickBase function to call
   * @param params  Array of name-value pairs for the function call
   * @param dbid    Database ID to execute the function against (optional)
   * 
   * @return FALSE on error, SimpleXMLElement on success.  Error code and message are stored on the object in the properties errno and errmsg, respectively.
   */
  protected function sendRequest($fn, $params, $dbid = NULL) {
    $resp = FALSE;

    // Construct XML from params
    if ($this->ticket) {
      $params['ticket'] = $this->ticket;
    }
    $xml = new SimpleXMLElement('<qdbapi></qdbapi>');
    foreach ($params as $name => $value) {
      if (!empty($value)) {
        $xml->addChild($name, $value);
      }
    }
    $xml = $xml->asXML();

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
        $resp_xml = curl_exec($ch);

        // Check for "successful" cURL request
        if (FALSE !== $resp_xml) {
          // Check for successful HTTP request
          $resp_info = curl_getinfo($ch);
          if (2 == ($resp_info['http_code'] / 100)) {
            try {
              $resp = new SimpleXMLElement($resp_xml);

              // Check service-level error code
              if (0 != $resp->errcode) {
                $this->errno = $resp->errcode;
                $this->errmsg = $this->formatErrMsg('QuickBase API', $resp->errtext, $url);
                $resp = FALSE;
              }
            }
            catch (Exception $e) {
              $this->errno = $e->getCode();
              $this->errmsg = $this->formatErrMsg('SimpleXML', $e->getMessage(), $url);
            }
          }
          else {
            $this->errno = $resp_info['http_code'];
            $this->errmsg = $this->formatErrMsg('HTTP', 'Received non-2xx response code', $url);
          }
        }
        else {
          $this->errno = curl_errno($ch);
          $this->errmsg = $this->formatErrMsg('cURL', curl_error($ch), $url);
        }

        // Perform cleanup
        curl_close($ch);
      }
      else {
        $this->errno = -1;
        $this->errmsg = $this->formatErrMsg('cURL', 'Unable to initialize cURL interface', $url);
      }
    }
    else {
      $this->errno = -1;
      $this->errmsg = $this->formatErrMsg('SimpleXML', 'Unable to construct XML from provided parameters: ' . print_r($params, TRUE), $url);
    }

    return $resp;
  }

  /**
   * Formats error message text
   * 
   * @param comp    Component reporting error
   * @param msg     Message text
   * @param url     URL that generated the error
   */
   protected function formatErrMsg($comp, $msg, $url = NULL) {
     return "{$comp}: {$msg} " . (($url) ? "[{$url}]" : '');
   }

  //////////////////////////////////////////////////////////////////////
  // Public API
  //////////////////////////////////////////////////////////////////////
  /**
   * http://www.quickbase.com/api-guide/add_field.html
   */
  public function AddField() {}

  /**
   * http://www.quickbase.com/api-guide/add_record.html
   */
  public function AddRecord() {}

  /**
   * http://www.quickbase.com/api-guide/add_replace_dbpage.html
   */
  public function AddReplaceDBPage() {}

  /**
   * http://www.quickbase.com/api-guide/add_user_to_role.html
   */
  public function AddUserToRole() {}

  /**
   * http://www.quickbase.com/api-guide/authenticate.html
   * 
   * @param params  Array of name-value pairs that ccan override the values set during construction
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
   */
  public function ChangeRecordOwner() {}

  /**
   * http://www.quickbase.com/api-guide/change_user_role.html
   */
  public function ChangeUserRole() {}

  /**
   * http://www.quickbase.com/api-guide/clone_database.html
   */
  public function CloneDatabase() {}

  /**
   * http://www.quickbase.com/api-guide/API_CopyMasterDetail.htm
   */
  public function CopyMasterDetail() {}

  /**
   * http://www.quickbase.com/api-guide/create_database.html
   */
  public function CreateDatabase() {}

  /**
   *  http://www.quickbase.com/api-guide/create_table.html
   */
  public function CreateTable() {}

  /**
   * http://www.quickbase.com/api-guide/delete_database.html
   */
  public function DeleteDatabase() {}

  /**
   * http://www.quickbase.com/api-guide/delete_field.html
   */
  public function DeleteField() {}

  /**
   * http://www.quickbase.com/api-guide/delete_record.html
   */
  public function DeleteRecord() {}

  /**
   * http://www.quickbase.com/api-guide/do_query.html
   */
  public function DoQuery() {}

  /**
   * http://www.quickbase.com/api-guide/do_query_count.html
   */
  public function DoQueryCount() {}

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
   */
  public function GetAncestorInfo() {}

  /**
   * http://www.quickbase.com/api-guide/get_app_dtm_info.html
   */
  public function GetAppDTMInfo() {}

  /**
   * http://www.quickbase.com/api-guide/get_db_info.html
   * 
   * @param dbid  Database ID to execute query against
   */
  public function GetDBInfo($dbid) {
    $resp = FALSE;
    if ($this->Authenticate()) {
      // Assemble params for call
      $params = array();
      if (!empty($this->apptoken)) {
        $params['apptoken'] = $this->apptoken;
      }
      $resp = $this->sendRequest('API_GetDBInfo', $params, $dbid);
    }
    return $resp;
  }

  /**
   * http://www.quickbase.com/api-guide/get_db_page.html
   */
  public function GetDBPage() {}

  /**
   * http://www.quickbase.com/api-guide/getdbvar.html
   */
  public function GetDBvar() {}

  /**
   * http://www.quickbase.com/api-guide/getnumrecords.html
   */
  public function GetNumRecords() {}

  /**
   * http://www.quickbase.com/api-guide/getrecordashtml.html
   */
  public function GetRecordAsHTML() {}

  /**
   * http://www.quickbase.com/api-guide/getrecordinfo.html
   */
  public function GetRecordInfo() {}

  /**
   * http://www.quickbase.com/api-guide/getroleinfo.html
   */
  public function GetRoleInfo() {}

  /**
   * http://www.quickbase.com/api-guide/getschema.html
   */
  public function GetSchema() {}

  /**
   * http://www.quickbase.com/api-guide/getuserinfo.html
   */
  public function GetUserInfo() {}

  /**
   * http://www.quickbase.com/api-guide/getuserrole.html
   */
  public function GetUserRole() {}

  /**
   * http://www.quickbase.com/api-guide/granteddbs.html
   * 
   * @param params  Array of name-value pairs that ccan override the values set during construction
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

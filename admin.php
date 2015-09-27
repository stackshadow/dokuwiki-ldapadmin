<?php


class admin_plugin_ldapadmin extends DokuWiki_Admin_Plugin {


	public function getInfo(){
		return array(
			'author' => 'stackshadow',
			'email'  => 'stackshadow@evilbrain.de',
			'date'	 => '2015-09-27',
			'name'	 => 'LDAP Administrator',
			'desc'	 => 'Manage your LDAP-User-Accounts',
			'url'	 => 'https://github.com/',
		);
	}

  var $formAction = '';
  var $formValues = '';
  
  var $selectedUser = '';
  
  var $arrayUsers;              // users inside the ldap
  var $arrayAttributes;         // attributes inside ldap of an selected user
  
  var $formOutput = '';
  var $htmlOutput = '';
  

  

  function __construct() {
   

  // Read the userlist
    $this->arrayUsers = array();
    $this->readUsers();

    

  }
   

   
/** handle user request */
    function handle() {

      $this->formAction = '';
      if (!is_array($_REQUEST['formAction'])) { return; }
      if (!checkSecurityToken()) return;
      $this->formAction = key($_REQUEST['formAction']);

    }
 
/** output appropriate html */
    function html() {

    
      $this->handleAction();

      ptln('<form action="'.wl($ID).'" method="post">');
      // output hidden values to ensure dokuwiki will return back to this plugin
      ptln('  <input type="hidden" name="do"   value="admin" />');
      ptln('  <input type="hidden" name="page" value="'.$this->getPluginName().'" />');
      formSecurityToken();
      ptln( $this->formOutput );
      ptln('</form><br>');
      
      ptln( $this->htmlOutput );

    }


    function handleAction(){
      $this->formValues = $_REQUEST['formValues'];
      $this->selectedUser = $this->formValues['selectedUser'];
      $this->readUserInfo( $this->selectedUser );   // read the user info from ldap
  
      switch ( $this->formAction ) {
        case '':
                          $this->formSelectUserAndAction();
                          break;
        case 'showUser' : 
                          $this->showUserinfo();
                          break;
        case 'editUser' : 
                          $this->formEditUserAttributes();
                          break;
        case 'showChanges' :
                          $this->formShowLDIF();
                          break;
        case 'saveChanges' :
                          $this->formSaveLDIF();
                          break;
      }
      
    
    }


    function formSelectUserAndAction(){
      $this->formOutput = '';
    
      $this->formOutput .= '<select name="formValues[selectedUser]">';
      foreach( $this->arrayUsers as $user ) {
        $this->formOutput .= '<option value="'.$user.'">'.$user.'</option>';
      }
      $this->formOutput .= '</select>';
     
      $this->formOutput .= '<input type="submit" name="formAction[showUser]"  value="'.$this->getLang('SHOW_USER').'" />';
      $this->formOutput .= '<input type="submit" name="formAction[editUser]"  value="'.$this->getLang('EDIT_USER').'" />';
      //ptln('  <input type="submit" name="cmd[goodbye]"  value="'.$this->getLang('btn_goodbye').'" />');

    }

    
    function formEditUserAttributes(){
      $this->formOutput = 'Benutzer-DN: ' . $this->selectedUser . '<br>';
      
      
      $arrayKeys = array_keys( $this->arrayAttributes );

      
      foreach( $arrayKeys as $key ) {
        
      // password will not be shown
        if( $key == 'userPassword' ){
          $this->formOutput .= $key . ': <input type="text" name="formValues[' . $key . ']" value=""/><br>';
          continue;
        }
        
        $this->formOutput .= $key . ': <input type="text" name="formValues[' . $key . ']" value="' . $this->arrayAttributes[$key]['value'] . '"/><br>';
      }
      $this->formOutput .= '<input type="hidden" name="formValues[selectedUser]" value="' . $this->selectedUser . '">';
      $this->formOutput .= '<input type="submit" name="formAction[showChanges]"  value="Bearbeiten" />';
      
    }


    function formShowLDIF(){
      $this->htmlOutput = '';
      
      $ldifFileName = "/tmp/import.ldif";
      $ldifFile = fopen( $ldifFileName, 'w+' ) or die("can't open file");

      $stringData  = "### changing:\n";
      $stringData .= "dn: " . $this->arrayAttributes['dn']['value'] . "\n";
      $stringData .= "changetype: modify\n";


      $arrayKeys = array_keys( $this->arrayAttributes );
      foreach( $arrayKeys as $key ) {
        
      // hash userPassword
        if( $key == 'userPassword' ){
          if( strlen($this->formValues[$key]) > 1 ){
            $hashedpw = $this->getSSHAPassword( $this->formValues[$key] );
            $this->htmlOutput .= 'Hashed Password: ' . $hashedpw . '<br>';
            $this->formValues[$key] = $hashedpw;
          } else {
            continue;
          }
        }
        
      // debugging
        $this->htmlOutput .= 'arrayKey: ' . $key;
        $this->htmlOutput .= ' arrayAttributes: ' . $this->arrayAttributes[$key]['value'];
        $this->htmlOutput .= ' formValues: ' . $this->formValues[$key];
        $this->htmlOutput .= '<br>';
        
      // add / replace ?
        if( $this->arrayAttributes[$key]['exist'] ){                                    // if value exist in ldap
          if( strlen($this->formValues[$key]) > 1 ){
            if( $this->arrayAttributes[$key]['value'] != $this->formValues[$key] ){       // if value is changed
              $stringData .= 'replace: ' . $key . "\n";
              $stringData .= $key . ': ' . $this->formValues[$key] . "\n";
            }
          }
        } else {
          if( strlen($this->formValues[$key]) > 1 ){
            if( $this->arrayAttributes[$key]['value'] != $this->formValues[$key] ){       // if value is changed
              $stringData .= 'add: ' . $key . "\n";
              $stringData .= $key . ': ' . $this->formValues[$key] . "\n";
            }
          }
        }
      }




      fwrite( $ldifFile, $stringData );
      fclose( $ldifFile );
      
      $this->htmlOutput .= 'Dieser String wird an den LDAP-Server gesendet: <br>';
      $this->htmlOutput .= str_replace( "\n", '<br>', $stringData );

      $this->formOutput .= '<input type="hidden" name="formValues[selectedUser]" value="' . $this->selectedUser . '">';
      $this->formOutput .= '<input type="submit" name="formAction[saveChanges]"  value="Speichern" />';
      $this->formOutput .= '<input type="submit" name="formAction"  value="Abbrechen" />';
    }


    function formSaveLDIF(){
      $this->htmlOutput = '';
      $ldifFileName = "/tmp/import.ldif";

      $command  = 'ldapmodify';
      $command .= ' -h '.$this->getConf('server');
      $command .= ' -p '.$this->getConf('port');
      $command .= ' -D '.$this->getConf('binddn');
      $command .= ' -w '.$this->getConf('bindpw');
      $command .= ' -v -d 10 -f '.$ldifFileName;
      exec( $command, $commandOutput );
      
      foreach( $commandOutput as $output ){
        $this->htmlOutput .= $output . '<br>';
      }
      
      $this->formSelectUserAndAction();
    }


    function readAdditionalAttributesFromConfig(){
    // read from config
      $additionalAttributes = $this->getConf('additionalAttributes');
      if( strlen($additionalAttributes) <= 0 ) return;
      
    // split the attributes
      $arrayAttributes = split( ',', $additionalAttributes );
      
    // iterate through the attributes
      $this->arrayAttributes = array();
      foreach( $arrayAttributes as $attribute ){
        
      // check
        if( strlen($attribute) <= 1 ) continue;
    
    // build the attribute-array
        $this->arrayAttributes[$attribute]['value'] = '';
        $this->arrayAttributes[$attribute]['exist'] = FALSE;
      }
      
    }


    function readUsers(){
      $command  = 'ldapsearch';
      $command .= ' -h '.$this->getConf('server');
      $command .= ' -p '.$this->getConf('port');
      $command .= ' -D '.$this->getConf('binddn');
      $command .= ' -w '.$this->getConf('bindpw');

      exec( $command, $op );

      foreach( $op as $value ) {
        
        // find dn
        $pos = strpos($value, 'dn');
        if ($pos !== false) {
          
          // find uid entry inside the dn
          $pos = strpos($value, 'uid');
          if ($pos !== false) {
            $pos = strpos($value, 'dn: ');
            $this->arrayUsers[] = substr( $value , $pos + 4 );
            
          }
        }
      }
    }


    function readUserInfo( $selectedUser ){
      $command  = 'ldapsearch';
      $command .= ' -h '.$this->getConf('server');
      $command .= ' -p '.$this->getConf('port');
      $command .= ' -D '.$this->getConf('binddn');
      $command .= ' -w '.$this->getConf('bindpw');
      $command .= ' -b '.$selectedUser;
      $command .= ' -LLL';
      exec( $command, $results );
      
    // Guess what this function do ;)
      $this->readAdditionalAttributesFromConfig();

      foreach( $results as $result ) {
      // replace :: with :
        $result = str_replace( '::', ':', $result );
      
      // split key / value
        $arrayAttribute = split( ': ', $result );
        $attributeName = $arrayAttribute[0];
        $attributeValue = $arrayAttribute[1];
        
      // write into our attributes array
        if( strlen($attributeValue) > 0 ){
          $this->arrayAttributes[$attributeName]['value'] = $attributeValue;
          $this->arrayAttributes[$attributeName]['exist'] = TRUE;
        }
      }
    }


    function showUserinfo(){
      $this->formSelectUserAndAction();

      $arrayKeys = array_keys( $this->arrayAttributes );

      $this->htmlOutput = '';
      foreach( $arrayKeys as $key ) {
        $this->htmlOutput .= $key . ': ' . $this->arrayAttributes[$key]['value'] . '<br>';
      }
    }


    function getSSHAPassword( $password ){
        $command  = 'slappasswd';
        $command .= ' -h {SSHA} ';
        $command .= ' -s '.$password;
        exec( $command, $op );
        
        return $op[0];
    }
}

<?php
/*******************************************************************
    class.osticket.php

    osTicket (sys) -> Config.

    Core osTicket object: loads congfig and provides loggging facility.

    Use osTicket::start(configId)

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2012 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

require_once(INCLUDE_DIR.'class.config.php'); //Config helper
require_once(INCLUDE_DIR.'class.csrf.php'); //CSRF token class.

define('LOG_WARN',LOG_WARNING);

class osTicket {

    var $loglevel=array(1=>'Error','Warning','Debug');
    var $errors;
    var $warning;
    var $message;

    var $headers;

    var $config;
    var $session;
    var $csrf;

    function osTicket($cfgId) {
        
        $this->config = Config::lookup($cfgId);

        //DB based session storage was added starting with v1.7
        if($this->config && !$this->getConfig()->getDBVersion())
            $this->session = osTicketSession::start(SESSION_TTL); // start DB based session
        else
            session_start();

        $this->csrf = new CSRF('__CSRFToken__');
    }

    function isSystemOnline() {
        return ($this->getConfig() && $this->getConfig()->isHelpDeskOnline() && !$this->isUpgradePending());
    }

    function isUpgradePending() {
        return (defined('SCHEMA_SIGNATURE') && strcasecmp($this->getDBSignature(), SCHEMA_SIGNATURE));
    }

    function getSession() {
        return $this->session;
    }

    function getConfig() {
        return $this->config;
    }

    function getConfigId() {

        return $this->getConfig()?$this->getConfig()->getId():0;
    }

    function getDBSignature() {
        return $this->getConfig()->getSchemaSignature();
    }

    function getVersion() {
        return THIS_VERSION;
    }

    function getCSRF(){
        return $this->csrf;
    }

    function getCSRFToken() {
        return $this->getCSRF()->getToken();
    }

    function getCSRFFormInput() {
        return $this->getCSRF()->getFormInput();
    }

    function validateCSRFToken($token) {
        return ($token && $this->getCSRF()->validateToken($token));
    }

    function checkCSRFToken($name='') {

        $name = $name?$name:$this->getCSRF()->getTokenName();
        if(isset($_POST[$name]) && $this->validateCSRFToken($_POST[$name]))
            return true;
       
        if(isset($_SERVER['HTTP_X_CSRFTOKEN']) && $this->validateCSRFToken($_SERVER['HTTP_X_CSRFTOKEN']))
            return true;

        $msg=sprintf('Invalid CSRF token [%s] on %s',
                ($_POST[$name].''.$_SERVER['HTTP_X_CSRFTOKEN']), THISPAGE);
        $this->logWarning('Invalid CSRF Token '.$name, $msg);

        return false;
    }
    
    function isFileTypeAllowed($file, $mimeType='') {
       
        if(!$file || !($allowedFileTypes=$this->getConfig()->getAllowedFileTypes()))
            return false;

        //Return true if all file types are allowed (.*)
        if(trim($allowedFileTypes)=='.*') return true;

        $allowed = array_map('trim', explode(',', strtolower($allowedFileTypes)));
        $filename = is_array($file)?$file['name']:$file;

        $ext = strtolower(preg_replace("/.*\.(.{3,4})$/", "$1", $filename));

        //TODO: Check MIME type - file ext. shouldn't be solely trusted.

        return ($ext && is_array($allowed) && in_array(".$ext", $allowed));
    }

    /* Function expects a well formatted array - see  Format::files()
       It's up to the caller to reject the upload on error.
     */
    function validateFileUploads(&$files) {
       
        $errors=0;
        foreach($files as &$file) {
            if(!$this->isFileTypeAllowed($file))
                $file['error']='Invalid file type for '.$file['name'];
            elseif($file['size']>$this->getConfig()->getMaxFileSize())
                $file['error']=sprintf('File (%s) is too big. Maximum of %s allowed',
                        $file['name'], Format::file_size($this->getConfig()->getMaxFileSize()));
            elseif(!$file['error'] && !is_uploaded_file($file['tmp_name']))
                $file['error']='Invalid or bad upload POST';

            if($file['error']) $errors++;
        }

        return (!$errors);
    }

    function addExtraHeader($header) {
        $this->headers[md5($header)] = $header;
    }

    function getExtraHeaders() {
        return $this->headers;
    }

    function getErrors() {
        return $this->errors;
    }

    function setErrors($errors) {
        if(!is_array($errors))
            return  $this->setError($errors);

        $this->errors = $errors;
    }

    function getError() {
        return $this->errors['err'];
    }

    function setError($error) {
        $this->errors['err'] = $error;
    }

    function clearError() {
        $this->setError('');
    }

    function getWarning() {
        return $this->warning;
    }

    function setWarning($warn) {
        $this->warning = $warn;
    }

    function clearWarning() {
        $this->setWarning('');
    }


    function getMessage() {
        return $this->message;
    }

    function setMessage($msg) {
        $this->message = $msg;
    }

    function clearMessage() {
        $this->setMessage('');
    }


    function alertAdmin($subject, $message, $log=false) {
                
        //Set admin's email address
        if(!($to=$this->getConfig()->getAdminEmail()))
            $to=ADMIN_EMAIL;


        //append URL to the message
        $message.="\n\n".THISPAGE;

        //Try getting the alert email.
        $email=null;
        if(!($email=$this->getConfig()->getAlertEmail())) 
            $email=$this->getConfig()->getDefaultEmail(); //will take the default email.

        if($email) {
            $email->send($to, $subject, $message);
        } else {//no luck - try the system mail.
            Email::sendmail($to, $subject, $message, sprintf('"osTicket Alerts"<%s>',$to));
        }

        //log the alert? Watch out for loops here.
        if($log)
            $this->log(LOG_CRIT, $subject, $message, false); //Log the entry...and make sure no alerts are resent.

    }

    function logDebug($title, $message, $alert=false) {
        return $this->log(LOG_DEBUG, $title, $message, $alert);
    }

    function logInfo($title, $message, $alert=false) {
        return $this->log(LOG_INFO, $title, $message, $alert);
    }

    function logWarning($title, $message, $alert=true) {
        return $this->log(LOG_WARN, $title, $message, $alert);
    }
    
    function logError($title, $error, $alert=true) {
        return $this->log(LOG_ERR, $title, $error, $alert);
    }

    function logDBError($title, $error, $alert=true) {

        if($alert && !$this->getConfig()->alertONSQLError())
            $alert =false;

        return $this->log(LOG_ERR, $title, $error, $alert);
    }

    function log($priority, $title, $message, $alert=false) {

        //We are providing only 3 levels of logs. Windows style.
        switch($priority) {
            case LOG_EMERG:
            case LOG_ALERT: 
            case LOG_CRIT: 
            case LOG_ERR:
                $level=1; //Error
                break;
            case LOG_WARN:
            case LOG_WARNING:
                $level=2; //Warning
                break;
            case LOG_NOTICE:
            case LOG_INFO:
            case LOG_DEBUG:
            default:
                $level=3; //Debug
        }

        //Alert admin if enabled...
        if($alert)
            $this->alertAdmin($title, $message);

        //Logging everything during upgrade.
        if($this->getConfig()->getLogLevel()<$level && !$this->isUpgradePending())
            return false;

        //Save log based on system log level settings.
        $loglevel=array(1=>'Error','Warning','Debug');
        $sql='INSERT INTO '.SYSLOG_TABLE.' SET created=NOW(), updated=NOW() '.
            ',title='.db_input($title).
            ',log_type='.db_input($loglevel[$level]).
            ',log='.db_input($message).
            ',ip_address='.db_input($_SERVER['REMOTE_ADDR']);
        
        mysql_query($sql); //don't use db_query to avoid possible loop.
        
        return true;
    }

    function purgeLogs() {

        if(!($gp=$this->getConfig()->getLogGracePeriod()) || !is_numeric($gp))
            return false;

        //System logs
        $sql='DELETE  FROM '.SYSLOG_TABLE.' WHERE DATE_ADD(created, INTERVAL '.$gp.' MONTH)<=NOW()';
        db_query($sql);
        
        //TODO: Activity logs

        return true;
    }

    /**** static functions ****/
    function start($configId) {

        if(!$configId || !($ost = new osTicket($configId)) || $ost->getConfigId()!=$configId)
            return null;

        //Set default time zone... user/staff settting will overwrite it (on login).
        $_SESSION['TZ_OFFSET'] = $ost->getConfig()->getTZoffset();
        $_SESSION['TZ_DST'] = $ost->getConfig()->observeDaylightSaving();

        return $ost;
    }
}

?>

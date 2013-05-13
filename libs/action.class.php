<?php
/**
 * Wikka Action Class
 *
 * An abstract class that can be extended for wikka action classes.
 *
 *
 * @package     Action
 * @author      Tom Atwell <klenwell@gmail.com>
 * @copyright   Copyright 2010, Tom Atwell <klenwell@gmail.com>
 * @license     GNU General Public License v3
 * @link        http://www.opensource.org/licenses/gpl-3.0.html
 * @link        http://klenwell.com/is/WikkaBaseActionClass
 * @link        git@gist.github.com:5564387.git
 *
 */

/**
 * Base wikka action class.
 *
 * @package    Action
 * @subpackage Libs
 * @author     Tom Atwell <klenwell@gmail.com>
 */
class WikkaAction {
    # defaults
    var $version = '0.9';

    # internal
    var $Wikka          = NULL;
    var $Param          = array();
    var $Get            = array();
    var $Post           = array();
    var $Datastore      = array();       # active datastore list (data out)
    var $_Datastore     = array();       # loaded datastore (data in)
    var $user           = NULL;
    var $user_role      = 'viewer';      # viewer, user, or admin
    var $PageAcl        = array();
    var $is_admin       = FALSE;
    var $is_logged_in   = FALSE;
    var $is_cli         = FALSE;
    var $here           = '';

    # ACL Settings
    var $AclAllow = array(
        'all'       => '*',
        'users'     => '+',
        'admins'    => '!*',
    );

    # PhpMailer Settings
    var $PhpMailerDefault = array(
        'host'  => 'smtp.gmail.com',
        'port'  => 587,
        'debug' => 0
    );

    function __construct($Wakka=NULL, $ActionParams=array()) {
        if ( is_null($Wakka) ) {
            $this->_abort('must pass wakka object as argument for action class');
        }
        $this->Wikka = $Wakka;
        $this->Param = $ActionParams;
        $this->Get = $_GET;
        $this->Post = $_POST;
        $this->page = $this->Wikka->tag;
        $this->is_cli = defined('WIKKA_ALLOW_CLI');

        # get url path
        if ( isset($_SERVER['SCRIPT_URL']) ) {
            $this->here = $_SERVER['SCRIPT_URL'];
        }
        elseif ( isset($_SERVER['REQUEST_URI']) ) {
            $this->here = $_SERVER['REQUEST_URI'];
        }
        else {
            $this->here = $this->Wikka->GetConfigValue('base_url') . $this->page;
        }

        $this->_load_wikka_acl();
    }

    function main() {
        $this->_abort('this method must be overridden');
    }

    function output($content, $wikka_format=0) {
        if ( $wikka_format ) {
            $content = $this->Wikka->format($content);
        }
        print $content;
    }

    function action_set_up() {
        $this->_abort('this method must be overridden');
    }

    function has_param($key) {
        return isset($this->Param[$key]);
    }

    function get_param($key) {
        if ( ! $this->has_param($key) ) {
            return NULL;
        }
        return $this->Param[$key];
    }

    function get_config($key) {
        return $this->Wikka->GetConfigValue($key);
    }

    /**
     * Safe Sql
     * Use parameterized mysql queries
     */
    function safe_sql($sqlf, $ParamList) {
        $SafeParamList = array();
        foreach ( $ParamList as $value ) {
            $SafeParamList[] = mysql_real_escape_string($value);
        }
        $SprintfArgList = array_merge(array($sqlf), $SafeParamList);
        return call_user_func_array('sprintf', $SprintfArgList);
    }

    /**
     * Datastore Functions
     * These functions can be used to persist data between calls.
     *
     */
    function store($key, $value) {
        $this->Datastore[$key] = $value;
    }

    function get_store($key, $page=NULL) {
        $Datastore = $this->load_datastore($page);
        $value = ( isset($Datastore[$key]) ) ? $Datastore[$key] : NULL;
        return $value;
    }

    function load_datastore($page=NULL) {
        if ( ! $page ) {
            $page = $this->page;
        }

        if ( isset($this->_Datastore[$page]) ) {
            return $this->_Datastore[$page];
        }

        # load page
        $content = $this->load_page_body($page);

        # extract datastore
        $datastore_regex = $this->_get_datastore_regex();
        $is_matched = preg_match_all($datastore_regex, $content, $Matches);
        if ( ! $is_matched || !isset($Matches[1][0]) ) {
            return NULL;
        }

        # cache and return
        $this->_Datastore[$page] = unserialize( base64_decode($Matches[1][0]) );
        return $this->_Datastore[$page];
    }

    function save_datastore($page=NULL) {
        # serialize datastore
        $serialization = base64_encode( serialize($this->Datastore) );
        $datastore_shell = $this->_get_datastore_regex($return_shell=1);
        $datastore = sprintf($datastore_shell, $serialization);

        # purge current datastore
        $page_content = $this->purge_datastore($page);

        # save page with datastore
        #print $this->pr(array('before store'=>htmlentities($page_content)));
        $page_content = $page_content .= $datastore;
        #print $this->pr(array('after store'=>htmlentities($page_content)));
        return $this->save_page($page, $page_content);
    }

    function purge_datastore($page=NULL, $save=FALSE) {
        $page_content = $this->load_page_body($page);
        $datastore_regex = $this->_get_datastore_regex();
        $page_content = preg_replace($datastore_regex, '', $page_content);

        if ( $save ) {
            $note = 'purging datastore';
            $this->save_page($page, $page_content);
        }

        return $page_content;
    }

    function _get_datastore_regex($return_shell=FALSE) {
        $RegexParts = array(
            '""<!-- [wikka_datastore] ',
            '(.*?)',
            ' -->""'
        );

        if ( $return_shell ) {
            return sprintf('%s%s%s', $RegexParts[0], '%s', $RegexParts[2]);
        }

        $datastore_regex = sprintf('~%s%s%s~',
                                   preg_quote($RegexParts[0]),
                                   $RegexParts[1],
                                   preg_quote($RegexParts[2]));

        return $datastore_regex;
    }

    /**
     * Page-Related Database Functions
     * Effectively wrappers for wikka functions
     */
    function load_page_body($page=NULL) {
        if ( is_null($page) ) {
            $page = $this->page;
        }

        $Record = $this->Wikka->LoadPage($page, NULL, 0);
        $body = ( isset($Record['body']) ) ? $Record['body'] : NULL;
        return $body;
    }

    function create_page($tag, $body, $note, $user=NULL, $owner=NULL) {

        if ( $this->load_page_body($tag) ) {
            trigger_error("page '$tag' already exists", E_USER_NOTICE);
            return FALSE;
        }

        if ( ! $owner && $user ) {
            $owner = $user;
        }
        elseif ( ! $owner ) {
            $owner = '(Public)';
        }

        if ( ! $user ) {
            $user = '(Action Class)';
        }

        $sqlf = <<<HERESQL
INSERT INTO %s
SET
    tag = '%s',
    body = '%s',
    note = '%s',
    user = '%s',
    owner = '%s',
    latest = 'Y',
    time = NOW()
HERESQL;

        $ParamList = array(
            $this->get_config('table_prefix') . 'pages',
            $tag, $body, $note, $user, $owner
        );

        # insert new user
        $sql = $this->safe_sql($sqlf, $ParamList);
        $this->Wikka->Query($sql);
        print $this->load_page_body($tag);
    }

    function save_page($page=NULL, $content='', $note=NULL) {
        if ( is_null($page) ) {
            $page = $this->page;
        }

        if ( is_null($note) ) {
            $note = 'page saved by action class';
        }

        return $this->Wikka->SavePage($page, $content, $note);
    }

    /**
     * Mail Functions
     */
    function mail($toList, $subject, $body) {

        # gmail
        if ( $this->Wikka->GetConfigValue('use_gmail') ) {
            return $this->mail_with_gmail($toList, $subject, $body);
        }

        # php mail
        else {
            return $this->php_mail($toList, $subject, $body);
        }
    }

    /**
     * Send an email using php's mail function (server must be configured
     * properly)
     * @link  http://php.net/manual/en/function.mail.php
     */
    function php_mail($toList, $subject, $body)
    {
        $SendResults = array();
        $from_email = $this->Wikka->GetConfigValue("admin_email");

        # build recipient string
        $RecipientList = array();
        $toList = $this->_normalize_email_list($toList);
        foreach ( $toList as $tuple ) {
            $RecipientList[] = sprintf('%s <%s>', $tuple[1], $tuple[0]);
        }

        # build headers
        $HeaderList = array();
        $HeaderList[] = sprintf('From:%s', $from_email);
        $HeaderList[] = sprintf('Reply-To:%s', $from_email);

        # send to each
        $to = implode(', ', $RecipientList);
        $headers = implode("\n", $HeaderList);
        return mail($to, $subject, $body, $headers);
    }

    /**
     * Send an email through a gmail account using PhpMailer
     * @link  http://phpmailer.worxware.com/index.php?pg=examplebgmail
     */
    function mail_with_gmail($toList, $subject, $body) {

        if ( empty($this->PhpMailer) ) {
            require_once $this->Wikka->GetConfigValue('phpmailer_path');
            $this->PhpMailer = new PHPMailer();
            $this->PhpMailer->IsSMTP();
            $this->PhpMailer->SMTPAuth = TRUE;
            $this->PhpMailer->SMTPSecure = 'tls';
            $this->PhpMailer->Host = $this->PhpMailerDefault['host'];
            $this->PhpMailer->Port = $this->PhpMailerDefault['port'];
            $this->PhpMailer->SMTPDebug = ($this->PhpMailerDefault['debug']) ?
                2 : 0;
            $this->PhpMailer->Username = $this->Wikka->GetConfigValue(
                'gmail_user');
            $this->PhpMailer->Password = $this->Wikka->GetConfigValue(
                'gmail_pw');

            $from_name = $this->Wikka->GetConfigValue('gmail_name');
            if ( empty($from_name) ) {
                $from_name = $this->PhpMailer->Username;
            }
            $this->PhpMailer->SetFrom($this->PhpMailer->Username, $from_name);
            $this->PhpMailer->AddReplyTo($this->PhpMailer->Username, $from_name);
        }

        # add senders
        $toList = $this->_normalize_email_list($toList);
        foreach ( $toList as $tuple ) {
            $this->PhpMailer->AddAddress($tuple[0], $tuple[1]);
        }

        # prepare message
        $this->PhpMailer->Subject = $subject;
        $this->PhpMailer->Body = $body;

        # send
        if ( ! $this->PhpMailer->Send() ) {
            trigger_error(sprintf('PhpMailer Error: %s',
                                  $this->PhpMailer->ErrorInfo), E_USER_WARNING);
            return FALSE;
        }
        else {
            return TRUE;
        }
    }

    /**
     * Possible variations of $toList:
     *  1) string(email)
     *  2) array(email, name)
     *  3) array(email, email, ...)
     *  4) array( array(email, name), array(email, name), ... )
     *
     * @return  array  returns an array of arrays (email, name)
     */
    function _normalize_email_list($toList) {
        $EmailList = array();

        # case 1: convert string to array
        if ( ! is_array($toList) ) {
            $toList = array( $toList );
        }
        # case 2: single email/name pair
        elseif ( count($tuple) == 2 && strpos($tuple[1], '@') ) {
            $toList = array( array($tuple[0], $tuple[1]) );
        }

        # list should now be case 3 or 4
        foreach ( $toList as $tuple ) {
            # case 3: list of emails
            if ( ! is_array($tuple) ) {
                $NameSplit = explode('@', $tuple);
                $EmailList[] = array($tuple, $NameSplit[0]);
            }

            # case 4: list of email/name pairs assumed
            else {
                $EmailList[] = array($tuple[0], $tuple[1]);
            }
        }

        return $EmailList;
    }

    /**
     * Utility Methods
     */
    function pr($Array, $print=FALSE) {
        $dump = print_r($Array,1);
        if ( ! $print ) {
            return $dump;
        }
        else {
            printf('<pre>%s</pre>', $dump);
        }
    }

    function get_source_code($file) {
        return highlight_file($file, 1);
    }

    /**
     * Private Methods
     */
    function _load_wikka_acl() {
        # load user
        $this->user = $this->Wikka->GetUser();
        if ( !empty($this->user) ) {
            $this->is_admin = $this->Wikka->IsAdmin($this->user);
        }

        if ( $this->is_admin ) {
            $this->user_role = 'admin';
        }
        elseif ( $this->user ) {
            $this->user_role = 'user';
        }

        # is logged in
        $this->is_logged_in = in_array($this->user_role, array('admin', 'user'));

        # load acls
        $AclList = $this->Wikka->LoadAllACLs($this->page);
        $this->PageAcl['read'] = $AclList['read_acl'];
        $this->PageAcl['write'] = $AclList['write_acl'];
        $this->PageAcl['comment_read_acl'] = $AclList['comment_read_acl'];
        $this->PageAcl['comment_post_acl'] = $AclList['comment_post_acl'];
        $this->PageAcl['comment'] = $AclList['comment_post_acl'];   # deprecated

        return;
    }

    function _abort($msg) {
        $m = sprintf('Action Exception: %s', $msg);
        throw new Exception($m);
    }
}

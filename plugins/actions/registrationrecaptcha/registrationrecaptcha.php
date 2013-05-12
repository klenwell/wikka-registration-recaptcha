<?php
/**
 * registrationrecaptcha.php
 * Test extension of user settings action
 *
 * References
 *      http://klenwell.com/is/WikkaRegistrationRecaptcha
 *      http://klenwell.com/is/WikkaBaseActionClass
 *
 * @package     Action
 * @author      Tom Atwell <klenwell@gmail.com>
 * @copyright   Copyright 2012, Tom Atwell <klenwell@gmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU General Public License
 */

require_once 'libs/action.class.php';
require_once 'libs/userregistration.class.php';

class RegistrationRecaptchaAction extends WikkaAction {

    var $version = '0.1.20120731';

    # parameter defaults
    var $password_min_length                = 5;
    var $valid_email_pattern                = '/^.+?\@.+?\..+$/';

    # requests
    var $new_user_is_registering            = FALSE;
    var $has_submitted_recaptcha            = FALSE;
   
    # user interface
    var $button_recaptcha                   = 'Submit ReCAPTCHA';
   
    # other
    var $UserAuth                           = NULL;


    function main()
    {
        $this->action_set_up();

        # request tree
        if ( $this->new_user_is_registering ) {
            return $this->challenge_with_recaptcha();
        }
        elseif ( $this->has_submitted_recaptcha ) {
            $RecaptchaResponse = $this->validate_recaptcha();
           
            if ( $RecaptchaResponse->is_valid ) {
                return $this->complete_registration();
            }
            else {
                return $this->show_recaptcha_form($RecaptchaResponse->error);
            }
        }
        else {
            return $this->html_comment("skipping");
        }
    }
   
    function action_set_up()
    {
        $this->UserAuth = new URAuth($this->Wikka);        
       
        # these values are set in usersettings -- not sure if they're
        # available here, but let's try
        if ( defined('PASSWORD_MIN_LENGTH') ) {
            $this->password_min_length = PASSWORD_MIN_LENGTH;
        }
       
       
        if ( defined('VALID_EMAIL_PATTERN') ) {
            $this->valid_email_pattern = VALID_EMAIL_PATTERN;
        }

        # request tree
        $this->new_user_is_registering =
            ($this->Wikka->GetSafeVar('submit', 'post') == T_("Register"))
            && $this->get_config('allow_user_registration');
           
        $this->has_submitted_recaptcha =
            $this->Wikka->GetSafeVar('recaptcha_submit', 'post') == $this->button_recaptcha
            && $this->get_config('allow_user_registration');
    }
   
    function challenge_with_recaptcha() {
        # if registration is not valid, let post pass so that
        # usersettings plugin will display proper warning
        if ( !$this->is_valid_registration() ) {
            return $this->html_comment("invalid registration form");
        }
        else {
           $this->intercept_registration();
           return $this->show_recaptcha_form();
        }
    }
   
    function is_valid_registration() {
        # this is effectively lifted from usersettings.php. Very un-DRY.
        $name = trim($this->Wikka->GetSafeVar('name', 'post'));
        $email = trim($this->Wikka->GetSafeVar('email', 'post'));
        $password = $this->Wikka->GetSafeVar('password', 'post');
        $confpassword = $this->Wikka->GetSafeVar('confpassword', 'post');

        // validate input
        switch(TRUE) {
            case (FALSE===$this->UserAuth->URAuthVerify()):
            case (isset($_POST['name']) && TRUE === $this->Wikka->existsUser(
                $this->Wikka->GetSafeVar('name', 'post'))):
            case (strlen($name) == 0):
            case (!$this->Wikka->IsWikiName($name)):
            case ($this->Wikka->ExistsPage($name)):
            case (strlen($password) == 0):
            case (preg_match("/ /", $password)):
            case (strlen($password) < $this->password_min_length):
            case (strlen($confpassword) == 0):
            case ($confpassword != $password):
            case (strlen($email) == 0):
            case (!preg_match($this->valid_email_pattern, $email)):
                return FALSE;
            default:
                return TRUE;
        }
    }  
   
    function validate_recaptcha() {
        require_once('3rdparty/plugins/recaptcha/recaptchalib.php');

        $RecaptchaResponse = recaptcha_check_answer(
            $this->Wikka->GetConfigValue('rc_private_key'),
            $_SERVER['REMOTE_ADDR'],
            $_POST["recaptcha_challenge_field"],
            $_POST["recaptcha_response_field"]
        );

        return $RecaptchaResponse;
    }

    function complete_registration() {
        $this->unintercept_registration();
        return $this->html_comment("completing registration");
    }
   
    function intercept_registration() {
        $_SESSION['reg_intercept'] = $_POST;
        $_POST['submit'] = NULL;
    }
   
    function unintercept_registration() {
        $_POST = $_SESSION['reg_intercept'];
        $_SESSION['reg_intercept'] = NULL;
    }
   
    function show_recaptcha_form($error=NULL) {
        require_once('3rdparty/plugins/recaptcha/recaptchalib.php');
        $public_key = $this->Wikka->GetConfigValue('rc_public_key');

        $htmlf = <<<XHTML
<h5>To deter bots and spammers, please complete this ReCAPTCHA challenge</h5>
<div class="recaptcha">
    %s
    %s
    <input name="recaptcha_submit" type="submit" value="{$this->button_recaptcha}" />
    %s
</div>
XHTML;
        $recaptcha_form = sprintf( $htmlf,
            $this->Wikka->FormOpen(),
            recaptcha_get_html($public_key, $error),
            $this->Wikka->FormClose()
        );
       
        return $recaptcha_form;
    }
   
    function output($content) {
        print $content;
    }
   
    function html_comment($comment) {
        return sprintf("\n\n<!-- RegistrationRecaptcha: %s -->\n\n", $comment);
    }
   

}

# Main Routine
try {
    $Action = new RegistrationRecaptchaAction($this, $vars);
    $content = $Action->main();
    $Action->output($content);
}
catch(Exception $e) {
    printf('<em class="error">%s</em>', $e->getMessage());
}
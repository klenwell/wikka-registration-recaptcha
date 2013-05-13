# wikka-registration-recaptcha

This plugin action presents new users registering for your wikka site with a reCAPTCHA challenge. It is designed to work seamlessly with the core UserSettings plugin.

For more information, see [klenwell.com](http://klenwell.com/is/WikkaRegistrationRecaptcha).

## Installation
#### Download source code and unzip:

    cd /tmp
    wget https://github.com/klenwell/wikka-registration-recaptcha/archive/master.zip
    unzip master.zip

#### Copy into wiki root dir

For example: `cp -Rv /tmp/wikka-registration-recaptcha-master/{3rdparty,libs,plugins} /var/www/wiki`

#### Add reCAPTCHA keys to config file

If you don't have keys, get them here: [https://www.google.com/recaptcha/admin](https://www.google.com/recaptcha/admin/create)

Add the following lines to `wikka.config.php`:

    # RegistrationRecaptcha Action Settings
    'recaptcha_domain'  => 'TBA',   # your wiki's domain
    'rc_public_key'     => 'TBA',
    'rc_private_key'    => 'TBA',

Edit your **UserSettings** login page so that the `{{RegistrationRecaptcha}}` action precedes `{{UserSettings}}` like so:
   
    {{RegistrationRecaptcha}}
    {{UserSettings}}
    {{nocomments}}
   
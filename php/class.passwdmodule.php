<?php
/**
 * Passwd module.
 * Module that will be used to change passwords of the user
 */

class PasswdModule extends Module
{
        /**
         * Process the incoming events that were fire by the client.
         */
        public function execute()
        {
                foreach($this->data as $actionType => $actionData)
                {
                        if(isset($actionType)) {
                                try {
                                        switch($actionType)
                                        {
                                                case 'save':
                                                        $this->save($actionData);
                                                        break;
                                                default:
                                                        $this->handleUnknownActionType($actionType);
                                        }
                                } catch (MAPIException $e) {
                                        $this->sendFeedback(false, $this->errorDetailsFromException($e));
                                }

                        }
                }
        }

        /**
         * Change the password of user. Do some calidation and call proper methods based on
         * zarafa setup.
         * @param {Array} $data data sent by client.
         */
        public function save($data)
        {
                $errorMessage = '';

                // some sanity checks
                if(empty($data)) {
                        $errorMessage = dgettext("plugin_passwd", 'No data received.');
                }

                if(empty($data['username'])) {
                        $errorMessage = dgettext("plugin_passwd", 'User name is empty.');
                }

                if(empty($data['current_password'])) {
                        $errorMessage = dgettext("plugin_passwd", 'Current password is empty.');
                }

                if(empty($data['new_password']) || empty($data['new_password_repeat'])) {
                        $errorMessage = dgettext("plugin_passwd", 'New password is empty.');
                }

                if($data['new_password'] !== $data['new_password_repeat']) {
                        $errorMessage = dgettext("plugin_passwd", 'New passwords do not match.');
                }

                if(empty($errorMessage)) {
                        if(PLUGIN_PASSWD_LDAP) {
                                $this->saveInLDAP($data);
                        } else {
                                $this->saveInDB($data);
                        }
                } else {
                        $this->sendFeedback(false, array(
                                'type' => ERROR_ZARAFA,
                                'info' => array(
                                        'display_message' => $errorMessage
                                )
                        ));
                }
        }

        /**
         * Function will connect to LDAP and will try to modify user's password.
         * @param {Array} $data data sent by client.
         */
        public function saveInLDAP($data)
        {
                $errorMessage = '';

                // connect to LDAP server
                $ldapconn = ldap_connect(PLUGIN_PASSWD_LDAP_URI);

                // check connection is successfull
                if(ldap_errno($ldapconn) === 0) {
                        // get the users uid, if we have a multi tenant installation then remove company name from user name
                        if (PLUGIN_PASSWD_LOGIN_WITH_TENANT){
                                $parts = explode('@', $data['username']);
                                $uid = $parts[0];
                        } else {
                                $uid = $data['username'];
                        }

                        // check if we should use tls!
                        if(strrpos(PLUGIN_PASSWD_LDAP_URI, "ldaps://", -strlen(PLUGIN_PASSWD_LDAP_URI)) === FALSE && PLUGIN_PASSWD_LDAP_USE_TLS === true) {
                                ldap_start_tls($ldapconn);
                        }

                        // set connection parametes
                        ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
                        ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, 0);

                        // now bind to the ldap server to search the user dn
                        ldap_bind($ldapconn, PLUGIN_PASSWD_LDAP_BIND_DN, PLUGIN_PASSWD_LDAP_BIND_PW);

                        // set ldapfilter
                        $ldap_filter = "(&(" . PLUGIN_PASSWD_LDAP_USER_LOGIN_ATTR . "=" . $uid . ")" . PLUGIN_PASSWD_LDAP_FILTER . ")";

                        // search for the user dn that will be used to do login into LDAP
                        $userdn = ldap_search (
                                $ldapconn,                                              // connection-identify
                                PLUGIN_PASSWD_LDAP_BASEDN,              // basedn
                                $ldap_filter
                        );

                        if ($userdn) {
                                $entries = ldap_get_entries($ldapconn, $userdn);
                                $userdn = $entries[0]['dn'];

                                // bind to ldap directory
                                // login with current password if that fails then current password is wrong
                                ldap_bind($ldapconn, $userdn, $data['current_password']);

                                if(ldap_errno($ldapconn) === 0) {

                                        $passwd = $data['new_password'];
                                        $oldpass = $data['current_password'];

                                        if ($this->checkPasswordStrenth($passwd)) {
                                                $msg = $this->change_password($ldapconn, $userdn, $passwd, $oldpass);

                                                if ($msg === "passwordchanged") {
                                                        // password changed successfully

                                                        // send feedback to client
                                                        $this->sendFeedback(true, array(
                                                                'info' => array(
                                                                        'display_message' => dgettext("plugin_passwd", 'Password is changed successfully.')
                                                                )
                                                        ));
                                                        WebAppSession::getInstance()->destroy();
                                                        header("Location: /");
                                                } else {
                                                        $errorMessage = dgettext("plugin_passwd", 'Password is not changed. Error: ' . $msg);
                                                }
                                        } else {
                                                $errorMessage = dgettext("plugin_passwd", 'Password is weak. Password should contain capital, non-capital letters and numbers. Password should have 8 to 20 characters.');
                                        }
                                } else {
                                        $errorMessage = dgettext("plugin_passwd", 'Current password does not match.');
                                }
                                // release ldap-bind
                                ldap_unbind($ldapconn);
                        }
                }

                if(!empty($errorMessage)) {
                        $this->sendFeedback(false, array(
                                'type' => ERROR_ZARAFA,
                                'info' => array(
                                        'ldap_error' => ldap_errno($ldapconn),
                                        'ldap_error_name' => ldap_error($ldapconn),
                                        'display_message' => $errorMessage
                                )
                        ));
                }
        }

        /**
         * Function will try to change user's password via MAPI in SOAP connection.
         * @param {Array} $data data sent by client.
         */
        public function saveInDB($data)
        {
                $errorMessage = '';
                $passwd = $data['new_password'];

                /*
                // get current session password
                $sessionPass = $_SESSION['password'];
                // if user has openssl module installed
                if (function_exists("openssl_decrypt")) {
                        if (version_compare(phpversion(), "5.3.3", "<")) {
                                $sessionPass = openssl_decrypt($sessionPass, "des-ede3-cbc", PASSWORD_KEY, 0);
                        } else {
                                $sessionPass = openssl_decrypt($sessionPass, "des-ede3-cbc", PASSWORD_KEY, 0, PASSWORD_IV);
                        }

                        if (!$sessionPass) {
                                $sessionPass = $_SESSION['password'];
                        }
                }
                */
                // Get current user password
                $encryptionStore = EncryptionStore::getInstance();
                $sessionPass = $encryptionStore->get('password');

                if($data['current_password'] === $sessionPass) {
                        if ($this->checkPasswordStrenth($passwd)) {
                                // all information correct, change password
                                $store = $GLOBALS['mapisession']->getDefaultMessageStore();
                                $userinfo = mapi_zarafa_getuser_by_name($store, $data['username']);

                                if (mapi_zarafa_setuser($store, $userinfo['userid'], $data['username'], $userinfo['fullname'], $userinfo['emailaddress'], $passwd, 0, $userinfo['admin'])) {
                                        // password changed successfully

                                /* Not able to find a way, session is discarded and user should log in again
                                        // write new password to session because we don't want user to re-authenticate
                                        session_start();
                                        // if user has openssl module installed
                                        if (function_exists("openssl_encrypt")) {
                                                // In PHP 5.3.3 the iv parameter was added
                                                if (version_compare(phpversion(), "5.3.3", "<")) {
                                                        $_SESSION['password'] = openssl_encrypt($passwd, "des-ede3-cbc", PASSWORD_KEY, 0);
                                                } else {
                                                        $_SESSION['password'] = openssl_encrypt($passwd, "des-ede3-cbc", PASSWORD_KEY, 0, PASSWORD_IV);
                                                }
                                        } else {
                                                $_SESSION['password'] = $passwd;
                                        }
                                        session_write_close();

                                        // send feedback to client
                                        $this->sendFeedback(true, array(
                                                'info' => array(
                                                        'display_message' => dgettext("plugin_passwd", 'Password is changed successfully.')
                                                )
                                        ));
                                        */
                                        // Give the session a new id
                                        session_regenerate_id();
                                } else {
                                        $errorMessage = dgettext("plugin_passwd", 'Password is not changed.');
                                }
                        } else {
                                $errorMessage = dgettext("plugin_passwd", 'Password is weak. Password should contain capital, non-capital letters and numbers. Password should have 8 to 20 characters.');
                        }
                } else {
                        $errorMessage = dgettext("plugin_passwd", 'Current password does not match.');
                }

                if(!empty($errorMessage)) {
                        $this->sendFeedback(false, array(
                                'type' => ERROR_ZARAFA,
                                'info' => array(
                                        'display_message' => $errorMessage
                                )
                        ));
                }
        }

        /**
         * Function will check strength of the password and if it does not meet minimum requirements then
         * will return false.
         * Password should meet the following criteria:
         * - min. 8 chars, max. 20
         * - contain caps and noncaps characters
         * - contain numbers
         * @param {String} $password password which should be checked.
         * @return {Boolean} true if password passes the minimum requirement else false.
         */
        public function checkPasswordStrenth($password)
        {
                if (preg_match("#.*^(?=.{8,20})(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).*$#", $password)) {
                        return true;
                } else {
                        return false;
                }
        }

        /**
         * Function will generate SSHA hash to use to store user's password in LDAP.
         * @param {String} $text text based on which hash will be generated.
         */
        function sshaEncode($text)
        {
                $salt = '';
                for ($i=1; $i<=10; $i++) {
                        $salt .= substr('0123456789abcdef', rand(0, 15), 1);
                }

                $hash = '{SSHA}' . base64_encode(pack('H*',sha1($text . $salt)) . $salt);

                return $hash;
        }

        function make_ad_password($password) {
                $password = "\"" . $password . "\"";
                $adpassword = mb_convert_encoding($password, "UTF-16LE", "UTF-8");
                return $adpassword;
        }


        function change_password( $ldap, $dn, $password, $oldpassword ) {
                $result = "";
                $error_code = "";
                $error_msg = "";
                $ppolicy_error_code = "";

                $time = time();

                # Transform password value
                $password = $this->make_ad_password($password);

                # Set password value
                $userdata["unicodePwd"] = $password;

                # Commit modification on directory

                # The AD password change procedure is modifying the attribute unicodePwd by
                # first deleting unicodePwd with the old password and them adding it with the
                # the new password
                $oldpassword = $this->make_ad_password($oldpassword);

                $modifications = array(
                        array(
                                "attrib" => "unicodePwd",
                                "modtype" => LDAP_MODIFY_BATCH_REMOVE,
                                "values" => array($oldpassword),
                        ),
                        array(
                                "attrib" => "unicodePwd",
                                "modtype" => LDAP_MODIFY_BATCH_ADD,
                                "values" => array($password),
                        ),
                );

                $bmod = ldap_modify_batch($ldap, $dn, $modifications);
                $error_code = ldap_errno($ldap);
                $error_msg = ldap_error($ldap);

                if ( !isset($error_code) ) {
                        $result = "ldaperror";
                } elseif ( $error_code > 0 ) {
                        $result = "passworderror";
                        error_log("LDAP - Modify password error $error_code ($error_msg)");
                        if ( $ppolicy_error_code === 5 ) { $result = "badquality"; }
                        if ( $ppolicy_error_code === 6 ) { $result = "tooshort"; }
                        if ( $ppolicy_error_code === 7 ) { $result = "tooyoung"; }
                        if ( $ppolicy_error_code === 8 ) { $result = "inhistory"; }
                } else {
                        $result = "passwordchanged";
                }

                return $result;
        }
}

<?php
/**
 * Created by JetBrains PhpStorm.
 * User: root
 * Date: 14/12/11
 * Time: 14:12
 * To change this template use File | Settings | File Templates.
 */


/**
 * LDAP authentication plugin.
 * extended to fetch LDAP groups and to be 'group aware'
 */
class GAAuthLdap extends AuthLdap {

    /**
     * Constructor.
     */

    function __construct($instanceid) {
        global $CFG;
        //fetch all instances data
        parent::__construct($instanceid);
        //TODO must be in some setting screen Currently in config.php
        $this->config['group_attribute'] = !empty($CFG->ldap_group_attribute) ? $CFG->ldap_group_attribute : 'cn';
        $this->config['group_class'] = strtolower(!empty($CFG->ldap_group_class) ? $CFG->ldap_group_class : 'groupOfUniqueNames');

        //argh phpldap convert uniqueMember to lowercase array keys when returning the list of members  ...
        $this->config['memberattribute'] = strtolower(!empty($CFG->ldap_member_attribute) ? $CFG->ldap_member_attribute : 'uniquemember');
        $this->config['memberattribute_isdn'] = !empty($CFG->ldap_member_attribute_isdn) ? $CFG->ldap_member_attribute_isdn : 1;

    }


    /**
     * this class allows to change default config option read from the database
     * @param $key
     * @param $value
     */
    function set_config ($key,$value) {
        $this->config[$key]=$value;
    }

    /**
     * for debugging purpose
     * @return array  current config for printing
     */
    function get_config() {
        return $this->config;
    }

    /**
     * return all groups declared in LDAP
     * @return string[]
     */

    function ldap_get_grouplist($filter = "*") {
        /// returns all groups from ldap servers

        global $CFG;

        // print_string('connectingldap', 'auth_ldap');
        $ldapconnection = $this->ldap_connect();

        $fresult = array();

        if ($filter == "*") {
            $filter = "(&(" . $this->config['group_attribute'] . "=*)(objectclass=" . $this->config['group_class'] . "))";
        }

        $contexts = explode(';', $this->config['contexts']);

        foreach ($contexts as $context) {
            $context = trim($context);
            if (empty ($context)) {
                continue;
            }

            if ($this->config['search_sub']== 'yes') {
                //use ldap_search to find first group from subtree
                $ldap_result = ldap_search($ldapconnection, $context, $filter, array(
                    $this->config['group_attribute']
                ));
            } else {
                //search only in this context
                $ldap_result = ldap_list($ldapconnection, $context, $filter, array(
                    $this->config['group_attribute']
                ));
            }

            $groups = ldap_get_entries($ldapconnection, $ldap_result);

            //add found groups to list
            for ($i = 0; $i < count($groups) - 1; $i++) {
                array_push($fresult, ($groups[$i][$this->config['group_attribute']][0]));
            }
        }
        @ldap_close($ldapconnection);
        return $fresult;
    }

    /**
     * serach for group members on a openLDAP directory
     * return string[] array of usernames
     */

    function ldap_get_group_members_rfc($group) {
        global $CFG;

        $ret = array();
        $ldapconnection = $this->ldap_connect();

        if (function_exists('textlib_get_instance')) {
            $textlib = textlib_get_instance();
            $group = $textlib->convert($group, 'utf-8', $this->config['ldapencoding']);
        }
        if ($CFG->debug_ldap_groupes) {
            moodle_print_object("connexion ldap: ", $ldapconnection);
        }
        if (!$ldapconnection) {
            return $ret;
        }

        $queryg = "(&(cn=" . trim($group) . ")(objectClass={$this->config['group_class']}))";
        if ($CFG->debug_ldap_groupes) {
            moodle_print_object("queryg: ", $queryg);
        }

        $contexts = explode(';', $this->config['contexts']);
        if (!empty ($this->config['create_context'])) {
            array_push($contexts, $this->config['create_context']);
        }

        foreach ($contexts as $context) {
            $context = trim($context);
            if (empty ($context)) {
                continue;
            }

            $resultg = ldap_search($ldapconnection, $context, $queryg);

            if (!empty ($resultg) AND ldap_count_entries($ldapconnection, $resultg)) {
                $groupe = ldap_get_entries($ldapconnection, $resultg);
                if ($CFG->debug_ldap_groupes) {
                    moodle_print_object("groupe: ", $groupe);
                }

                //todo tester existence du groupe !!!
                for ($g = 0; $g < (sizeof($groupe[0][$this->config['memberattribute']]) - 1); $g++) {

                    $membre = trim($groupe[0][$this->config['memberattribute']][$g]);
                    if ($membre != "") { //*3
                        if ($CFG->debug_ldap_groupes) {
                            moodle_print_object("membre : ", $membre);
                        }
                        if ($this->config['memberattribute_isdn']) {

                            $membre = $this->get_account_bydn($this->config['memberattribute'], $membre);
                        }

                        if ($membre) {
                            $ret[] = $membre;
                        }

                    }
                }
            }
        }

        if ($CFG->debug_ldap_groupes) {
            moodle_print_object("retour get_g_m ", $ret);
        }
        @ldap_close($ldapconnection);
        return $ret;
    }

    /**
     * specific serach for active Directory  problems if more than 999 members
     * recherche paginée voir http://forums.sun.com/thread.jspa?threadID=578347
     */

    function ldap_get_group_members_ad($group) {
        global $CFG;

        $ret = array();
        $ldapconnection = $this->ldap_connect();
        if ($CFG->debug_ldap_groupes) {
            moodle_print_object("connexion ldap: ", $ldapconnection);
        }
        if (!$ldapconnection) {
            return $ret;
        }

        $textlib = textlib_get_instance();
        $group = $textlib->convert($group, 'utf-8', $this->config['ldapencoding']);

        $queryg = "(&(cn=" . trim($group) . ")(objectClass={$this->config['group_class']}))";
        if ($CFG->debug_ldap_groupes) {
            moodle_print_object("queryg: ", $queryg);
        }

        $size = 999;


        $contexts = explode(';', $this->config['contexts']);
        if (!empty ($this->config['create_context'])) {
            array_push($contexts, $this->config['create_context']);
        }

        foreach ($contexts as $context) {
            $context = trim($context);
            if (empty ($context)) {
                continue;
            }
            $start = 0;
            $end = $size;
            $fini = false;

            while (!$fini) {
                //recherche paginée par paquet de 1000
                $attribut = $this->config['memberattribute'] . ";range=" . $start . '-' . $end;
                $resultg = ldap_search($ldapconnection, $context, $queryg, array(
                    $attribut
                ));

                if (!empty ($resultg) AND ldap_count_entries($ldapconnection, $resultg)) {
                    $groupe = ldap_get_entries($ldapconnection, $resultg);
                    if ($CFG->debug_ldap_groupes) {
                        moodle_print_object("groupe: ", $groupe);
                    }

                    // a la derniere passe, AD renvoie member;Range=numero-* !!!
                    if (empty ($groupe[0][$attribut])) {
                        $attribut = $this->config['memberattribute'] . ";range=" . $start . '-*';
                        $fini = true;
                    }

                    for ($g = 0; $g < (sizeof($groupe[0][$attribut]) - 1); $g++) {
                        $membre = trim($groupe[0][$this->config['memberattribute']][$g]);
                        if ($membre != "") { //*3
                            if ($CFG->debug_ldap_groupes) {
                                moodle_print_object("membre : ", $membre);
                            }
                            if ($this->config['memberattribute_isdn']) {

                                $membre = $this->get_account_bydn($this->config['memberattribute'], $membre);
                            }

                            if ($membre) {
                                $ret[] = $membre;
                            }

                        }
                    }
                } else {
                    $fini = true;
                }
                $start = $start + $size;
                $end = $end + $size;
            }
        }
        if ($CFG->debug_ldap_groupes) {
            moodle_print_object("retour get_g_m ", $ret);
        }
        @ldap_close($ldapconnection);
        return $ret;
    }

    /**
     * should return a Mahara account from its LDAP dn
     * @param string $dnid
     * @param string $dn
     */
    function get_account_bydn($dnid, $dn) {
        global $CFG;
        if ($this->config['memberattribute_isdn']) {
            $dn_tmp1 = explode(",", $dn);
            if (count($dn_tmp1) > 1) {
                // normalement le premier élément est soir cn=..., soit uid=...
                if ($CFG->debug_ldap_groupes) {
                    moodle_print_object("membre_tmp1: ", $dn_tmp1);
                }
                //essaie de virer la suite
                $dn_tmp2 = explode("=", trim($dn_tmp1[0]));
                if ($CFG->debug_ldap_groupes) {
                    moodle_print_object("membre_tmp2: ", $dn_tmp2);
                }
                if ($dn_tmp2[0] == $this->config['user_attribute']) //celui de la config
                {
                    return $dn_tmp2[1];
                }
                else {
                    //intervenir ici !!!
                    if ($CFG->debug_ldap_groupes) {
                        moodle_print_object("$dn attribut trouvé {$this->config['user_attribute']} different de ", $this->config['user_attribute'],'');
                    }
                    return false;
                }

            } else
            {
                return $dn;
            }

        } else {
            return $dn;
        }
    }

    /**
     * rev 1012 traitement de l'execption avec active directory pour des groupes >1000 membres
     * voir http://forums.sun.com/thread.jspa?threadID=578347
     *
     * @return string[] an array of username indexed by Moodle's userid
     */
    function ldap_get_group_members($groupe) {
        global $DB;
        if ($this->config['user_type'] == "ad") {
            $members = $this->ldap_get_group_members_ad($groupe);
        }
        else
        {
            $members = $this->ldap_get_group_members_rfc($groupe);
        }
        /*
        $ret = array();
        foreach ($members as $member) {
            $params = array(
                'username' => $member
            );
            if ($user = $DB->get_record('user', $params, 'id,username')) {
                $ret[$user->id] = $user->username;
            }
        }
        return $ret;
        */
        return $members;
    }


}


/**
 * Returns all authentication instances using the CAS method
 *
 */
function auth_instance_get_cas_records() {
    $result = get_records_select_array('auth_instance', "authname = 'cas'");
    $result = empty($result) ? array() : $result;
    return $result;
}

/**
 * Returns all authentication instances using the LDAP method
 *
 */
function auth_instance_get_ldap_records() {
    $result = get_records_select_array('auth_instance', "authname = 'ldap'");
    $result = empty($result) ? array() : $result;
    return $result;
}

function auth_instance_get_matching_instances($institutionname) {
    $final = array();
    $result = array_merge(auth_instance_get_cas_records(), auth_instance_get_ldap_records());
    foreach ($result as $record) {
        if ($record->institution == $institutionname) {
            $final[] = $record;
        }
    }
    return $final;

}

function ldap_sync_filter_name($name, $includes, $excludes) {
    global $CFG;
    if (!empty($includes)) {
        foreach ($includes as $regexp) {
            if (empty($regexp)) {
                continue;
            }
            if (!filter_var($name, FILTER_VALIDATE_REGEXP, array("options" => array('regexp' => '/' . $regexp . '/')))) {
                if ($CFG->debug_ldap_groupes) {
                    print ($name . " refusé par includes \n");
                }
                return false;
            }
        }

    }
    if (!empty($excludes)) {
        foreach ($excludes as $regexp) {
            if (empty($regexp)) {
                continue;
            }
            if (filter_var($name, FILTER_VALIDATE_REGEXP, array("options" => array('regexp' => '/' . $regexp . '/')))) {
                if ($CFG->debug_ldap_groupes) {
                    print ($name . " refusé par excludes \n");
                }
                return false;
            }
        }
    }
    return true;
}


function moodle_print_object($title, $obj) {
    print $title;
    if (is_object($obj) || is_array($obj))
        print_r($obj);
    else
        print ($obj."\n");
}


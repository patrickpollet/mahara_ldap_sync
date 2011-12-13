<?php


// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * cohort sync with LDAP groups script.
 *
 * This script is meant to be called from a cronjob to sync moodle's cohorts
 * registered in LDAP groups where the CAS/LDAP backend acts as 'master'.
 *
 * Sample cron entry:
 * # 5 minutes past 4am
 * 5 4 * * * $sudo -u www-data /usr/bin/php /var/www/moodle/auth/cas/cli/sync_cohorts.php
 *
 * Notes:
 *   - it is required to use the web server account when executing PHP CLI scripts
 *   - you need to change the "www-data" to match the apache user account
 *   - use "su" if "sudo" not available
 *   - If you have a large number of users, you may want to raise the memory limits
 *     by passing -d momory_limit=256M
 *   - For debugging & better logging, you are encouraged to use in the command line:
 *     -d log_errors=1 -d error_reporting=E_ALL -d display_errors=0 -d html_errors=0
 *
 *THis script should be run some time after /var/www/moodle/auth/cas/cli/sync_users.php
 *
 * Performance notes:
 * We have optimized it as best as we could for PostgreSQL and MySQL, with 27K students
 * we have seen this take 10 minutes.
 *
 * @package    auth
 * @subpackage CAS
 * @copyright  2010 Patrick Pollet - based on code by Jeremy Guittirez
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require (dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once ($CFG->dirroot . '/group/lib.php');
require_once ($CFG->dirroot . '/cohort/lib.php');

require_once ($CFG->dirroot . '/auth/ldap/auth.php');

/**
 * CAS authentication plugin.
 * extended to fetch LDAP groups and to be cohort aware
 */
class auth_plugin_cohort extends auth_plugin_ldap {

    /**
    * Constructor.
    */

    function auth_plugin_cohort() {
        global $CFG;
        $this->authtype = 'cas';
        $this->roleauth = 'auth_cas';
        $this->errorlogtag = '[AUTH CAS] ';
        $this->init_plugin($this->authtype);
        //TODO must be in some setting screen Currently in config.php
        $this->config->group_attribute = !empty($CFG->ldap_group_attribute)?$CFG->ldap_group_attribute:'cn';
        $this->config->group_class = !empty($CFG->ldap_group_class )?$CFG->ldap_group_class :'groupOfUniqueNames';

        // print_r($this->config);
    }

    /**
     * return all groups declared in LDAP
     * @return string[]
     */

    function ldap_get_grouplist($filter = "*") {
        /// returns all groups from ldap servers

        global $CFG, $DB;

        print_string('connectingldap', 'auth_ldap');
        $ldapconnection = $this->ldap_connect();

        $fresult = array ();

        if ($filter == "*") {
            $filter = "(&(" . $this->config->group_attribute . "=*)(objectclass=" . $this->config->group_class . "))";
        }

        $contexts = explode(';', $this->config->contexts);
        if (!empty ($this->config->create_context)) {
            array_push($contexts, $this->config->create_context);
        }

        foreach ($contexts as $context) {
            $context = trim($context);
            if (empty ($context)) {
                continue;
            }

            if ($this->config->search_sub) {
                //use ldap_search to find first group from subtree
                $ldap_result = ldap_search($ldapconnection, $context, $filter, array (
                    $this->config->group_attribute
                ));
            } else {
                //search only in this context
                $ldap_result = ldap_list($ldapconnection, $context, $filter, array (
                    $this->config->group_attribute
                ));
            }

            $groups = ldap_get_entries($ldapconnection, $ldap_result);

            //add found groups to list
            for ($i = 0; $i < count($groups) - 1; $i++) {
                array_push($fresult, ($groups[$i][$this->config->group_attribute][0]));
            }
        }
        $this->ldap_close();
        return $fresult;
    }

    /**
     * serach for group members on a openLDAP directory
     * return string[] array of usernames
     */

    function ldap_get_group_members_rfc($group) {
        global $CFG;

        $ret = array ();
        $ldapconnection = $this->ldap_connect();

        $textlib = textlib_get_instance();
        $group = $textlib->convert($group, 'utf-8', $this->config->ldapencoding);

        if ($CFG->debug_ldap_groupes)
            print_object("connexion ldap: ", $ldapconnection);
        if (!$ldapconnection)
            return $ret;

        $queryg = "(&(cn=" . trim($group) . ")(objectClass={$this->config->group_class}))";
        if ($CFG->debug_ldap_groupes)
            print_object("queryg: ", $queryg);

        $contexts = explode(';', $this->config->contexts);
        if (!empty ($this->config->create_context)) {
            array_push($contexts, $this->config->create_context);
        }

        foreach ($contexts as $context) {
            $context = trim($context);
            if (empty ($context)) {
                continue;
            }

            $resultg = ldap_search($ldapconnection, $context, $queryg);

            if (!empty ($resultg) AND ldap_count_entries($ldapconnection, $resultg)) {
                $groupe = ldap_get_entries($ldapconnection, $resultg);
                if ($CFG->debug_ldap_groupes)
                    print_object("groupe: ", $groupe);

                //todo tester existence du groupe !!!
                for ($g = 0; $g < (sizeof($groupe[0][$this->config->memberattribute]) - 1); $g++) {

                    $membre = trim($groupe[0][$this->config->memberattribute][$g]);
                    if ($membre != "") { //*3
                        if ($CFG->debug_ldap_groupes)
                            print_object("membre : ", $membre);

                        // la cle count contient le nombre de membres
                        // 3 cas : 1 - membre est de la forme  cn=xxxxxx, ou =zzzz ....
                        //         2 - membre est de la forme uid=login,ou=people,dc=insa_lyon,dc=fr
                        //         3 - membre est simplement un login
                        // récupération de la chaîne membre
                        // vérification du format
                        $membre_tmp1 = explode(",", $membre);
                        if (count($membre_tmp1) > 1) {
                            // normalement le premier élément est soir cn=..., soit uid=...
                            if ($CFG->debug_ldap_groupes)
                                print_object("membre_tpl1: ", $membre_tmp1);
                            //essaie de virer la suite
                            $membre_tmp2 = explode("=", trim($membre_tmp1[0]));
                            if ($CFG->debug_ldap_groupes)
                                print_object("membre_tpl2: ", $membre_tmp2);
                            //pas le peine d'aller dans le ldap si c'est bon
                            if ($membre_tmp2[0] == $this->config->user_attribute) //celui de la config
                                $ret[] = $membre_tmp2[1];
                            else {
                                //intervenir ici !!!
                                if ($CFG->debug_ldap_groupes)
                                    print_object("attribut trouvé different de ", $this->config->user_attribute);
                                // rev 1012 Lyon1 (AD)
                                if ($this->config->memberattribute_isdn) {
                                    // allez chercher son "login" (uid)
                                    if ($cpt = $this->get_account_bydn($membre_tmp2[0], $membre_tmp2[1]))
                                        $ret[] = $cpt->username;
                                }
                            }

                        } else
                            $ret[] = $membre;
                    }
                }
            }
        }
        if ($CFG->debug_ldap_groupes)
            print_object("retour get_g_m ", $ret);
        $this->ldap_close();
        return $ret;
    }

    /**
     * specific serach for active Directory  problems if more than 999 members
     * recherche paginée voir http://forums.sun.com/thread.jspa?threadID=578347
     */

    function ldap_get_group_members_ad($group) {
        global $CFG;

        $ret = array ();
        $ldapconnection = $this->ldap_connect();
        if ($CFG->debug_ldap_groupes)
            print_object("connexion ldap: ", $ldapconnection);
        if (!$ldapconnection)
            return $ret;

        $textlib = textlib_get_instance();
        $group = $textlib->convert($group, 'utf-8', $this->config->ldapencoding);

        $queryg = "(&(cn=" . trim($group) . ")(objectClass={$this->config->group_class}))";
        if ($CFG->debug_ldap_groupes)
            print_object("queryg: ", $queryg);

        $size = 999;


        $contexts = explode(';', $this->config->contexts);
        if (!empty ($this->config->create_context)) {
            array_push($contexts, $this->config->create_context);
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
                $attribut = $this->config->memberattribute . ";range=" . $start . '-' . $end;
                $resultg = ldap_search($ldapconnection, $context, $queryg, array (
                    $attribut
                ));

                if (!empty ($resultg) AND ldap_count_entries($ldapconnection, $resultg)) {
                    $groupe = ldap_get_entries($ldapconnection, $resultg);
                    if ($CFG->debug_ldap_groupes)
                        print_object("groupe: ", $groupe);

                    // a la derniere passe, AD renvoie member;Range=numero-* !!!
                    if (empty ($groupe[0][$attribut])) {
                        $attribut = $this->config->memberattribute . ";range=" . $start . '-*';
                        $fini = true;
                    }

                    for ($g = 0; $g < (sizeof($groupe[0][$attribut]) - 1); $g++) {

                        $membre = trim($groupe[0][$attribut][$g]);
                        if ($membre != "") { //*3
                            if ($CFG->debug_ldap_groupes)
                                print_object("membre : ", $membre);

                            $membre_tmp1 = explode(",", $membre);
                            if (count($membre_tmp1) > 1) {
                                // normalement le premier élément est soir cn=..., soit uid=...

                                if ($CFG->debug_ldap_groupes)
                                    print_object("membre_tpl1: ", $membre_tmp1);
                                //essaie de virer la suite
                                $membre_tmp2 = explode("=", trim($membre_tmp1[0]));
                                if ($CFG->debug_ldap_groupes)
                                    print_object("membre_tpl2: ", $membre_tmp2);
                                //pas le peine d'aller dans le ldap si c'est bon
                                if ($membre_tmp2[0] == $this->config->user_attribute) //celui de la config
                                    $ret[] = $membre_tmp2[1];
                                else {
                                    //intervenir ici !!!
                                    if ($CFG->debug_ldap_groupes)
                                        print_object("attribut trouvé different de ", $this->config->user_attribute);
                                    // rev 1012 Lyon1 (AD)
                                    if ($this->config->memberattribute_isdn) {
                                        // allez chercher son "login" (uid)
                                        if ($cpt = $this->get_account_bydn($membre_tmp2[0], $membre_tmp2[1]))
                                            $ret[] = $cpt->username;
                                    }
                                }

                            } else
                                $ret[] = $membre;

                        }
                    }
                } else
                    $fini = true;
                $start = $start + $size;
                $end = $end + $size;
            }
        }
        if ($CFG->debug_ldap_groupes)
            print_object("retour get_g_m ", $ret);
        $this->ldap_close();
        return $ret;
    }

    /**
     * not yet implemented
     * should return a Moodle account from its LDAP dn
     * @param string $dnid
     * @param string $dn
     */
    function get_account_bydn($dnid,$dn) {
        return false;
    }

    /**
     * rev 1012 traitement de l'execption avec active directory pour des groupes >1000 membres
     * voir http://forums.sun.com/thread.jspa?threadID=578347
     *
     * @return string[] an array of username indexed by Moodle's userid
     */
    function ldap_get_group_members($groupe) {
        global $DB;
        if ($this->config->user_type == "ad")
            $members = $this->ldap_get_group_members_ad($groupe);
        else
            $members = $this->ldap_get_group_members_rfc($groupe);
        $ret = array ();
        foreach ($members as $member) {
            $params = array (
                'username' => $member
            );
            if ($user = $DB->get_record('user', $params, 'id,username'))
                $ret[$user->id] = $user->username;
        }
        return $ret;
    }

    function get_cohort_members($cohortid) {
        global $DB;
        $sql = " SELECT u.id,u.username
                          FROM {user} u
                         JOIN {cohort_members} cm ON (cm.userid = u.id AND cm.cohortid = :cohortid)
                        WHERE u.deleted=0";
        $params['cohortid'] = $cohortid;
        return $DB->get_records_sql($sql, $params);
    }

    function cohort_is_member($cohortid, $userid) {
        global $DB;
        $params = array (
            'cohortid' => $cohortid,
            'userid' => $userid
        );
        return $DB->record_exists('cohort_members', $params);
    }

}

// Ensure errors are well explained
$CFG->debug = DEBUG_NORMAL;

if (!is_enabled_auth('cas')) {
    error_log('[AUTH CAS] ' . get_string('pluginnotenabled', 'auth_ldap'));
    die;
}

$plugin = new auth_plugin_cohort();

$ldap_groups = $plugin->ldap_get_grouplist();
//print_r($ldap_groups);

foreach ($ldap_groups as $group=>$groupname) {
    print "traitement du groupe " . $groupname . "\n";
    $params = array (
        'idnumber' => $groupname
    );
    if (!$cohort = $DB->get_record('cohort', $params, '*')) {
        $cohort = new StdClass();
        $cohort->name = $cohort->idnumber = $groupname;
        $cohort->contextid = get_system_context()->id;
        $cohort->component='sync_ldap';
        $cohortid = cohort_add_cohort($cohort);
        print "creation cohorte " . $group . "\n";

    } else
        $cohortid = $cohort->id;
    //    print ($cohortid." ");
    $ldap_members = $plugin->ldap_get_group_members($groupname);
    $cohort_members = $plugin->get_cohort_members($cohortid);

    foreach ($cohort_members as $userid => $user) {
        if (!isset ($ldap_members[$userid])) {
            cohort_remove_member($cohortid, $userid);
            print "desinscription de " .
            $user->username .
            " de la cohorte " .
            $groupname .
            "\n";
        }
    }

    foreach ($ldap_members as $userid => $username) {
        if (!$plugin->cohort_is_member($cohortid, $userid)) {
            cohort_add_member($cohortid, $userid);
            print "inscription de " . $username . " à la cohorte " . $groupname . "\n";
        }
    }
    //break;

}
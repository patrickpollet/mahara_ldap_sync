<?php
/**
 * Mahara: Electronic portfolio, weblog, resume builder and social networking
 * Copyright (C) 2006-2009 Catalyst IT Ltd (http://www.catalyst.net.nz)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    mahara
 * @subpackage mahara-sync-ldap
 * @author     Patrick Pollet <pp@patrickpollet.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL
 * @copyright  (C) 2006-2011 Catalyst IT Ltd http://catalyst.net.nz
 * @copyright  (C) 2011 INSA de Lyon France
 *
 * This file incorporates work covered by the following copyright and
 * permission notice:
 *
 *    Moodle - Modular Object-Oriented Dynamic Learning Environment
 *             http://moodle.com
 *
 *    Copyright (C) 2001-3001 Martin Dougiamas        http://dougiamas.com
 *
 *    This program is free software; you can redistribute it and/or modify
 *    it under the terms of the GNU General Public License as published by
 *    the Free Software Foundation; either version 2 of the License, or
 *    (at your option) any later version.
 *
 *    This program is distributed in the hope that it will be useful,
 *    but WITHOUT ANY WARRANTY; without even the implied warranty of
 *    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *    GNU General Public License for more details:
 *
 *             http://www.gnu.org/copyleft/gpl.html
 */

/**
 * The purpose of this CLI script is to be run as a cron job to synchronize Mahara' users
 * with users defined on a LDAP server
 *
 * This script requires at least a parameter the name of the target institution
 * in which users will be created/updated.
 * An instance of LDAP or CAS auth plugin MUST have been added to this institution
 * for this script to retrieve LDAP parameters
 * It is possible to run this script for several institutions
 *
 * For the synchronisation of group membership , this script MUST be run before
 * the mahara_sync_groups script
 *
 * This script is strongly inspired of synching Moodle's users with LDAP
 */


define('INTERNAL', 1);
define('ADMIN', 1);
define('INSTALLER', 1);
define('CLI', 1);

define ('SUSPENDED_REASON', 'LDAP sync ');

require(dirname(dirname(dirname(dirname(__FILE__)))) . '/init.php');
require(get_config('libroot') . 'cli.php');

// must be done before any output
$USER->reanimate(1, 1);


require(get_config('libroot') . 'institution.php');
require(get_config('libroot') . 'group.php');
require(get_config('libroot') . 'searchlib.php');
require_once(get_config('docroot') . 'auth/ldap/lib.php');
require_once(dirname(dirname(__FILE__))) . '/lib.php';


$CFG->debug_ldap_groupes = false;

$cli = get_cli();

$options = array();

$options['institution'] = new stdClass();
$options['institution']->examplevalue = '\'my institution\'';
$options['institution']->shortoptions = array('i');
$options['institution']->description = get_string('institutionname', 'local.ldap');
$options['institution']->required = true;

$options['contexts'] = new stdClass();
$options['contexts']->examplevalue = '\'ou=pc,dc=insa-lyon,dc=fr[;anothercontext]\'';
$options['contexts']->shortoptions = array('c');
$options['contexts']->description = get_string('searchcontexts', 'local.ldap');
$options['contexts']->required = false;

$options['searchsub'] = new stdClass();
$options['searchsub']->examplevalue = '1';
$options['searchsub']->shortoptions = array('s');
$options['searchsub']->description = get_string('searchsubcontexts', 'local.ldap');
$options['searchsub']->required = false;


$options['extrafilterattribute'] = new stdClass();
$options['extrafilterattribute']->examplevalue = 'eduPersonAffiliation=member';
$options['extrafilterattribute']->shortoptions = array('f');
$options['extrafilterattribute']->description = get_string('extrafilterattribute', 'local.ldap');
$options['extrafilterattribute']->required = false;

$options['doupdate'] = new stdClass();
$options['doupdate']->shortoptions = array('u');
$options['doupdate']->description = get_string('doupdate', 'local.ldap');
$options['doupdate']->required = false;

$options['nocreate'] = new stdClass();
$options['nocreate']->shortoptions = array('n');
$options['nocreate']->description = get_string('nocreate', 'local.ldap');
$options['nocreate']->required = false;


$options['dosuspend'] = new stdClass();
$options['dosuspend']->shortoptions = array('s');
$options['dosuspend']->description = get_string('dosuspend', 'local.ldap');
$options['dosuspend']->required = false;

$options['dodelete'] = new stdClass();
$options['dodelete']->shortoptions = array('d');
$options['dodelete']->description = get_string('dodelete', 'local.ldap');
$options['dodelete']->required = false;

$settings = new stdClass();
$settings->options = $options;
$settings->info = get_string('cli_mahara_sync_users', 'local.ldap');

$cli->setup($settings);

// Check initial password and e-mail address before we install
try {
    $institutionname = $cli->get_cli_param('institution');
    $institution = new Institution ($institutionname);
    $extrafilterattribute = $cli->get_cli_param('extrafilterattribute');

    $CFG->debug_ldap_groupes = $cli->get_cli_param('verbose');
    $onlycontexts = $cli->get_cli_param('contexts');
    $searchsub = $cli->get_cli_param('searchsub');

    $doupdate = $cli->get_cli_param('doupdate');
    $nocreate = $cli->get_cli_param('nocreate');
    $dosuspend = $cli->get_cli_param('dosuspend');
    $dodelete = $cli->get_cli_param('dodelete');

    if ($dosuspend && $dodelete) {
        throw new ParameterException (get_string('cannotdeleteandsuspend', 'local.ldap'));
    }
}
// we catch missing parameter and unknown institution
catch (Exception $e) {
    $USER->logout(); // important
    cli::cli_exit($e->getMessage(), true);
}


$auths = auth_instance_get_matching_instances($institutionname);

if ($CFG->debug_ldap_groupes) {
    moodle_print_object("auths candidates : ", $auths);
}

if (count($auths) == 0) {
    cli::cli_exit(get_string('cli_mahara_nomatchingauths', 'local.ldap'));
}


// it is unlikely that there is mre than one LDAP per institution
foreach ($auths as $auth) {
    $instance = new  GAAuthLdap($auth->id);

    // override defaut contexts values for the auth plugin
    if ($onlycontexts) {
        $instance->set_config('contexts', $onlycontexts);
    }

    // OVERRRIDING searchsub contexts for this auth plugin
    if ($searchsub !== false) {
        $instance->set_config('search_sub', $searchsub ? 'yes' : 'no');
    }

    $instanceconfig = $instance->get_config();
    if ($CFG->debug_ldap_groupes) {
        moodle_print_object("config. LDAP : ", $instanceconfig);
    }

    // fetch ldap users having the filter attribute on (caution maybe mutlivalued

    $ldapusers = $instance->ldap_get_users($extrafilterattribute);
    if ($CFG->debug_ldap_groupes) {
        moodle_print_object("LDAP users : ", $ldapusers);
    }

    $nbupdated = $nbcreated = $nbsuspended = $nbdeleted = $nbignored = $nbpresents = 0;

    // Define ldap attributes in user update
    $ldapattributes = array();
    $ldapattributes['firstname'] = $instanceconfig['firstnamefield'];
    $ldapattributes['lastname'] = $instanceconfig['surnamefield'];
    $ldapattributes['email'] = $instanceconfig['emailfield'];
    $ldapattributes['studentid'] = $instanceconfig['studentidfield'];
    $ldapattributes['preferredname'] = $instanceconfig['preferrednamefield'];

    // Match database and ldap entries and update in database if required
    $fieldstoimport = array_keys($ldapattributes);

    // fields to fetch from usr table for existing users  (try to save memory
    $fieldstofetch = array_keys($ldapattributes);
    $fieldstofetch = array_merge($fieldstofetch, array('id', 'username', 'suspendedreason'));
    $fieldstofetch = implode(',', $fieldstofetch);


    if ($CFG->debug_ldap_groupes) {
        moodle_print_object("LDAP attributes : ", $ldapattributes);
    }


    // we fetch only Mahara users of this institution concerned by this authinstance (either cas or ldap)
    // and get also their suspended status since we may have to unsuspend them
    // this serach cannot be done by a call to get_institutional_admin_search_results
    // that does not support searching by auth instance id and do not return suspended status


    $data = auth_instance_get_concerned_users($auth->id, $fieldstofetch);
    if ($CFG->debug_ldap_groupes) {
        moodle_print_object("current members : ", $data);
    }

    $currentmembers = array();

    foreach ($data as $datum) {
        $currentmembers [$datum->username] = $datum;
    }

    unset($data); //save memory

    if ($CFG->debug_ldap_groupes) {
        moodle_print_object("current members  II : ", $currentmembers);
    }


    foreach ($ldapusers as $ldapusername) {
        if (isset($currentmembers[$ldapusername])) {
            $nbpresents++;

            if ($doupdate) {
                $user = $currentmembers[$ldapusername];
                $cli->cli_print('updating user ' . $ldapusername);
                // Retrieve information of user from LDAP
                $ldapdetails = $instance->get_user_info($ldapusername, $ldapattributes);
                // this method returns an object and we want an array below
                $ldapdetails = (array)$ldapdetails;

                foreach ($fieldstoimport as $field) {
                    $sanitizer = "sanitize_$field";
                    $ldapdetails[$field] = $sanitizer($ldapdetails[$field]);
                    if (!empty($ldapdetails[$field]) && ($user->$field != $ldapdetails[$field])) {
                        $user->$field = $ldapdetails[$field];
                        set_profile_field($user->id, $field, $ldapdetails[$field]);
                    }
                }

                //we also must update the student id in table usr_institution

                set_field('usr_institution', 'studentid', $user->studentid, 'usr', $user->id, 'institution', $institutionname);
                //  pp_error_log ('maj compte II',$user);


                $nbupdated++;
                //unsuspend if was supended by me at a previous run
                if (!empty($user->suspendedreason) && strstr(SUSPENDED_REASON, $user->suspendedreason) !== false) {
                    unsuspend_user($user->id);
                }
                unset($user);
                unset($ldapdetails);
            }
            unset ($currentmembers[$ldapusername]);

        } else {
            if (!$nocreate) {
                // Retrieve information of user from LDAP
                $ldapdetails = $instance->get_user_info($ldapusername, $ldapattributes);
                $ldapdetails->username = $ldapusername; //not returned by LDAP
                $ldapdetails->authinstance = $auth->id;
                if ($CFG->debug_ldap_groupes) {
                    moodle_print_object("creation de ", $ldapdetails);
                }
                $cli->cli_print('creating user ' . $ldapusername);
                create_user($ldapdetails, array(), $institutionname);
                $nbcreated++;
            }
        }
    }

    // now currentmembers contains ldap/cas users that are not anymore in LDAP
    foreach ($currentmembers as $memberusername => $member) {
        if ($dosuspend) {
            if (!$member->suspendedreason) { //if not already suspended for any reason ( me or some manual operation)
                $cli->cli_print('suspending user ' . $memberusername);
                suspend_user($member->id, SUSPENDED_REASON . ' ' . time());
                $nbsuspended++;

            }
        } else {
            if ($dodelete) {
                $cli->cli_print('deleting user ' . $memberusername);
                delete_user($member->id);
                $nbdeleted++;

            } else {
                // nothing to do
                $cli->cli_print('ignoring user ' . $memberusername);
                $nbignored++;
            }
        }
    }
    cli::cli_print("$nbpresents $nbupdated $nbcreated $nbsuspended $nbdeleted $nbignored");

}

$USER->logout(); // important
cli::cli_exit("fini", true);



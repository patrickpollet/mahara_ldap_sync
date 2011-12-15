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
 * The purpose of this CLI script is to be run as a cron job to synchronize Mahara's groups
 * with groups defined on a LDAP server
 *
 * This script requires as a single parameter the name of the target institution
 * in which groups will be created/updated.
 * An instance of LDAP or CAS auth plugin MUST have been added to this institution
 * for this script to retrieve LDAP parameters
 * It is possible to run this script for several institutions
 *
 * For the synchronisation of group members , this script MUST be run after
 * the mahara_sync_users script
 *
 * This script is strongly inspired of synching Moodle's cohorts with LDAP groups
 * as described here : http://moodle.org/mod/forum/discuss.php?d=160751
 *
 * Sample cron entry:
 * # 5 minutes past 4am
 * 5 4 * * * $sudo -u www-data /usr/bin/php /var/www/mahara/local/ldap/cli/mahara_sync_groups.php 'my institution'
 *
 * Notes:
 *   - it is required to use the web server account when executing PHP CLI scripts
 *   - you need to change the "www-data" to match the apache user account
 *   - use "su" if "sudo" not available
 *   - If you have a large number of users, you may want to raise the memory limits
 *     by passing -d memory_limit=256M
 *   - For debugging & better logging, you are encouraged to use in the command line:
 *     -d log_errors=1 -d error_reporting=E_ALL -d display_errors=0 -d html_errors=0
 *
 *
 */

define('INTERNAL', 1);
define('ADMIN', 1);
define('INSTALLER', 1);
define('CLI', 1);

require(dirname(dirname(dirname(dirname(__FILE__)))) . '/init.php');
require(get_config('libroot') . 'cli.php');

require(get_config('libroot') . 'institution.php');
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

$options['exclude'] = new stdClass();
$options['exclude']->examplevalue = '\repository*;cipc-*\'';
$options['exclude']->shortoptions = array('x');
$options['exclude']->description = get_string('excludelist', 'local.ldap');
$options['exclude']->required = false;

$options['include'] = new stdClass();
$options['include']->examplevalue = '\repository*;cipc-*\'';
$options['include']->shortoptions = array('o');
$options['include']->description = get_string('includelist', 'local.ldap');
$options['include']->required = false;

$settings = new stdClass();
$settings->options = $options;
$settings->info = get_string('cli_mahara_sync_groups', 'local.ldap');

$cli->setup($settings);

// Check initial password and e-mail address before we install
try {
    $institutionname = $cli->get_cli_param('institution');
    $institution = new Institution ($institutionname);
    $excludelist = explode(';', $cli->get_cli_param('exclude'));
    $includelist = explode(';', $cli->get_cli_param('include'));
    $CFG->debug_ldap_groupes = $cli->get_cli_param('verbose');
}
// we catch missing parameter and unknown institution
catch (Exception $e) {
    cli::cli_exit($e->getMessage(), true);

}

if ($CFG->debug_ldap_groupes) {
    moodle_print_object("institution : ", $institution);
    moodle_print_object("liste exclusion : ", $excludelist);
    moodle_print_object("liste inclusion : ", $includelist);

}

$auths = auth_instance_get_matching_instances($institutionname);

if ($CFG->debug_ldap_groupes) {
    moodle_print_object("auths candidates : ",$auths);
}

if (count($auths) == 0) {
    cli::cli_exit(get_string('cli_mahara_nomatchingauths', 'local.ldap'));
}

foreach ($auths as $auth) {
    $instance = new  GAAuthLdap($auth->id);
    $groups = $instance->ldap_get_grouplist();
    if ($CFG->debug_ldap_groupes) {
        moodle_print_object("groupes non filtrès : ", $groups);
    }
    foreach ($groups as $group) {
        if (!ldap_sync_filter_name($group, $includelist, $excludelist)) {
            continue;
        }

        if ($CFG->debug_ldap_groupes) {
            moodle_print_object("traitement du groupe  : ", $group);
        }
        $users = $instance->ldap_get_group_members($group);
        if ($CFG->debug_ldap_groupes) {
            moodle_print_object($group.' : ', $users);
        }








        // break;
    }
}


cli::cli_exit("fini", true);


/************
// Ensure errors are well explained
$CFG->debug = DEBUG_NORMAL;
$CFG->debug_ldap_groupes=false;

if (!is_enabled_auth('cas')) {
error_log('[AUTH CAS] ' . get_string('pluginnotenabled', 'auth_ldap'));
die;
}


$plugin = new auth_plugin_cohort();

$ldap_groups = $plugin->ldap_get_grouplist();
//print_r($ldap_groups);

foreach ($ldap_groups as $group => $groupname) {
print "traitement du groupe " . $groupname . "\n";
$params = array(
'idnumber' => $groupname
);
if (!$cohort = $DB->get_record('cohort', $params, '*')) {
$cohort = new StdClass();
$cohort->name = $cohort->idnumber = $groupname;
$cohort->contextid = get_system_context()->id;
// no Moodle 21 is looking for this component
//$cohort->component = 'sync_ldap';
$cohortid = cohort_add_cohort($cohort);
print "creation cohorte " . $group . "\n";

} else
{
$cohortid = $cohort->id;
}
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
 ********/

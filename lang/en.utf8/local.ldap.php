<?php
/**
 * Created by JetBrains PhpStorm.
 * User: root
 * Date: 05/01/12
 * Time: 15:25
 * To change this template use File | Settings | File Templates.
 */


defined ('INTERNAL') || die();


$string['cli_mahara_sync_users']='This command line PHP script will attempt to synchronize an institution list of Mahara accounts with an LDAP directory';

$string['cli_mahara_sync_groups']='This command line PHP script will attempt to synchronize an institution list of groups with an LDAP directory';

$string['institutionname'] = 'Name of the institution to process (required)';

$string['searchcontexts']= 'Restrict searching in these contexts (override values set in authentication plugin)';
$string['searchsubcontexts']='Also search in sub contexts (override values set in authentication plugin)';

$string['extrafilterattribute']='additional LDAP field to search';


$string['nocreate']= 'do not create new accounts';
$string['doupdate']= 'update existing Mahara accounts with LDAP data (long)';
$string['dodelete']= 'delete Mahara accounts not anymore in LDAP' ;
$string['dosuspend']= 'suspend Mahara accounts not anymore in LDAP';


$string['cannotdeleteandsuspend']= 'Cannot specify -d and -s at the same time';


$string['includelist']='';
$string['excludelist']='';

$string['grouptype']='';

/*
 *
 *
cli_mahara_nomatchingauths
 */


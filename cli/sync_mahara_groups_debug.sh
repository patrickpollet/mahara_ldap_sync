#!/bin/sh
SYNC_DIR="/var/www/html/mahara/local/ldap/cli"
DATE=`date +%Y-%m-%d`
LOG_DIR="/work/maharadata/sync"
PHP=/usr/bin/php
if [ ! -d $LOG_DIR ]; then
	mkdir -p $LOG_DIR
fi
OLDPWD=$PWD
cd $SYNC_DIR && \
$PHP -d log_errors=1 -d error_reporting=E_ALL \
-d display_errors=0 -d html_errors=0 -d memory_limit=256M \
./mahara_sync_groups.php -i=premiercycle -o='test;^repo*' -x='-18' -v > $LOG_DIR/group_ldap_sync_$DATE.txt
cd $OLDPWD


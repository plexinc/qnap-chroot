#/bin/sh

AD_DOMAIN=$2
AD_USER=$3
AD_PASSWD=$4
AD_SERVER=$5
AD_WG=$6
AD_OU=$7
if [ "x$1" = "xupdate_krb5_ticket" ]; then
	AD_PASSWD=$2
fi

if [ "x$1" = "xADM" ]; then
DEBUG_FILE=/var/log/setup_smb.debug
/bin/rm -f /home/httpd/cgi-bin/setup_smb.debug
/bin/ln -sf ${DEBUG_FILE} /home/httpd/cgi-bin/setup_smb.debug
else
DEBUG_FILE=/dev/null
fi

# constants
SUCCESS=0
USAGE_ERROR=1
UNKNOWN_ERROR=2
KDC_SRV_ERROR=3
KDC_REALM_ERROR=4
KDC_TIME_DIFF_ERROR=5
AD_JOIN_ERROR=6
NTD_JOIN_ERROR=7
KDC_SRV_ADNAME_ERROR=8

# utility 
SED=/bin/sed
UPPERCASE=/usr/bin/uppercase
LOWERCASE=/usr/bin/lowercase
GETKDC=/usr/bin/getKDC
KINIT=/usr/bin/kinit
GREP=/bin/grep
CUT=/bin/cut
SETCFG=/sbin/setcfg
GETCFG=/sbin/getcfg
NET=/usr/local/samba/bin/net
WBINFO=/usr/bin/wbinfo

# config file
KRB5_TMPL=/etc/krb5_tmpl.conf
KRB5_CONF=/etc/config/krb5.conf
SMB_CONF=/etc/config/smb.conf

# functions
backup_conf_file() {
	#echo "enter backup_conf_file"
	SMB_TMP=`mktemp $SMB_CONF.XXXXXX`
	if [ -f $KRB5_CONF ]; then
		KRB5_TMP=`mktemp $KRB5_CONF.XXXXXX` 
		cp -af $KRB5_CONF $KRB5_TMP
	fi
	cp -af $SMB_CONF $SMB_TMP
}

restore_conf_file() {
	#echo "enter restore_conf_file"
	[ ! -f "$KRB5_TMP" ] || mv $KRB5_TMP $KRB5_CONF
	mv $SMB_TMP $SMB_CONF

}

clean_backup_file() {
	#echo "enter clean_backup_file"
	[ ! -f "$KRB5_TMP" ] || rm -f $KRB5_TMP
	rm -f $SMB_TMP
}

add_printer_admin_main(){
	printers_support=`$GETCFG Printers Support`
        printers_enable=`$GETCFG Printers Enable`
        if [ "x${printers_enable}" = "xTRUE" ]; then
		echo $printers_support > /tmp/log
		echo $printers_enable >> /tmp/log
        	add_printer_admin
                add_printer_admin
                /etc/init.d/smb.sh restart > /dev/null
        fi
}
add_printer_admin(){
	user=""
        for x in `wbinfo -g | grep "Domain Users"`; do
                if [ x"$x" != x"Users" ]; then
                        user=$user"@\""$x\ Users\",
                fi
        done
        user=$user@everyone,guest
        /sbin/setcfg Printers "printer admin" "$user" -f $LOCAL_SMB_CONF -c
        return
}

clean_smb_conf() {
	LOCAL_SMB_CONF=$1	
	$SETCFG global 'character set' -f $LOCAL_SMB_CONF -e
	$SETCFG global 'name resolve order' -f $LOCAL_SMB_CONF -e
	$SETCFG global 'realm' -f $LOCAL_SMB_CONF -e
	$SETCFG global 'password server' -f $LOCAL_SMB_CONF -e
	$SETCFG global 'pam password change' -f $LOCAL_SMB_CONF -e
	$SETCFG global 'winbind separator' -f $LOCAL_SMB_CONF -e
	$SETCFG global 'idmap uid' -f $LOCAL_SMB_CONF -e
	$SETCFG global 'idmap gid' -f $LOCAL_SMB_CONF -e
	$SETCFG global 'winbind enum users' -f $LOCAL_SMB_CONF -e
	$SETCFG global 'winbind enum groups' -f $LOCAL_SMB_CONF -e
	$SETCFG global 'winbind cache time' -f $LOCAL_SMB_CONF -e
	$SETCFG Printers 'printer admin' -f $LOCAL_SMB_CONF -e
	
	/etc/init.d/hostname.sh
}

clean_winbind_cache() {
	volume_test=`/sbin/getcfg Public path -f /etc/smb.conf | cut -d '/' -f 3`
	[ "x${volume_test}" = "x" ] || volume=${volume_test}
	if [ -d /share/${volume}/.locks ]; then
		cd /share/${volume}/.locks
		/bin/rm -f /share/${volume}/.locks/* -rf
	fi
}

clean_domain_db() {
	/bin/rm -f /mnt/HDA_ROOT/.domain_user
	/bin/rm -f /mnt/HDA_ROOT/.domain_group
}

alone_set_smb_conf() {
	LOCAL_SMB_CONF=$1
	$SETCFG global workgroup "$AD_DOMAIN" -f $LOCAL_SMB_CONF
	$SETCFG global security USER -f $LOCAL_SMB_CONF
	$SETCFG System Workgroup "$AD_DOMAIN"
}

join_ntd() {
	LOCAL_SMB_CONF=$1
	domain_name=`${LOWERCASE} $2`
	wg_name=`${UPPERCASE} ${domain_name}`
	user_name=$3
	passwd=$4
	_winbind_separator=`${GETCFG} Samba "winbind separator" -d "+"`

	$SETCFG global 'security' DOMAIN -f $LOCAL_SMB_CONF
	$SETCFG global 'workgroup' $wg_name -f $LOCAL_SMB_CONF
	$SETCFG global 'realm' $domain_name -f $LOCAL_SMB_CONF
	$SETCFG global 'password server' '*' -f $LOCAL_SMB_CONF
	$SETCFG global 'pam password change' yes -f $LOCAL_SMB_CONF
	if [ "x${_winbind_separator}" != "x+" ]; then
		$SETCFG -e global 'winbind separator' -f $LOCAL_SMB_CONF
	else
		$SETCFG global 'winbind separator' ${_winbind_separator} -f $LOCAL_SMB_CONF
	fi
	$SETCFG global 'idmap uid' '30001-300000' -f $LOCAL_SMB_CONF
	$SETCFG global 'idmap gid' '30001-300000' -f $LOCAL_SMB_CONF
	$SETCFG global 'winbind enum users' yes -f $LOCAL_SMB_CONF
	$SETCFG global 'winbind enum groups' yes -f $LOCAL_SMB_CONF
	$SETCFG global 'winbind cache time' '3600' -f $LOCAL_SMB_CONF
	$SETCFG System Workgroup $wg_name

	/bin/echo "[command] ${NET} rpc join -U \"$user_name%********\" -s $SMB_CONF" >> $DEBUG_FILE
	${NET} rpc join -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
	if [ "$?" != "0" ]; then 
		return 2
	fi
	return 0
}


join_ad() {
	LOCAL_SMB_CONF=$1
	domain_name=`${LOWERCASE} ${AD_DOMAIN}`
	if [ "x${AD_WG}" != "x" ]; then
		wg_name=`${UPPERCASE} ${AD_WG}`
		echo "Specify WORKGROUP = ${wg_name}" >> $DEBUG_FILE
	else
		wg_name=`${UPPERCASE} ${domain_name} | ${CUT} -d . -f 1`
		echo "Domain prefix WORKGROUP = ${wg_name}" >> $DEBUG_FILE
	fi
	
	user_name=${AD_USER}
	passwd=${AD_PASSWD}
	_winbind_separator=`${GETCFG} Samba "winbind separator" -d "+"`

	if [ "x${AD_SERVER}" != "x" ]; then
		kdc=${AD_SERVER}.${AD_DOMAIN}
	else
		echo "[command] ${GETKDC} ${lc_name}" >> $DEBUG_FILE
		${GETKDC} ${lc_name} >> $DEBUG_FILE
		if [ "$?" != "0" ]; then
			echo "get KDC failed!"
			return 1
		fi
		kdc=`${GETKDC} ${lc_name}`
	fi

	$SETCFG global 'security' ADS -f $LOCAL_SMB_CONF
	$SETCFG global 'workgroup' $wg_name -f $LOCAL_SMB_CONF
	$SETCFG global 'realm' $domain_name -f $LOCAL_SMB_CONF
	if [ "x${AD_SERVER}" = "x*" ]; then
		$SETCFG global 'password server' "*" -f $LOCAL_SMB_CONF
	else
		$SETCFG global 'password server' $kdc -f $LOCAL_SMB_CONF
	fi
	$SETCFG global 'pam password change' yes -f $LOCAL_SMB_CONF
	if [ "x${_winbind_separator}" != "x+" ]; then
		$SETCFG -e global 'winbind separator' -f $LOCAL_SMB_CONF
	else
		$SETCFG global 'winbind separator' ${_winbind_separator} -f $LOCAL_SMB_CONF
	fi
	$SETCFG global 'idmap uid' '30001-300000' -f $LOCAL_SMB_CONF
	$SETCFG global 'idmap gid' '30001-300000' -f $LOCAL_SMB_CONF
	$SETCFG global 'winbind enum users' yes -f $LOCAL_SMB_CONF
	$SETCFG global 'winbind enum groups' yes -f $LOCAL_SMB_CONF
	$SETCFG global 'winbind cache time' '3600' -f $LOCAL_SMB_CONF
	$SETCFG System Workgroup $wg_name
	/etc/init.d/hostname.sh
	/bin/cp /etc/config/smb.conf /home/httpd/cgi-bin/smb.conf.debug
	if [ "x${AD_SERVER}" = "x*" ]; then
		if [ "x${AD_OU}" != "x" ]; then
			echo "[command] ${NET} ads join createcomputer="${AD_OU}" -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
			${NET} ads join createcomputer="${AD_OU}" -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
			if [ "$?" != "0" ]; then
				# try to use rpc join
				echo "[command] ${NET} rpc join createcomputer="${AD_OU}" -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
				${NET} rpc join createcomputer="${AD_OU}" -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
			fi
		else
			echo "[command] ${NET} ads join -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
			${NET} ads join -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
			if [ "$?" != "0" ]; then
				# try to use rpc join
				echo "[command] ${NET} rpc join -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
				${NET} rpc join -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
			fi
		fi
		return 0
	fi
	AD_SERVER_NAME=`/sbin/getcfg global "password server" -f /etc/config/smb.conf  | /bin/cut -d. -f1`
	if [ "x${AD_OU}" != "x" ]; then
		echo "[command] ${NET} ads join createcomputer="${AD_OU}" -S ${AD_SERVER_NAME} -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
		${NET} ads join createcomputer="${AD_OU}" -S ${AD_SERVER_NAME} -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
		if [ "$?" != "0" ]; then
			echo "[command] ${NET} ads join createcomputer="${AD_OU}" -S ${kdc} -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
			${NET} ads join createcomputer="${AD_OU}" -S ${kdc} -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
			if [ "$?" != "0" ]; then
				echo "[command] ${NET} ads join createcomputer="${AD_OU}" -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
				${NET} ads join createcomputer="${AD_OU}" -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
				if [ "$?" != "0" ]; then
					# try to use rpc join
					echo "[command] ${NET} rpc join createcomputer="${AD_OU}" -S ${AD_SERVER_NAME} -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
					${NET} rpc join createcomputer="${AD_OU}" -S ${AD_SERVER_NAME} -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
					if [ "$?" != "0" ]; then
						echo "[command] ${NET} rpc join createcomputer="${AD_OU}" -S ${kdc} -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
						${NET} rpc join createcomputer="${AD_OU}" -S ${kdc} -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
						if [ "$?" != "0" ]; then
							echo "[command] ${NET} rpc join createcomputer="${AD_OU}" -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
							${NET} rpc join createcomputer="${AD_OU}" -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
							if [ "$?" != "0" ]; then
								return 2
							fi
						fi
					fi
				fi
			fi
		fi
	else
		echo "[command] ${NET} ads join -S ${AD_SERVER_NAME} -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
		${NET} ads join -S ${AD_SERVER_NAME} -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
		if [ "$?" != "0" ]; then
			echo "[command] ${NET} ads join -S ${kdc} -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
			${NET} ads join -S ${kdc} -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
			if [ "$?" != "0" ]; then
				echo "[command] ${NET} ads join -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
				${NET} ads join -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
				if [ "$?" != "0" ]; then
					# try to use rpc join
					echo "[command] ${NET} rpc join -S ${AD_SERVER_NAME} -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
					${NET} rpc join -S ${AD_SERVER_NAME} -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
					if [ "$?" != "0" ]; then
						echo "[command] ${NET} rpc join -S ${kdc} -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
						${NET} rpc join -S ${kdc} -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
						if [ "$?" != "0" ]; then
							echo "[command] ${NET} rpc join -U \"${user_name}%********\" -s $SMB_CONF" >> $DEBUG_FILE
							${NET} rpc join -U "$user_name%$passwd" -s $SMB_CONF >> $DEBUG_FILE 2>&1
							if [ "$?" != "0" ]; then
								return 2
							fi
						fi
					fi
				fi
			fi
		fi
	fi
	return 0
}

setup_krb5() {
	lc_name=`${LOWERCASE} ${AD_DOMAIN}`
	uc_name=`${UPPERCASE} ${lc_name}`
	user_name=${AD_USER}
	passwd=${AD_PASSWD}
	if [ "x${AD_SERVER}" != "x" ]; then
		if [ "x${AD_SERVER}" = "x*" ]; then
			kdc=${AD_DOMAIN}
		else
			kdc=${AD_SERVER}.${AD_DOMAIN}
		fi
	else
		echo "[command] ${GETKDC} ${lc_name}" >> $DEBUG_FILE
		${GETKDC} ${lc_name} >> $DEBUG_FILE
		if [ "$?" != "0" ]; then
			echo "get KDC failed!"
			return 1
		fi
		kdc=`${GETKDC} ${lc_name}`
	fi

	[ ! -f ${KRB5_CONF} ] || rm -f ${KRB5_CONF}
	${SED} "s/##QNAP_REALM##/${uc_name}/g" ${KRB5_TMPL} | ${SED} "s/##QNAP_DOMAIN##/${lc_name}/g" | ${SED} "s/##QNAP_KDC##/${kdc}/g" > ${KRB5_CONF}

        echo "${NET} time set -S ${kdc}" >> $DEBUG_FILE
        ${NET} time set -S ${kdc} 2>/dev/null 1>/dev/null
	if [ "$?" != "0" ]; then
		TMP_FILE=/tmp/tmp.adserver
		/bin/ping -q -c 1 $kdc > ${TMP_FILE}
		ADSERVER_IP=`/bin/cat ${TMP_FILE} | /bin/tr -cs '[0-9\.]' '\012' | /bin/awk -F'.' 'NF==4 && $1>0 && $1<256 && $2<256 && $3<256 && $4<256 && !/\.\./' | /bin/uniq`
		echo "Sync time with domain name fail, try to sync time with IP" >> $DEBUG_FILE
		echo "${NET} time set -S ${ADSERVER_IP}" >> $DEBUG_FILE
		${NET} time set -S ${ADSERVER_IP} 2>/dev/null 1>/dev/null
	fi

	echo "[command] echo ******** | ${KINIT} \"${user_name}@${uc_name}\"" >> $DEBUG_FILE
	echo ${passwd} | ${KINIT} "${user_name}@${uc_name}" >> $DEBUG_FILE 2>&1
	case $? in
	0)
		echo "setup krb5 success"
		;;
	127)
		if [ $# = 4 ]; then
			echo "kinit failed: please check DNS and AD server name"
			return 4
		fi
		;;
	*)
		echo "[command] echo ******** | ${KINIT} \"${user_name}@${uc_name}\"" >> $DEBUG_FILE
		tmp=`echo ${passwd} | ${KINIT} "${user_name}@${uc_name}" 2>&1 1>>$DEBUG_FILE` 
		clk=`echo ${tmp} | ${GREP} -qc "Clock skew too great"`
		if [ "${clk}" = "0" ]; then 
			echo "kinit failed: can't find KDC realm!"
			return 2
		else
			echo "kinit failed: too much time difference!"
			return 3
		fi
	esac
	return 0
}

update_ad_user_group() {
	_sed="/bin/sed"
	_netbios_name=`$GETCFG global workgroup -f $SMB_CONF`
	_security=`$GETCFG global security -d user -f $SMB_CONF`
	_smbd_locks="/usr/local/samba/var/locks"
	if [ "x${_security}" = "xADS" ]; then
		_trusted_domain=`$GETCFG Samba "Trusted Domain" -d False`
		if [ "x${_trusted_domain}" = "xTRUE" ]; then
			_trusted_netbios=/tmp/.trusted_netbios
			/sbin/get_trusted_domain  | /bin/cut -f 3 -d '[' | /bin/cut -f 1 -d ']' > ${_trusted_netbios}
			while read _netbios_name; do
				if [ "x$1" = "x\\" ]; then
					_from=${_netbios_name}\\\\
				else
					_from=${_netbios_name}$1
				fi
				if [ "x$2" = "x\\" ]; then
					_to=${_netbios_name}\\\\
				else
					_to=${_netbios_name}$2
				fi
				${_sed} -i "s/${_from}/${_to}/g" /etc/config/smb.conf
			done < ${_trusted_netbios}
		else
			if [ "x$1" = "x\\" ]; then
				_from=${_netbios_name}\\\\
			else
				_from=${_netbios_name}$1
			fi
			if [ "x$2" = "x\\" ]; then
				_to=${_netbios_name}\\\\
			else
				_to=${_netbios_name}$2
			fi
			${_sed} -i "s/${_from}/${_to}/g" /etc/config/smb.conf
		fi
		_winbind_separator=`${GETCFG} Samba "winbind separator" -d "+"`
		if [ "x${_winbind_separator}" != "x+" ]; then
			$SETCFG -e global 'winbind separator' -f $SMB_CONF
		else
			$SETCFG global 'winbind separator' ${_winbind_separator} -f $SMB_CONF
		fi
	fi
}

update_password_server()
{
	return 0
	_tmpDC=/tmp/.tmpDC
	_password_server=
	/bin/grep "security = ADS" $SMB_CONF 2>/dev/null 1>/dev/null
	if [ $? = 0 ]; then
		_realm=`$GETCFG global realm -d user -f $SMB_CONF`
		/usr/bin/getDomainServers k ${_realm} | grep target > ${_tmpDC}
		if [ $? = 0 ]; then
			while read LINE; do
				_dc=`/bin/echo $LINE | /bin/cut -d = -f 2`
				_target=`/usr/bin/uppercase ${_dc} | /bin/cut -d . -f 1`
				_password_server=${_password_server}${_target}.${_realm}' '
			done < ${_tmpDC}
			/bin/echo "Update AD password server to ${_password_server}"
			$SETCFG global "password server" "${_password_server}" -f ${SMB_CONF}
		fi
	fi
}

update_krb5_ticket()
{
	/bin/grep "security = ADS" $SMB_CONF 2>/dev/null 1>/dev/null
	if [ $? = 0 ]; then
		${KINIT} -R
		if [ $? != 0 ]; then
			_kdc=`$GETCFG realms kdc -d 127.0.0.1 -f /etc/krb5.conf`
			${NET} time set -S ${_kdc}
			if [ $? != 0 ]; then
				TMP_FILE=/tmp/tmp.adserver
				/bin/ping -q -c 1 $_kdc > ${TMP_FILE}
				ADSERVER_IP=`/bin/cat ${TMP_FILE} | /bin/tr -cs '[0-9\.]' '\012' | /bin/awk -F'.' 'NF==4 && $1>0 && $1<256 && $2<256 && $3<256 && $4<256 && !/\.\./' | /bin/uniq`
				${NET} time set -S ${ADSERVER_IP} 2>/dev/null 1>/dev/null
			fi
			_passwd=${AD_PASSWD}
			_user_name=`$GETCFG Samba User -f /etc/config/uLinux.conf`
			_realm=`$GETCFG global realm -f ${SMB_CONF}`
			_dc_name=`/usr/bin/uppercase ${_realm}`
			echo ${_passwd} | ${KINIT} "${_user_name}@${_dc_name}"
			if [ $? = 0 ]; then
				echo "update krb5 ticket successfully"
			else
				echo "update krb5 ticket fail"
			fi
		else
			echo "renew krb5 ticket successfully"
		fi
	fi
}

#import_remote_accounts()
#{
#	echo "import remote accounts!"
#	# so far, we only import remote users
#	$WBINFO --import-users >/dev/null 2>&1 &
#}

#clean_imported_accounts()
#{
#	echo "kill imported remote accounts!"
#	# so far, we only import remote users
#	$WBINFO --kill-remote-users >/dev/null 2>&1 &
#}

#set_sync_account_schedule()
#{
#	echo "setting schedule to synchronize remote accounts"
#	# so far, only sync remote users
#	clear_sync_account_schedule
#	echo "0 6 * * * root /usr/bin/sync_remote_accounts.sh user>/dev/null 2>&1" >> /etc/crontab
#}

#clear_sync_account_schedule()
#{
#	cp /etc/crontab /tmp/crontab
#	${SED} -e '/sync_remote_accounts/d' /tmp/crontab > /etc/crontab
#	rm /tmp/crontab -f
#}

# main program 

auth_user=$3
auth_pwd=$4
echo "======== DEBUG START =======" > $DEBUG_FILE
case "$1" in
	"ADM") 		#AD member
		[ $# = 4 ] || [ $# = 5 ] || ( echo "USAGE: $0 $1 AD_DOMAIN ADM_ACCOUNT ADM_PASSWD [AD_SRV_NAME] !"; exit $USAGE_ERROR )
		/etc/init.d/smb.sh stop > /dev/null
		backup_conf_file
		[ ! -f /etc/config/secrets.tdb ] || /bin/mv /etc/config/secrets.tdb /etc/config/secrets.tdb_bak
		setup_krb5 $2 $3 $4 $5 > /dev/null
		case "$?" in
			"0")
				RETVAL=$SUCCESS
				;;
			"1")
				RETVAL=$KDC_SRV_ERROR
				;;
			"2")
				RETVAL=$KDC_REALM_ERROR
				;;
			"3")
				RETVAL=$KDC_TIME_DIFF_ERROR
				;;
			"4")
				RETVAL=$KDC_SRV_ADNAME_ERROR
				;;
			*)
				RETVAL=$KNOWN_ERROR
		esac
		if [ $RETVAL -ne $SUCCESS ]; then
			restore_conf_file
			/etc/init.d/smb.sh start > /dev/null
			exit $RETVAL
		fi
		clean_smb_conf $SMB_CONF
		clean_winbind_cache
		join_ad $SMB_CONF $2 $3 $4 $5
		if [ "$?" = "0" ]; then 
			clean_backup_file
			/etc/init.d/ads_register_dns.sh
			RETVAL=$SUCCESS
		else
			restore_conf_file
			RETVAL=$AD_JOIN_ERROR
		fi
		$SETCFG "Samba" "User" "$auth_user"
		$SETCFG "Samba" "Password" "$auth_pwd"
		$SETCFG "Samba" "OU" "$AD_OU"
		$0 update_password_server > /dev/null
		/etc/init.d/smb.sh start > /dev/null
		add_printer_admin_main
		clean_domain_db
		if [ $RETVAL = $SUCCESS ]; then
			/bin/cat /etc/config/crontab | /bin/grep idmap.sh > /dev/null
			if [ $? -ne 0 ]; then
				echo "0 3 * * 0 /etc/init.d/idmap.sh dump" >> /etc/config/crontab
				/usr/bin/crontab /etc/config/crontab
			fi
		fi
#		import_remote_accounts
#		set_sync_account_schedule
		exit $RETVAL
		;;
	"NTM")		#NT member
		[ "$#" = "4" ] || ( echo "USAGE: $0 $1 NT_DOMAIN ADM_ACCOUNT ADM_PASSWD!"; exit $USAGE_ERROR )
		/etc/init.d/smb.sh stop > /dev/null
		backup_conf_file
		clean_smb_conf $SMB_CONF
		clean_winbind_cache
		join_ntd $SMB_CONF $2 $3 $4
		if [ "$?" = "0" ]; then 
			clean_backup_file
			[ ! -f ${KRB5_CONF} ] || rm -f ${KRB5_CONF}
			RETVAL=$SUCCESS
		else
			restore_conf_file
			RETVAL=$NTD_JOIN_ERROR
		fi
		$SETCFG "Samba" "User" "$auth_user"
		$SETCFG "Samba" "Password" "$auth_pwd"
		/etc/init.d/smb.sh start > /dev/null
		sleep 3
                add_printer_admin_main
		clean_domain_db

#		import_remote_accounts
#		set_sync_account_schedule
		exit $RETVAL
		;;
#	"PDC")		#NT PDC
#		;;
#	"BDC")		#NT BDC
#		;;
	"ALONE")	#Stand Alone Server
		[ "$#" = "2" ] || ( echo "USAGE: $0 $1 WORKGROUP_NAME!"; exit $USAGE_ERROR )
		/etc/init.d/idmap.sh leave_dump
		/bin/sed -i '/idmap.sh/d' /etc/config/crontab
		/usr/bin/crontab /etc/config/crontab
		/etc/init.d/smb.sh stop > /dev/null
#		if [ `$GETCFG global security -f $SMB_CONF -u -d ADS` = ADS ]; then
#			$NET ads leave
#		fi
		backup_conf_file
		clean_smb_conf $SMB_CONF	
		alone_set_smb_conf $SMB_CONF
		add_printer_admin_main
#		clean_imported_accounts
		/etc/init.d/smb.sh start > /dev/null
		clean_backup_file
		clean_domain_db
#		clear_sync_account_schedule
		[ ! -f ${KRB5_CONF} ] || rm -f ${KRB5_CONF}
		;;
	"update_winbind_separator")
		update_ad_user_group $2 $3
		;;
	"update_password_server")
		update_password_server
		;;
	"update_krb5_ticket")
		update_krb5_ticket
		;;
	*)
		echo "USAGE: $0 [ADM|NTM|ALONE|update_winbind_separator|update_password_server|update_krb5_ticket] [arguments]!"
		exit $USAGE_ERROR
		;;
esac

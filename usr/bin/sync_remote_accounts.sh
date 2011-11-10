#!/bin/sh

# constants
SUCCESS=0
USAGE_ERROR=1
IMPORT_ERROR=2

# utilities
GETCFG=/sbin/getcfg
WBINFO=/usr/bin/wbinfo
WRITELOG=/sbin/write_log

show_usage()
{
	echo "USAGE: $0 [-u|user|-g|group|-a|all]!"
	echo "DESCRIPTION: used only when the system is working as a AD/NT domain member."
}

init_global_variables()
{
	RETVAL=$SUCCESS
	SMB_ENABLED=`$GETCFG Samba Enable -d "FALSE" -u`
	if [ "$SMB_ENABLED" = "FALSE" ]; then
		SMB_TYPE=NONE
	else
		SMB_TYPE=`$GETCFG global security -d "USER" -u -f /etc/config/smb.conf`
	fi

	case "$sync_type" in
		"-u") 		#import remote user
			IMPORT_OPTION="users"
			;;
		"user")
			IMPORT_OPTION="users"
			;;
		"-g")
			IMPORT_OPTION="groups"
			;;
		"group")
			IMPORT_OPTION="groups"
			;;
		*)
			show_usage
			exit $USAGE_ERROR
			;;
	esac
}

# main pogram here
sync_type=$1
init_global_variables

if [ "$SMB_ENABLED" = "FALSE" ]; then
	show_usage
	exit $USAGE_ERROR
fi

case "$SMB_TYPE" in
	"ADS")		#AD member
		$WBINFO "--import-$IMPORT_OPTION"
		if [ "$?" = "0" ]; then 
			RETVAL=$SUCCESS
		else
			RETVAL=$IMPORT_ERROR
			$WRITELOG "Failed to sync remote $IMPORT_OPTION" 2
		fi
		exit $RETVAL
		;;
	"DOMAIN")	#NT member
		$WBINFO "--import-$IMPORT_OPTION"
		if [ "$?" = "0" ]; then 
			RETVAL=$SUCCESS
		else
			RETVAL=$IMPORT_ERROR
			$WRITELOG "Failed to sync remote $IMPORT_OPTION" 2
		fi
		exit $RETVAL
		;;
	*)		#stand-alone
		show_usage
		exit $USAGE_ERROR
		;;	
esac

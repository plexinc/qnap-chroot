#! /bin/sh

if [ "$1" = "" ]
then
	echo "Usage: initnas.sh RaidType"
	exit 0
fi

/sbin/initdisk $1 `/sbin/getcfg Storage "Disk Drive Number"` 2>/dev/null 1>/dev/null
RET=$?
if [ $RET != 0 ]; then
	exit $RET
fi

/sbin/addshare public /share/MD0_DATA/public -p -c -gr -gw 2>/dev/null 1>/dev/null
RET=$?
if [ $RET != 0 ]; then
	exit $RET
fi

/sbin/report_log -C
exit 0

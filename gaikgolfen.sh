#!/usr/bin/env sh

if ! command -v php >/dev/null; then
    echo 'Require php' >&2;
    exit 1;
fi;

if [ "$(php -v | head -n1 | grep -o "PHP [0-9]\+" | cut -c5-)" -lt 7 ]; then
    echo 'PHP 7 or higher required' >&2;
    exit 2;
fi

login="${1}";
if [ -z "${login}" ]; then
    read -p 'Login: ' login;
    if [ -z "${login}" ]; then
        echo 'Login not set' >&2;
        exit 3;
    fi
fi

read -p 'Password: ' passwd;
if [ -z "${passwd}" ]; then
    echo 'Password not set' >&2;
    exit 4;
else
    passwd=$(echo "${passwd}" | base64);
fi

interval="${2:-600}";
course="${2:-64}";
cache=gig.cache;
lastSat='';
lastSun='';

while true; do
    newSat=$(./gaikgolfen.php --login="${login}" --passwd="${passwd}" --date='saturday' --display=table --course="${course}" --cache="${cache}");
    if [ $? = 10 ]; then
        echo 'Could not login' >&2;
        exit 4;
    fi

    newSun=$(./gaikgolfen.php -v --login="${login}" --passwd="${passwd}" --date='sunday' --display=table --course="${course}" --cache="${cache}");
    url=$(echo "${newSun}" | head -n1);
    newSun=$(echo "${newSun}" | tail -n +2);

    if [ "${newSat}" != "${lastSat}" ] || [ "${newSun}" != "${lastSun}" ]; then
        [ -x "$(command -v notify-send)" ] && notify-send -i "$(pwd)/gig.png" -t 3000 'Gaikgolfen' 'Command output changed';
        sleep 1;
        [ -x "$(command -v firefox)" ] && firefox --new-tab "${url}";
    fi

    lastSat="${newSat}";
    lastSun="${newSun}";

    date;
    echo '';
    echo 'ZATERDAG';
    echo '========';
    echo "${newSat}";

    echo '';
    echo 'ZONDAG';
    echo '======';
    echo "${newSun}";
    echo '';

    sleep "${interval}";
done;

if [ -f "${cache}" ]; then
    rm "${cache}";
fi

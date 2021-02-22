#!/usr/bin/env bash

if command -v git >/dev/null; then
    git pull --quiet origin master;
fi

if ! command -v php >/dev/null; then
    echo 'Require php' >&2;
    exit 1;
fi;

if [ "$(php -v | head -n1 | grep -o "PHP [0-9]\+" | cut -c5-)" -lt 7 ]; then
    echo 'Require PHP 7 or higher' >&2;
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

read -sp 'Password: ' passwd;
if [ -z "${passwd}" ]; then
    echo 'Password not set' >&2;
    exit 4;
else
    passwd=$(echo "${passwd}" | base64);
fi

declare -A outputs;
cache_file="$(pwd)/gig.cache";
interval="${3:-600}";
course="${2:-64}";

while true; do
    did_notify=0;

    while read -r dateEntry; do
        if [ -z "${dateEntry}" ]; then
            continue;
        fi

        new_output=$(./gaikgolfen.php -v --login="${login}" --passwd="${passwd}" --date="next ${dateEntry}" --display=table --course="${course}" --cache="${cache_file}");
        url=$(echo "${new_output}" | head -n1);
        new_output=$(echo "${new_output}" | tail -n +2);

        if [ "${outputs[$dateEntry]}" != "${new_output}" ]; then
            outputs[$dateEntry]="${new_output}";

            if [ "${did_notify}" -eq 0 ]; then
                if [ -x "$(command -v termux-notification)" ]; then
                    termux-notification --title 'Gaikgolfen?' \
                                        --ccontent 'Command output changed' \
                                        --image-path "$(pwd)/gig.png" \
                                        --vibrate 200,20,20 \
                                        --action "termux-open-url '${url}'";
                fi

                if [ -x "$(command -v notify-send)" ]; then
                    notify-send -i "$(pwd)/gig.png" -t 3000 'Gaikgolfen?' 'Command output changed';
                fi

                did_notify=1;
            fi

            date +'%A %B %d %T';
            echo "${dateEntry}";
            echo $(echo "${dateEntry}" | sed 's/./=/g');
            echo "${new_output}";
            echo '';
        fi
    done < ./dates.txt;

    sleep "${interval}";
done;

if [ -f "${cache_file}" ]; then
    rm "${cache_file}";
fi

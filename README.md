## Ga ik golfen?

This repository is helps you monitor tee times for https://www/gaikgolfen.nl/.
It can run as a separate command, or as a daemon checking at regular intervals.

### Run on Android:
- Install Termux and Termux:API from the Google Play Store.
- To get started, open Termux and type the following commands:
    - `pkg install php`
    - `pkg install git`
    - `git clone https://github.com/KaspervdHeijden/gaikgolfen.git`

When you want to monitor teetimes:
- Open Termux
- Run `./gaikgolfen/gaikgolfen -l '<your-ik-ga-golfen-login>'`

To stop, Ctrl+C or click `Exit` from Termux notification tray.

Note: To be able to run when your phone is idle you'll need to click `Acquire Wakelock`
from the Termux notification tray.

### Run on Debian:
- To get started, install:
  - php (>= version 7)
  - git
  - Clone this repository (`git clone https://github.com/KaspervdHeijden/gaikgolfen.git`)

When you want to monitor teetimes:
- Open a terminal (Ctrl+T)
- Run `./gaikgolfen/gaikgolfen -l '<your-ik-ga-golfen-login>'`

When running the above daemon script, you will be prompted for your ik-ga-golfen password.
Once provided, the deamon will check every 10 minutes (by default). You will receive a
notification when the output changes.

You can adjust:
- The interval: pass `-i <seconds>` e.g.: `./gaikgolfen -i 300` to check every 5 minutes.
- The golfcourse: pass `-c <course>`. By default, the course will be `64` (Bentwoud).
  Values can be obtained by inspecting the select box on the teetimes page at www.ikgagolfen.nl.
- The number of columns: pass `-n <columns>`. Defaults to `4`. This is the number of columns to parse the
  teetimes for. E.g. when passing 3 on Bentwoud, it will _not_ parse the par 3 course.
- Edit the dates you want to monitor by editing `dates.txt`. Blank lines and lines starting with a hash (#)
  will be skipped. Other lines must be parseable to a date according to the formats defined at 
  https://www.php.net/manual/en/datetime.formats.php 
  By default saturday and sunday are monitored.

Note: Credentials are cached. If you don't want this, pass `-s`.

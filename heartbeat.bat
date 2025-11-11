@echo off
setlocal
cd /d C:\flovahub
git pull
echo update: %date% %time%>> heartbeat.txt
git add heartbeat.txt
git commit -m "chore: daily heartbeat %date%"
git push
endlocal

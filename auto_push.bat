@echo off
setlocal EnableDelayedExpansion
cd /d C:\flovahub

git pull

:: ensure target folder exists
if not exist src\snippets mkdir src\snippets

:: get a safe ISO timestamp via PowerShell
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HH-mm-ss"') do set TS=%%i

:: pick ONE queued PHP file
set "NEXT="
for %%F in (tasks_queue\*.php) do (
  set "NEXT=%%F"
  goto HAVE_FILE
)

echo no queued php file found, nothing to commit
goto END

:HAVE_FILE
set "OUT=src\snippets\snippet_!TS!.php"
move "!NEXT!" "!OUT!" >nul

echo - %date% %time%: add !OUT!>> CHANGELOG.md

git add "!OUT!" CHANGELOG.md
git commit -m "feat: add !OUT!" || goto END
git push

:END
endlocal

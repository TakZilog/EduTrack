@echo off
title EduTrack - Export Database
echo ============================================================
echo           EDUTRACK - DATABASE BACKUP EXPORTER
echo ============================================================
echo.
set MYSQL_DUMP=c:\xampp\mysql\bin\mysqldump.exe

if not exist "%MYSQL_DUMP%" (
    echo [ERROR] mysqldump.exe not found at %MYSQL_DUMP%
    pause
    exit /b 1
)

echo Exporting 'edutrack' database to sql\edutrack_backup.sql...
"%MYSQL_DUMP%" -u root edutrack > "%~dp0..\sql\edutrack_backup.sql"

if errorlevel 1 (
    echo [ERROR] Database export failed. Make sure MySQL is running in XAMPP!
) else (
    echo [SUCCESS] Backup created at: sql\edutrack_backup.sql
)

echo.
pause

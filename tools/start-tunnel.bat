@echo off
title EduTrack Permanent Custom Domain Tunnel (edutrack.art)
color 0b
echo ============================================================
echo      EDUTRACK - SECURE PERMANENT TUNNEL (edutrack.art)
echo ============================================================
echo.
echo Checking that XAMPP Apache is running...
curl -s -o nul http://localhost/EduTrack/
if errorlevel 1 (
    echo [WARNING] Could not reach http://localhost/EduTrack/
    echo Please make sure Apache and MySQL are started in XAMPP!
    echo.
) else (
    echo [OK] Local EduTrack server is responding!
    echo.
)

echo Starting Cloudflare Tunnel for edutrack.art...
echo.
echo Your system is live at:
echo   --^> https://edutrack.art/
echo   --^> https://www.edutrack.art/
echo.
echo Press Ctrl+C anytime to stop sharing.
echo ============================================================
echo.

"%~dp0cloudflared.exe" tunnel run edutrack
pause

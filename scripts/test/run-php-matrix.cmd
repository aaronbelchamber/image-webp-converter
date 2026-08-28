@echo off
REM ---------------------------------------------------------------------------
REM Run this plugin's test suite against every PHP environment in the matrix.
REM
REM The point is the hostile ones. This plugin has branches that a single
REM workstation cannot reach: a GD build that encodes WebP but cannot decode
REM it, CMYK JPEG handling when Imagick is absent, a lossless path needing
REM PHP 8.1's IMG_WEBP_LOSSLESS, and a PHP 7.4 imagedestroy() call. Those are
REM only exercised here.
REM
REM Requires wp-dev-playground installed and a container runtime running:
REM   pip install -e E:\ab-code-projects\projects\wp-dev-playground
REM   wpp doctor
REM ---------------------------------------------------------------------------
setlocal
set "PLUGIN=%~dp0..\.."

where wpp >nul 2>nul
if errorlevel 1 (
  echo.
  echo   wpp not found on PATH.
  echo   Install it:  pip install -e E:\ab-code-projects\projects\wp-dev-playground
  echo.
  exit /b 1
)

wpp doctor >nul 2>nul
if errorlevel 1 (
  echo.
  echo   No container runtime responding. Run "wpp doctor" for the detail.
  echo.
  exit /b 1
)

REM --variants all includes the end-of-life PHP images, which are exactly the
REM ones this plugin declares support for. A default run would skip them.
wpp run "%PLUGIN%" --variants all %*
exit /b %errorlevel%

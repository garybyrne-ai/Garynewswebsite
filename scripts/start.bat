@echo off
rem Local development server for ME News Ireland (Windows).
cd /d "%~dp0\.."
where php >nul 2>nul
if errorlevel 1 (
    echo Install PHP 8.2+ with pdo_sqlite, curl, mbstring, fileinfo and simplexml, then add PHP to PATH.
    pause
    exit /b 1
)
if not exist .env (
    copy .env.example .env >nul
    echo Created .env from .env.example.
)
if not exist storage\data\menews.sqlite (
    php scripts\setup.php --seed
    if errorlevel 1 ( pause & exit /b 1 )
)
echo ME News Ireland  -^>  http://127.0.0.1:8000
php -S 127.0.0.1:8000 -t public public\router.php

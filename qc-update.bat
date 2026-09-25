@echo off
REM ==============================================================================
REM QC LOCAL AUTO WATCH - SMART CASSAVAS
REM Launcher tu dong vuot qua ExecutionPolicy cua Windows PowerShell
REM ==============================================================================
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0qc-update.ps1" %*

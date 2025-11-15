#!/bin/bash
# Deployment script for Class System
# Lists files that need to be uploaded to InfinityFree

echo "================================================"
echo "  CLASS SYSTEM - DEPLOYMENT FILE LIST"
echo "================================================"
echo ""

echo "📦 NYA FILER (skapa på servern):"
echo "-----------------------------------"
echo "1. admin/system-settings.php"
if [ -f "admin/system-settings.php" ]; then
    SIZE=$(du -h admin/system-settings.php | cut -f1)
    echo "   ✅ Finns lokalt ($SIZE)"
else
    echo "   ❌ Saknas lokalt!"
fi

echo ""
echo "2. includes/class-calculations.php"
if [ -f "includes/class-calculations.php" ]; then
    SIZE=$(du -h includes/class-calculations.php | cut -f1)
    echo "   ✅ Finns lokalt ($SIZE)"
else
    echo "   ❌ Saknas lokalt!"
fi

echo ""
echo "3. database/migrations/008_classes_system.sql"
if [ -f "database/migrations/008_classes_system.sql" ]; then
    SIZE=$(du -h database/migrations/008_classes_system.sql | cut -f1)
    echo "   ✅ Finns lokalt ($SIZE)"
else
    echo "   ❌ Saknas lokalt!"
fi

echo ""
echo "-----------------------------------"
echo "🔄 UPPDATERADE FILER (ersätt på servern):"
echo "-----------------------------------"

echo "4. includes/navigation.php"
if [ -f "includes/navigation.php" ]; then
    SIZE=$(du -h includes/navigation.php | cut -f1)
    echo "   ✅ Finns lokalt ($SIZE)"
else
    echo "   ❌ Saknas lokalt!"
fi

echo ""
echo "5. admin/import-results-preview.php"
if [ -f "admin/import-results-preview.php" ]; then
    SIZE=$(du -h admin/import-results-preview.php | cut -f1)
    echo "   ✅ Finns lokalt ($SIZE)"
else
    echo "   ❌ Saknas lokalt!"
fi

echo ""
echo "================================================"
echo "  DEPLOYMENT-STEG"
echo "================================================"
echo ""
echo "Steg 1: Ladda upp alla filer via FTP/cPanel File Manager"
echo "Steg 2: Gå till /admin/system-settings.php?tab=migrations"
echo "Steg 3: Kör migration 008_classes_system.sql"
echo "Steg 4: Verifiera i Systeminställningar → Klasser"
echo ""
echo "📋 Se DEPLOYMENT-CHECKLIST.md för fullständiga instruktioner"
echo "================================================"
echo ""

# Skapa en lista med fullständiga sökvägar för enkel kopiering
echo "💾 Fullständiga filsökvägar (för FTP-uppladdning):"
echo "-----------------------------------"
echo "/home/user/TheHUB/admin/system-settings.php"
echo "/home/user/TheHUB/includes/class-calculations.php"
echo "/home/user/TheHUB/database/migrations/008_classes_system.sql"
echo "/home/user/TheHUB/includes/navigation.php"
echo "/home/user/TheHUB/admin/import-results-preview.php"
echo ""

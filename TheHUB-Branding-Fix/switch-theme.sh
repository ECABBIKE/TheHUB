#!/bin/bash
# TheHUB Branding Theme Switcher
# Usage: ./switch-theme.sh [theme-name]

BRANDING_FILE="uploads/branding.json"

# Backup current branding
backup_branding() {
    if [ -f "$BRANDING_FILE" ]; then
        cp "$BRANDING_FILE" "${BRANDING_FILE}.backup.$(date +%Y%m%d_%H%M%S)"
        echo "✓ Backup skapad"
    fi
}

# Apply theme
apply_theme() {
    local theme=$1
    backup_branding
    
    case $theme in
        "standard"|"cyan")
            cat > "$BRANDING_FILE" << 'EOF'
{
  "colors": {
    "light": {
      "--color-bg-page": "#f8f9fa",
      "--color-bg-surface": "#ffffff",
      "--color-bg-card": "#ffffff",
      "--color-accent": "#2bc4c6",
      "--color-border": "rgba(55, 212, 214, 0.15)"
    },
    "dark": {
      "--color-bg-page": "#0b131e",
      "--color-bg-surface": "#0d1520",
      "--color-bg-card": "#0e1621",
      "--color-accent": "#37d4d6",
      "--color-border": "rgba(55, 212, 214, 0.2)"
    }
  }
}
EOF
            echo "✓ Cyan/GravitySeries tema aktiverat"
            ;;
            
        "red"|"röd")
            cat > "$BRANDING_FILE" << 'EOF'
{
  "colors": {
    "light": {
      "--color-bg-page": "#ffe0e0",
      "--color-bg-surface": "#fff5f5",
      "--color-bg-card": "#ffffff",
      "--color-accent": "#e63946",
      "--color-border": "rgba(230, 57, 70, 0.2)"
    },
    "dark": {
      "--color-bg-page": "#1a0000",
      "--color-bg-surface": "#2d0000",
      "--color-bg-card": "#3d0000",
      "--color-accent": "#ff6b6b",
      "--color-border": "rgba(255, 107, 107, 0.3)"
    }
  }
}
EOF
            echo "✓ Rött tema aktiverat"
            ;;
            
        "blue"|"blå")
            cat > "$BRANDING_FILE" << 'EOF'
{
  "colors": {
    "light": {
      "--color-bg-page": "#eff6ff",
      "--color-bg-surface": "#dbeafe",
      "--color-bg-card": "#ffffff",
      "--color-accent": "#3b82f6",
      "--color-border": "rgba(59, 130, 246, 0.2)"
    },
    "dark": {
      "--color-bg-page": "#0c1e3e",
      "--color-bg-surface": "#112446",
      "--color-bg-card": "#1a3257",
      "--color-accent": "#60a5fa",
      "--color-border": "rgba(96, 165, 250, 0.3)"
    }
  }
}
EOF
            echo "✓ Blått tema aktiverat"
            ;;
            
        "green"|"grön")
            cat > "$BRANDING_FILE" << 'EOF'
{
  "colors": {
    "light": {
      "--color-bg-page": "#f0fdf4",
      "--color-bg-surface": "#dcfce7",
      "--color-bg-card": "#ffffff",
      "--color-accent": "#22c55e",
      "--color-border": "rgba(34, 197, 94, 0.2)"
    },
    "dark": {
      "--color-bg-page": "#0a2e1a",
      "--color-bg-surface": "#0d3d20",
      "--color-bg-card": "#0e4428",
      "--color-accent": "#4ade80",
      "--color-border": "rgba(74, 222, 128, 0.3)"
    }
  }
}
EOF
            echo "✓ Grönt tema aktiverat"
            ;;
            
        "purple"|"lila")
            cat > "$BRANDING_FILE" << 'EOF'
{
  "colors": {
    "light": {
      "--color-bg-page": "#faf5ff",
      "--color-bg-surface": "#f3e8ff",
      "--color-bg-card": "#ffffff",
      "--color-accent": "#a855f7",
      "--color-border": "rgba(168, 85, 247, 0.2)"
    },
    "dark": {
      "--color-bg-page": "#1e0a2e",
      "--color-bg-surface": "#2d0f44",
      "--color-bg-card": "#3d1559",
      "--color-accent": "#c084fc",
      "--color-border": "rgba(192, 132, 252, 0.3)"
    }
  }
}
EOF
            echo "✓ Lila tema aktiverat"
            ;;
            
        "gray"|"grå")
            cat > "$BRANDING_FILE" << 'EOF'
{
  "colors": {
    "light": {
      "--color-bg-page": "#f8f9fa",
      "--color-bg-surface": "#ffffff",
      "--color-bg-card": "#ffffff",
      "--color-accent": "#64748b",
      "--color-border": "rgba(100, 116, 139, 0.2)"
    },
    "dark": {
      "--color-bg-page": "#0f172a",
      "--color-bg-surface": "#1e293b",
      "--color-bg-card": "#334155",
      "--color-accent": "#94a3b8",
      "--color-border": "rgba(148, 163, 184, 0.3)"
    }
  }
}
EOF
            echo "✓ Grått tema aktiverat"
            ;;
            
        "reset"|"återställ")
            cat > "$BRANDING_FILE" << 'EOF'
{
  "colors": {},
  "responsive": {
    "mobile_portrait": {"padding": "12", "radius": "0"},
    "mobile_landscape": {"sidebar_gap": "4"},
    "tablet": {"padding": "24", "radius": "8"},
    "desktop": {"padding": "32", "radius": "12"}
  },
  "layout": {
    "content_max_width": "1400",
    "sidebar_width": "72",
    "header_height": "60"
  },
  "logos": {
    "sidebar": "",
    "homepage": "",
    "favicon": "/assets/favicon.svg"
  }
}
EOF
            echo "✓ Branding återställd till standard (theme.css värden)"
            ;;
            
        *)
            echo "❌ Okänt tema: $theme"
            echo ""
            echo "Tillgängliga teman:"
            echo "  standard, cyan  - GravitySeries cyan tema"
            echo "  red, röd        - Rött tema"
            echo "  blue, blå       - Blått tema"
            echo "  green, grön     - Grönt tema"
            echo "  purple, lila    - Lila tema"
            echo "  gray, grå       - Grått tema"
            echo "  reset           - Återställ till defaults"
            echo ""
            echo "Användning: $0 [tema-namn]"
            exit 1
            ;;
    esac
    
    # Validate JSON
    if command -v jq &> /dev/null; then
        if jq empty "$BRANDING_FILE" 2>/dev/null; then
            echo "✓ JSON validerad"
        else
            echo "⚠ JSON-fel i branding.json!"
            exit 1
        fi
    fi
    
    echo ""
    echo "🎨 Tema applicerat! Ladda om webbläsaren för att se ändringarna."
}

# Show current theme colors
show_current() {
    if [ -f "$BRANDING_FILE" ]; then
        echo "Nuvarande branding:"
        echo "==================="
        if command -v jq &> /dev/null; then
            jq '.colors' "$BRANDING_FILE"
        else
            cat "$BRANDING_FILE"
        fi
    else
        echo "Ingen branding.json hittades"
    fi
}

# Main
if [ "$#" -eq 0 ]; then
    show_current
    echo ""
    echo "För att byta tema: $0 [tema-namn]"
    echo "Använd '$0 help' för att se tillgängliga teman"
elif [ "$1" = "help" ] || [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    echo "TheHUB Branding Theme Switcher"
    echo "=============================="
    echo ""
    echo "Användning: $0 [tema-namn]"
    echo ""
    echo "Tillgängliga teman:"
    echo "  standard, cyan  - GravitySeries cyan tema (default)"
    echo "  red, röd        - Rött tema"
    echo "  blue, blå       - Blått tema"
    echo "  green, grön     - Grönt tema"
    echo "  purple, lila    - Lila tema"
    echo "  gray, grå       - Grått tema"
    echo "  reset           - Återställ till defaults"
    echo ""
    echo "Exempel:"
    echo "  $0 blue          # Aktivera blått tema"
    echo "  $0 reset         # Återställ till standard"
    echo "  $0               # Visa nuvarande tema"
else
    apply_theme "$1"
fi

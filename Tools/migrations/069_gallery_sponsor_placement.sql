-- Migration 069: Lägg till gallery som page_type i sponsor_placements
-- Möjliggör dedikerade galleri-banners som överskriver event/serie-sponsorer

-- Utöka page_type ENUM med 'gallery'
ALTER TABLE sponsor_placements
MODIFY COLUMN page_type ENUM(
    'home', 'results', 'series_list', 'series_single', 'database',
    'ranking', 'calendar', 'rider', 'club', 'event',
    'blog', 'blog_single', 'gallery', 'all'
) NOT NULL;

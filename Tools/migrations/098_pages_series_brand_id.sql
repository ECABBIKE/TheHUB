-- Add series_brand_id to pages table for linking CMS pages to series brands
ALTER TABLE pages ADD COLUMN series_brand_id INT NULL DEFAULT NULL AFTER hero_overlay_opacity;
ALTER TABLE pages ADD INDEX idx_pages_series_brand_id (series_brand_id);

-- Seed a draft info page for each active series brand
INSERT INTO pages (slug, title, meta_description, content, template, status, show_in_nav, nav_order, series_brand_id, created_at)
SELECT
    sb.slug,
    sb.name,
    CONCAT(sb.name, ' — tävlingsserie inom GravitySeries'),
    CONCAT('<h2>Om ', sb.name, '</h2>\n<p>Här kommer information om ', sb.name, '.</p>'),
    'default',
    'draft',
    0,
    99,
    sb.id,
    NOW()
FROM series_brands sb
WHERE sb.active = 1
AND NOT EXISTS (SELECT 1 FROM pages p WHERE p.slug = sb.slug);

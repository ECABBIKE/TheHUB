-- Migration: Create pages table for GravitySeries CMS
-- Date: 2026-03-10

CREATE TABLE IF NOT EXISTS pages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  meta_description VARCHAR(300) DEFAULT NULL,
  content LONGTEXT NOT NULL,
  template ENUM('default','full-width','landing') DEFAULT 'default',
  status ENUM('published','draft') DEFAULT 'draft',
  show_in_nav TINYINT(1) DEFAULT 0,
  nav_order INT DEFAULT 99,
  nav_label VARCHAR(60) DEFAULT NULL,
  hero_image VARCHAR(255) DEFAULT NULL,
  hero_image_position ENUM('center','top','bottom') DEFAULT 'center',
  hero_overlay_opacity TINYINT DEFAULT 50,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sponsors table for GravitySeries homepage
CREATE TABLE IF NOT EXISTS gs_sponsors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  website_url VARCHAR(500) DEFAULT NULL,
  logo_url VARCHAR(500) DEFAULT NULL,
  type ENUM('sponsor','collaborator') DEFAULT 'sponsor',
  sort_order INT DEFAULT 99,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

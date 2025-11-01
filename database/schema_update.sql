-- Drop existing tables if they exist
DROP TABLE IF EXISTS post_videos;
DROP TABLE IF EXISTS post_views;
DROP TABLE IF EXISTS moderation_logs;
DROP TABLE IF EXISTS featured_posts;
DROP TABLE IF EXISTS news_tags;
DROP TABLE IF EXISTS tags;
DROP TABLE IF EXISTS comments;
DROP TABLE IF EXISTS news_reactions;
DROP TABLE IF EXISTS news_verification;
DROP TABLE IF EXISTS news_posts;
DROP TABLE IF EXISTS locations;
DROP TABLE IF EXISTS countries;
DROP TABLE IF EXISTS states;
DROP TABLE IF EXISTS cities;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS users;

-- Users table with role support
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    profile_image VARCHAR(255),
    is_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- User roles
CREATE TABLE user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role ENUM('user', 'reporter', 'moderator', 'admin') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_role (user_id, role)
);

-- Countries table
CREATE TABLE countries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    iso2 CHAR(2) NOT NULL,
    iso3 CHAR(3) NOT NULL,
    phone_code VARCHAR(10),
    capital VARCHAR(100),
    currency VARCHAR(3),
    native_name VARCHAR(100),
    region VARCHAR(50),
    subregion VARCHAR(50),
    emoji VARCHAR(10),
    emojiU VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_country_iso2 (iso2),
    UNIQUE KEY unique_country_iso3 (iso3)
);

-- States/Provinces table
CREATE TABLE states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    country_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    state_code VARCHAR(10) NOT NULL,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE,
    UNIQUE KEY unique_state (country_id, state_code)
);

-- Cities table
CREATE TABLE cities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_id INT NOT NULL,
    country_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (state_id) REFERENCES states(id) ON DELETE CASCADE,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE,
    UNIQUE KEY unique_city (state_id, name)
);

-- Locations table (for more specific locations within cities)
CREATE TABLE locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    country_id INT NOT NULL,
    state_id INT,
    city_id INT,
    name VARCHAR(255) NOT NULL,
    address TEXT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE,
    FOREIGN KEY (state_id) REFERENCES states(id) ON DELETE SET NULL,
    FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL
);

-- News posts
CREATE TABLE news_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    location_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    excerpt TEXT,
    content LONGTEXT NOT NULL,
    image_url VARCHAR(255),
    video_url VARCHAR(255),
    video_embed_code TEXT,
    category ENUM(
        'crime', 'accident', 'event', 'weather', 'traffic', 
        'business', 'politics', 'health', 'education', 'sports',
        'entertainment', 'technology', 'environment', 'science', 'other'
    ) NOT NULL,
    status ENUM('draft', 'pending', 'published', 'rejected', 'archived') DEFAULT 'draft',
    is_breaking BOOLEAN DEFAULT FALSE,
    is_emergency BOOLEAN DEFAULT FALSE,
    is_featured BOOLEAN DEFAULT FALSE,
    view_count INT DEFAULT 0,
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    FULLTEXT(title, content, excerpt),
    UNIQUE KEY unique_slug (slug)
);

-- Post videos table (for multiple videos per post)
CREATE TABLE post_videos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    news_id INT NOT NULL,
    video_url VARCHAR(255) NOT NULL,
    thumbnail_url VARCHAR(255),
    video_type ENUM('upload', 'youtube', 'vimeo', 'facebook', 'other') NOT NULL,
    title VARCHAR(255),
    description TEXT,
    duration INT COMMENT 'Duration in seconds',
    width INT,
    height INT,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (news_id) REFERENCES news_posts(id) ON DELETE CASCADE
);

-- Post views tracking
CREATE TABLE post_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    news_id INT NOT NULL,
    user_id INT,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (news_id) REFERENCES news_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Moderation logs
CREATE TABLE moderation_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    news_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (news_id) REFERENCES news_posts(id) ON DELETE CASCADE
);

-- Featured posts
CREATE TABLE featured_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    news_id INT NOT NULL,
    featured_by INT NOT NULL,
    featured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (news_id) REFERENCES news_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (featured_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Tags
CREATE TABLE tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tag_slug (slug)
);

-- News tags relationship
CREATE TABLE news_tags (
    news_id INT NOT NULL,
    tag_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (news_id, tag_id),
    FOREIGN KEY (news_id) REFERENCES news_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

-- News verification
CREATE TABLE news_verification (
    id INT AUTO_INCREMENT PRIMARY KEY,
    news_id INT NOT NULL,
    verified_by INT NOT NULL,
    status ENUM('verified', 'disputed') NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (news_id) REFERENCES news_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Comments
CREATE TABLE comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    news_id INT NOT NULL,
    user_id INT NOT NULL,
    parent_id INT,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (news_id) REFERENCES news_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE SET NULL
);

-- News reactions (likes, etc.)
CREATE TABLE news_reactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    news_id INT NOT NULL,
    user_id INT NOT NULL,
    reaction_type ENUM('like', 'dislike', 'love', 'sad', 'angry') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (news_id) REFERENCES news_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_reaction (news_id, user_id)
);

-- Create indexes for better performance
CREATE INDEX idx_news_posts_status ON news_posts(status);
CREATE INDEX idx_news_posts_created_at ON news_posts(created_at);
CREATE INDEX idx_news_posts_location ON news_posts(location_id);
CREATE INDEX idx_comments_news ON comments(news_id);
CREATE INDEX idx_news_reactions_news ON news_reactions(news_id);

-- Insert default admin user (password: Admin@123 - change this in production!)
INSERT INTO users (
    username, 
    email, 
    password_hash, 
    full_name,
    is_verified,
    profile_image,
    created_at,
    updated_at
) VALUES (
    'admin', 
    'admin@example.com', 
    '$2y$12$QjSH496pcT5CEbzjD/vtVehe03tf9FqlD/NlK/Q6ABX9Aqjx8m8Ie', 
    'Administrator',
    TRUE,
    '/assets/images/default-avatar.png',
    NOW(),
    NOW()
);

INSERT INTO user_roles (user_id, role, created_at) 
VALUES (LAST_INSERT_ID(), 'admin', NOW());

-- Insert some default countries (you can import a complete list later)
INSERT INTO countries (name, iso2, iso3, phone_code, capital, currency, region, subregion) VALUES
('Afghanistan', 'AF', 'AFG', '93', 'Kabul', 'AFN', 'Asia', 'Southern Asia'),
('Albania', 'AL', 'ALB', '355', 'Tirana', 'ALL', 'Europe', 'Southern Europe'),
-- Add more countries as needed
('United States', 'US', 'USA', '1', 'Washington, D.C.', 'USD', 'Americas', 'Northern America'),
('United Kingdom', 'GB', 'GBR', '44', 'London', 'GBP', 'Europe', 'Northern Europe'),
('Nigeria', 'NG', 'NGA', '234', 'Abuja', 'NGN', 'Africa', 'Western Africa');

-- Insert some states (example for a few countries)
-- Nigeria
INSERT INTO states (country_id, name, state_code) VALUES
((SELECT id FROM countries WHERE iso2 = 'NG'), 'Lagos', 'LA'),
((SELECT id FROM countries WHERE iso2 = 'NG'), 'Abuja', 'FC'),
((SELECT id FROM countries WHERE iso2 = 'NG'), 'Kano', 'KN');

-- United States
INSERT INTO states (country_id, name, state_code) VALUES
((SELECT id FROM countries WHERE iso2 = 'US'), 'California', 'CA'),
((SELECT id FROM countries WHERE iso2 = 'US'), 'New York', 'NY'),
((SELECT id FROM countries WHERE iso2 = 'US'), 'Texas', 'TX');

-- United Kingdom
INSERT INTO states (country_id, name, state_code) VALUES
((SELECT id FROM countries WHERE iso2 = 'GB'), 'England', 'ENG'),
((SELECT id FROM countries WHERE iso2 = 'GB'), 'Scotland', 'SCT'),
((SELECT id FROM countries WHERE iso2 = 'GB'), 'Wales', 'WLS');

-- Insert some cities (example for a few states)
-- Lagos, Nigeria
INSERT INTO cities (state_id, country_id, name) VALUES
((SELECT id FROM states WHERE state_code = 'LA' AND country_id = (SELECT id FROM countries WHERE iso2 = 'NG')), 
 (SELECT id FROM countries WHERE iso2 = 'NG'), 'Lagos');

-- New York, USA
INSERT INTO cities (state_id, country_id, name) VALUES
((SELECT id FROM states WHERE state_code = 'NY' AND country_id = (SELECT id FROM countries WHERE iso2 = 'US')), 
 (SELECT id FROM countries WHERE iso2 = 'US'), 'New York');

-- London, UK
INSERT INTO cities (state_id, country_id, name) VALUES
((SELECT id FROM states WHERE state_code = 'ENG' AND country_id = (SELECT id FROM countries WHERE iso2 = 'GB')), 
 (SELECT id FROM countries WHERE iso2 = 'GB'), 'London');

-- Create a default location
INSERT INTO locations (country_id, state_id, city_id, name, created_at) VALUES
((SELECT id FROM countries WHERE iso2 = 'NG'), 
 (SELECT id FROM states WHERE state_code = 'LA' AND country_id = (SELECT id FROM countries WHERE iso2 = 'NG')), 
 (SELECT id FROM cities WHERE name = 'Lagos' AND country_id = (SELECT id FROM countries WHERE iso2 = 'NG')), 
 'Lagos Mainland', NOW());

-- Insert a sample news post
INSERT INTO news_posts (
    user_id, 
    location_id, 
    title, 
    slug,
    excerpt,
    content, 
    category, 
    status, 
    is_breaking,
    created_at,
    updated_at
) VALUES (
    (SELECT id FROM users WHERE username = 'admin'),
    (SELECT id FROM locations WHERE name = 'Lagos Mainland' LIMIT 1),
    'Welcome to LocalPulse',
    'welcome-to-localpulse',
    'Welcome to our news platform where you can share and discover local news.',
    '<p>Welcome to LocalPulse, your go-to platform for local news and updates. This is a sample post to demonstrate the platform\'s features.</p>',
    'other',
    'published',
    TRUE,
    NOW(),
    NOW()
);

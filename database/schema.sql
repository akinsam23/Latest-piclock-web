-- database/schema.sql
CREATE DATABASE IF NOT EXISTS local_places;
USE local_places;

-- Users table for authentication
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Places table
CREATE TABLE places (
    place_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    place_name VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    category ENUM('Restaurant', 'Cafe/Bar', 'Park/Outdoor', 'Church', 'Mosque', 'Temple', 'Retail Store', 'Other') NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    video_source VARCHAR(255) DEFAULT NULL,
    city_region VARCHAR(100) NOT NULL,
    nearby_links JSON DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create a spatial index for proximity searches
ALTER TABLE places ADD SPATIAL INDEX(latitude, longitude);
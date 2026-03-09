-- BikeValue Database Setup
-- Run this ONCE in phpMyAdmin or MySQL CLI:
-- mysql -u root -p < setup_db.sql

CREATE DATABASE IF NOT EXISTS bikevalue CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bikevalue;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     VARCHAR(50)  NOT NULL UNIQUE,
    email       VARCHAR(120) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('user','admin') DEFAULT 'user',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Predictions / bike data table
CREATE TABLE IF NOT EXISTS predictions (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          VARCHAR(50)  NOT NULL,
    bike_name        VARCHAR(100),
    brand            VARCHAR(60),
    engine_cc        INT,
    bike_age         INT,
    owner_type       VARCHAR(40),
    km_driven        INT,
    accident_history TINYINT(1) DEFAULT 0,
    accident_count   INT        DEFAULT 0,
    predicted_price  DECIMAL(12,2),
    ml_price         DECIMAL(12,2) NULL COMMENT 'Price from your Python ML model',
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Insert default admin (password: admin123)
INSERT IGNORE INTO users (user_id, email, password, role)
VALUES ('admin', 'admin@bikevalue.com',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

-- Show confirmation
SELECT 'Database setup complete!' AS status;

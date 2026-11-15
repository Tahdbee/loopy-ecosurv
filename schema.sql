CREATE DATABASE IF NOT EXISTS looply CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE looply;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('buyer', 'seller', 'admin', 'reviewer') NOT NULL DEFAULT 'buyer',
    reviewer_previous_role ENUM('buyer', 'seller') DEFAULT NULL,
    faculty VARCHAR(120) DEFAULT '',
    major VARCHAR(120) DEFAULT '',
    profile_image VARCHAR(255) NOT NULL DEFAULT '',
    payment_instructions VARCHAR(5000) NOT NULL DEFAULT '',
    payment_qr_image VARCHAR(255) NOT NULL DEFAULT '',
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    is_blocked TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(50) NOT NULL,
    description TEXT,
    price DECIMAL(12,2) NOT NULL,
    product_condition VARCHAR(60) NOT NULL,
    image1 VARCHAR(255) DEFAULT 'https://placehold.co/600x400?text=Looply+Item',
    image2 VARCHAR(255) DEFAULT '',
    image3 VARCHAR(255) DEFAULT '',
    is_sold TINYINT(1) NOT NULL DEFAULT 0,
    is_blocked TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE KEY product_image_path (product_id, image_path(191)),
    KEY product_images_order (product_id, sort_order, id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

INSERT IGNORE INTO product_images (product_id, image_path, sort_order)
SELECT id, image1, 0 FROM products WHERE image1 IS NOT NULL AND image1 <> '';
INSERT IGNORE INTO product_images (product_id, image_path, sort_order)
SELECT id, image2, 1 FROM products WHERE image2 IS NOT NULL AND image2 <> '';
INSERT IGNORE INTO product_images (product_id, image_path, sort_order)
SELECT id, image3, 2 FROM products WHERE image3 IS NOT NULL AND image3 <> '';

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    meetup_location VARCHAR(120) NOT NULL,
    meetup_time DATETIME NULL,
    payment_type ENUM('online', 'cash') NOT NULL,
    payment_method VARCHAR(60) DEFAULT '',
    payment_status ENUM('Pending', 'Paid', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending',
    payment_flow ENUM('legacy', 'cash_receipt', 'gopay_qr') NOT NULL DEFAULT 'legacy',
    buyer_payment_proof VARCHAR(255) NOT NULL DEFAULT '',
    seller_cash_receipt VARCHAR(255) NOT NULL DEFAULT '',
    seller_accepted TINYINT(1) NOT NULL DEFAULT 0,
    buyer_completed TINYINT(1) NOT NULL DEFAULT 0,
    seller_completed TINYINT(1) NOT NULL DEFAULT 0,
    amount DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS chats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    reviewee_id INT NOT NULL,
    rating INT NOT NULL,
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_review (transaction_id, reviewer_id),
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewee_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_fav (user_id, product_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    link VARCHAR(255) DEFAULT '',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS staff_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    link VARCHAR(255) NOT NULL DEFAULT '',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY staff_notifications_user_created (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id INT NOT NULL,
    product_id INT DEFAULT NULL,
    reported_user_id INT DEFAULT NULL,
    reason ENUM('scam', 'prohibited_item', 'misleading_listing', 'harassment', 'other') NOT NULL,
    details VARCHAR(2000) NOT NULL DEFAULT '',
    status ENUM('pending', 'reviewed', 'actioned', 'dismissed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_product_report (reporter_id, product_id),
    UNIQUE KEY unique_account_report (reporter_id, reported_user_id),
    KEY reports_status_created (status, created_at),
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS admin_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY admin_documents_created (created_at),
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
);

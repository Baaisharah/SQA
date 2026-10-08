-- =============================================================
-- Suka Dessert Inventory Management System
-- MySQL / XAMPP phpMyAdmin compatible schema and sample data
-- =============================================================

-- Create and select the application database.
CREATE DATABASE IF NOT EXISTS suka_dessert_inventory
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE suka_dessert_inventory;

-- =============================================================
-- Table: suppliers
-- Stores vendors that supply raw materials to Suka Dessert.
-- =============================================================
CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    email VARCHAR(150) NOT NULL,
    address VARCHAR(255) NOT NULL,
    PRIMARY KEY (supplier_id),
    UNIQUE KEY uq_suppliers_email (email)
) ENGINE=InnoDB;

-- =============================================================
-- Table: raw_materials
-- Stores ingredients and packaging materials kept in stock.
-- =============================================================
CREATE TABLE IF NOT EXISTS raw_materials (
    material_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    material_name VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL,
    unit VARCHAR(30) NOT NULL,
    quantity_in_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    reorder_level DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    supplier_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (material_id),
    KEY idx_raw_materials_supplier_id (supplier_id),
    CONSTRAINT fk_raw_materials_supplier
        FOREIGN KEY (supplier_id)
        REFERENCES suppliers (supplier_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB;

-- =============================================================
-- Table: dessert_batches
-- Tracks produced dessert batches and their shelf-life status.
-- =============================================================
CREATE TABLE IF NOT EXISTS dessert_batches (
    batch_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_name VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    production_date DATE NOT NULL,
    expiry_date DATE NOT NULL,
    storage_location VARCHAR(120) NOT NULL,
    status ENUM('Fresh', 'Expiring Soon', 'Expired') NOT NULL DEFAULT 'Fresh',
    PRIMARY KEY (batch_id),
    KEY idx_dessert_batches_status (status),
    KEY idx_dessert_batches_expiry_date (expiry_date)
) ENGINE=InnoDB;

-- =============================================================
-- Table: wastage_logs
-- Records desserts removed from inventory and why they were wasted.
-- =============================================================
CREATE TABLE IF NOT EXISTS wastage_logs (
    log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id INT UNSIGNED NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    quantity_wasted INT UNSIGNED NOT NULL,
    wastage_reason ENUM('Expired', 'Handling Damage', 'Quality Defect') NOT NULL,
    logged_date DATE NOT NULL,
    logged_by VARCHAR(120) NOT NULL,
    PRIMARY KEY (log_id),
    KEY idx_wastage_logs_batch_id (batch_id),
    CONSTRAINT fk_wastage_logs_batch
        FOREIGN KEY (batch_id)
        REFERENCES dessert_batches (batch_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB;

-- =============================================================
-- Table: purchase_orders
-- Records raw-material orders placed with suppliers.
-- =============================================================
CREATE TABLE IF NOT EXISTS purchase_orders (
    order_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    quantity_ordered DECIMAL(10,2) NOT NULL,
    order_status ENUM('Pending', 'Received', 'Cancelled') NOT NULL DEFAULT 'Pending',
    order_date DATE NOT NULL,
    PRIMARY KEY (order_id),
    KEY idx_purchase_orders_supplier_id (supplier_id),
    KEY idx_purchase_orders_material_id (material_id),
    CONSTRAINT fk_purchase_orders_supplier
        FOREIGN KEY (supplier_id)
        REFERENCES suppliers (supplier_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_purchase_orders_material
        FOREIGN KEY (material_id)
        REFERENCES raw_materials (material_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB;

-- =============================================================
-- Sample data: suppliers
-- =============================================================
INSERT INTO suppliers
    (supplier_name, contact_person, phone, email, address)
VALUES
    ('Sweet Supply Hub', 'Aisha Rahman', '+60-12-555-0101', 'aisha@sweetsupply.example', '12 Jalan Pastry, Kuala Lumpur'),
    ('Premium Cocoa Traders', 'Daniel Lim', '+60-13-555-0102', 'daniel@premiumcocoa.example', '88 Cocoa Avenue, Petaling Jaya'),
    ('BakePack Solutions', 'Nurul Hassan', '+60-14-555-0103', 'nurul@bakepack.example', '5 Packaging Street, Shah Alam'),
    ('Fresh Dairy Partners', 'Jason Tan', '+60-16-555-0104', 'jason@freshdairy.example', '21 Creamery Road, Subang Jaya');

-- =============================================================
-- Sample data: raw materials
-- =============================================================
INSERT INTO raw_materials
    (material_name, category, unit, quantity_in_stock, reorder_level, supplier_id)
VALUES
    ('Premium Wheat Flour', 'Baking Ingredient', 'kg', 42.00, 15.00, 1),
    ('Milk Chocolate Couverture', 'Chocolate', 'kg', 18.50, 8.00, 2),
    ('Clear Dessert Jars 250ml', 'Packaging', 'pieces', 120.00, 40.00, 3),
    ('Full Cream Milk', 'Dairy', 'litres', 24.00, 10.00, 4),
    ('Unsalted Butter', 'Dairy', 'kg', 9.00, 5.00, 4);

-- =============================================================
-- Sample data: dessert batches
-- =============================================================
INSERT INTO dessert_batches
    (item_name, category, quantity, production_date, expiry_date, storage_location, status)
VALUES
    ('Classic Choco Jars', 'Choco Jar', 30, '2026-10-07', '2026-10-12', 'Chiller A - Shelf 1', 'Fresh'),
    ('Fudgy Chocolate Brownies', 'Brownie', 24, '2026-10-06', '2026-10-10', 'Display Counter - Tray 2', 'Expiring Soon'),
    ('Milk Chocolate Cake', 'Cake', 8, '2026-10-03', '2026-10-08', 'Chiller B - Shelf 2', 'Expired'),
    ('Lotus Biscoff Choco Jars', 'Choco Jar', 20, '2026-10-07', '2026-10-13', 'Chiller A - Shelf 2', 'Fresh');

-- =============================================================
-- Sample data: wastage logs
-- =============================================================
INSERT INTO wastage_logs
    (batch_id, item_name, quantity_wasted, wastage_reason, logged_date, logged_by)
VALUES
    (3, 'Milk Chocolate Cake', 2, 'Expired', '2026-10-08', 'Farah - Storekeeper'),
    (2, 'Fudgy Chocolate Brownies', 1, 'Handling Damage', '2026-10-07', 'Amir - Counter Staff'),
    (1, 'Classic Choco Jars', 1, 'Quality Defect', '2026-10-08', 'Farah - Storekeeper');

-- =============================================================
-- Sample data: purchase orders
-- =============================================================
INSERT INTO purchase_orders
    (supplier_id, material_id, quantity_ordered, order_status, order_date)
VALUES
    (1, 1, 50.00, 'Received', '2026-10-01'),
    (2, 2, 25.00, 'Pending', '2026-10-08'),
    (3, 3, 200.00, 'Received', '2026-10-02'),
    (4, 4, 40.00, 'Cancelled', '2026-10-05');

-- =============================================================
-- End of script
-- =============================================================

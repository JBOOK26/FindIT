-- Select the intended FindIT database after applying the schema and migrations.
INSERT INTO categories (name, description, is_active) VALUES
    ('Electronics', NULL, 1),
    ('IDs and Cards', NULL, 1),
    ('Bags', NULL, 1),
    ('Books and School Supplies', NULL, 1),
    ('Keys', NULL, 1),
    ('Clothing and Accessories', NULL, 1),
    ('Other', NULL, 1)
ON DUPLICATE KEY UPDATE id = categories.id;

INSERT INTO users 
(first_name, last_name, email, password_hash, role, active)
VALUES
('Admin', 'One', 'admin1@test.com', '$2y$10$Q6a1S.8uk/fT6DgQSoeexeiUhOpw46XxLl6sZcu7NiFyfMb1FjPhq', 'admin', 1),
('Coordinator', 'One', 'coordinator1@test.com', '$2y$10$Q6a1S.8uk/fT6DgQSoeexeiUhOpw46XxLl6sZcu7NiFyfMb1FjPhq', 'coordinator', 1);
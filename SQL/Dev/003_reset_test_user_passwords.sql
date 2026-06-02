UPDATE users
SET password_hash = '$2y$10$PNagDW5hYtK6PJNF3hzCD.34IusG6lR4n1IOrlqi8yusLzsn1ky2O'
WHERE email IN ('admin1@test.com', 'coordinator1@test.com');
# Changelog

Tất cả các thay đổi quan trọng trong dự án sẽ được ghi nhận tại file này.

## [Unreleased] - 2026-09-28

### Performance Improvements (Hiệu năng CSDL & API)
- **perf(db):** Bổ sung migration `2026_09_28_000001_add_core_database_performance_indexes` tạo các composite index chiến lược cho `user_sessions`, `residents`, `invoices`, `tickets`, `amenity_bookings`, `visitor_registrations`, và `visitor_checkin_logs`.
- **perf(query):** Viết lại query sargable cho `ManagementDashboardController` (`where('booking_date', $today)` thay vì `whereDate`) và `ReceptionPortalController` (`whereIn('status', ...)` thay vì `LIKE '%RECEIVED%'`).
- **perf(cache):** Áp dụng mô hình Cache-Aside cho master data (`amenities`, `ticket_categories`) trong `ResidentPortalController` với TTL 600s.
- **perf(config):** Tối ưu cấu hình driver PDO MySQL (`ATTR_EMULATE_PREPARES = false`, `MYSQL_ATTR_USE_BUFFERED_QUERY = true`) và tham số InnoDB MySQL trong `docker-compose.yml` (`buffer-pool-size=256M`, `flush-log-at-trx-commit=2`).
- **docs(perf):** Bổ sung tài liệu phân tích hiệu năng toàn diện `docs/database-performance-analysis.md` và báo cáo kết quả tối ưu `docs/database-performance-optimization-report.md`.

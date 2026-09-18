# HỆ THỐNG QUẢN LÝ TÒA NHÀ & CƯ DÂN - SMART CASSAVAS

> Đề án hệ thống quản lý tòa nhà chung cư thông minh, kết nối cư dân, ban quản lý, lễ tân và admin.  
> Ngăn xếp công nghệ: **Laravel 12 + React 19 (TypeScript) + Vite + Tailwind CSS + MySQL 8.0 (Docker)**.

---

## 🚀 KHỞI ĐỘNG HỆ THỐNG VỚI DOCKER (DUY NHẤT & CHUẨN HÓA)

Hệ thống được đóng gói và vận hành **duy nhất thông qua Docker Compose**. Không cần cài đặt PHP, Composer, Node.js hay MySQL thủ công trên máy tính cá nhân.

### 🌟 Cách 1: Khởi động 1-Click bằng Menu tự động (Khuyên dùng trên Windows)
Chỉ cần **nhấp đúp chuột vào file `start_docker.bat`** tại thư mục gốc của dự án:
- ✅ Tự động kiểm tra Docker Desktop (nếu chưa bật sẽ tự động bật và chờ sẵn sàng).
- ✅ Tự động kiểm tra và cấu hình chuẩn tệp `.env` kết nối MySQL Docker.
- ✅ Hiển thị Menu điều hành trực quan (Khởi động, Build lại, Reset CSDL, Xem logs, Mở web tự động...).

### 💻 Cách 2: Khởi động bằng dòng lệnh (CLI / Terminal)
```bash
# 1. Chuẩn bị tệp môi trường
Copy-Item .env.example .env   # (hoặc: cp .env.example .env)

# 2. Khởi chạy toàn bộ hệ thống bằng Docker Compose
docker compose up -d
```

Quá trình tự động thực hiện:
1. **`smart_cassavas_db`**: Khởi chạy MySQL 8.0, tự động import dữ liệu ban đầu từ `dump_quanly_toanha.sql`.
2. **`smart_cassavas_app`**: Container PHP 8.4 + Node 22 tự động cài đặt Composer/NPM dependencies, build frontend Vite và chạy ứng dụng Laravel.
3. **`smart_cassavas_phpmyadmin`**: Khởi chạy giao diện phpMyAdmin để quản lý cơ sở dữ liệu.

---

## 🌐 ĐỊA CHỈ TRUY CẬP VÀ KẾT NỐI

| Dịch vụ | Địa chỉ | Thông tin đăng nhập |
| :--- | :--- | :--- |
| **Giao diện Web** | [http://localhost:8000](http://localhost:8000) | Trực tiếp trên trình duyệt |
| **phpMyAdmin** | [http://localhost:8888](http://localhost:8888) | Server: `db`, User: `root`, Password: `123567`, Database: `quanly_toanha` |
| **MySQL Database Port** | `localhost:3306` | User: `root`, Password: `123567`, Database: `quanly_toanha` |

---

## 🛠️ CÁC LỆNH ĐIỀU HÀNH VỚI DOCKER

Mọi thao tác phát triển, kiểm thử và bảo trì đều được thực hiện qua Docker:

- **Xem trạng thái các container**:
  ```bash
  docker compose ps
  ```

- **Xem logs ứng dụng realtime**:
  ```bash
  docker compose logs -f app
  ```

- **Xem logs cơ sở dữ liệu**:
  ```bash
  docker compose logs -f db
  ```

- **Chạy lệnh Artisan bên trong container**:
  ```bash
  docker compose exec app php artisan route:list
  docker compose exec app php artisan migrate
  docker compose exec app php artisan test
  ```

- **Chạy kiểm tra code Pint bên trong container**:
  ```bash
  docker compose exec app vendor/bin/pint
  ```

- **Khởi động lại toàn bộ hệ thống**:
  ```bash
  docker compose restart
  ```

- **Dừng hệ thống**:
  ```bash
  docker compose down
  ```

- **Reset sạch sẽ dữ liệu và nạp lại từ đầu**:
  ```bash
  docker compose down -v
  docker compose up -d
  ```

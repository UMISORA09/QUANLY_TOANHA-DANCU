# HỆ THỐNG QUẢN LÝ TÒA NHÀ & CƯ DÂN - SMART CASSAVAS

> Đề án hệ thống quản lý tòa nhà chung cư thông minh, kết nối cư dân, ban quản lý, lễ tân và admin.  
> Ngăn xếp công nghệ: **Laravel 12 + React 19 (TypeScript) + Vite + Tailwind CSS + Microsoft SQL Server (Docker)**.

---

## 🚀 HƯỚNG DẪN CHẠY ĐỒ ÁN VỚI DOCKER (1-CLICK DÀNH CHO NHÓM & GIẢNG VIÊN)

Hệ thống đã được đóng gói toàn diện bằng **Docker Compose**. Khi chuyển sang máy tính khác hoặc clone từ GitHub về, bạn **không cần** cài đặt PHP, Composer hay Microsoft SQL Server thủ công. File cơ sở dữ liệu `CSDL_CHUNGCU&DANCU.sql` (với hơn 100 bảng và dữ liệu mẫu) sẽ được **tự động nạp vào database** ngay khi khởi động.

### Bước 1: Clone dự án từ GitHub
```bash
git clone https://github.com/UMISORA09/QUANLY_TOANHA-DANCU.git
cd QUANLY_TOANHA-DANCU
```

### Bước 2: Tạo tệp môi trường
```bash
# Trên Windows PowerShell:
Copy-Item .env.example .env

# Trên Linux / macOS / Git Bash:
cp .env.example .env
```

### Bước 3: Khởi động hệ thống bằng Docker
```bash
docker compose up -d
```

Quá trình tự động diễn ra:
1. Container `smart_cassavas_db` khởi chạy Microsoft SQL Server 2022.
2. Container `smart_cassavas_db_init` tự động thực thi tệp `CSDL_CHUNGCU&DANCU.sql` để tạo database `[CSDL_CHUNGCU&DANCU]` và toàn bộ các bảng, views, dữ liệu ban đầu.
3. Container `smart_cassavas_app` cài đặt dependencies, biên dịch assets và khởi chạy ứng dụng web.

### Bước 4: Mở ứng dụng
- **Giao diện Web**: [http://localhost:8000](http://localhost:8000)
- **Cổng kết nối CSDL**: `localhost:1433`

---

## 🗄️ THÔNG TIN KẾT NỐI CƠ SỞ DỮ LIỆU (SSMS / DBeaver / Azure Data Studio)

Các thành viên có thể kết nối trực tiếp vào SQL Server trên máy để xem ERD, truy vấn hoặc kiểm tra bảng:

| Thông số | Giá trị kết nối |
| :--- | :--- |
| **DBMS** | Microsoft SQL Server 2022 |
| **Server / Host** | `localhost,1433` hoặc `127.0.0.1,1433` |
| **Database** | `CSDL_CHUNGCU&DANCU` |
| **Authentication** | SQL Server Authentication |
| **Username (`User ID`)** | `sa` |
| **Password** | `SmartCassavas@2026` |
| **Encrypt / Trust Certificate** | Trust Server Certificate: `True` (Encrypt: `Optional/No`) |

---

## 🛠️ CÁC LỆNH HỮU ÍCH KHI SỬ DỤNG DOCKER

- **Xem trạng thái các container**:
  ```bash
  docker compose ps
  ```
- **Xem logs của ứng dụng hoặc database**:
  ```bash
  docker compose logs -f app
  docker compose logs -f db-init
  ```
- **Khởi động lại toàn bộ hệ thống**:
  ```bash
  docker compose restart
  ```
- **Dừng hệ thống**:
  ```bash
  docker compose down
  ```
- **Dừng hệ thống và xóa sạch dữ liệu để nạp lại từ đầu**:
  ```bash
  docker compose down -v
  docker compose up -d
  ```

---

## 💻 CHẠY CỤC BỘ & QUẢN TRỊ DATABASE (PHPMYADMIN / MIGRATIONS)

### 1. Quản lý cơ sở dữ liệu qua Migrations
Hệ thống hỗ trợ cơ chế nạp toàn bộ 109 bảng và 6 views tự động tương thích đa nền tảng (MySQL, SQLite, SQL Server):
```bash
php artisan migrate:fresh
```

### 2. Quản trị trực quan qua phpMyAdmin
- **URL phpMyAdmin**: [http://localhost:8888](http://localhost:8888)
- **Cấu hình kết nối**:
  - Server / Host: `host.docker.internal` (hoặc `localhost`)
  - Username: `root`
  - Password: `123567`
  - Database: `quanly_toanha`

### 3. Chạy môi trường phát triển cục bộ
Nếu bạn phát triển trực tiếp trên máy:
1. Cài đặt thư viện: `composer install` và `npm install`
2. Cấu hình `.env` (MySQL Docker hoặc SQLite)
3. Chạy migration: `php artisan migrate:fresh`
4. Khởi chạy:
   - Terminal 1: `php artisan serve`
   - Terminal 2: `npm run dev`

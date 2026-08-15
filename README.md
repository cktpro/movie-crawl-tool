# roxone/movie-nguonc-crawler

Plugin crawler phim cho [Movie CMS](https://github.com/cktpro/movie-core). Lấy dữ liệu từ nguồn định dạng **ophim** và định dạng **nguonc**, kèm bộ công cụ quản lý ảnh đã crawl về.

> Tên repo là `movie-crawl-tool`, còn tên package trên Packagist là `roxone/movie-nguonc-crawler`.

## Demo
### Trang Crawl
![Alt text](https://i.ibb.co/WPy9Hp7/CRAWLER-INDEX.png "Crawler Page")

### Trang Cấu Hình
![Alt text](https://i.ibb.co/zmDYwRd/CRAWLER-OPTION.png "Options Page")

### Cấu Hình Tự Động
![Alt text](https://i.ibb.co/5jY3s2P/CRAWLER-SCHEDULE.png "Options Page")

## Yêu cầu

- [roxone/movie-core](https://github.com/cktpro/movie-core) (`dev-main`)
- `intervention/image` ^2.7 — kéo theo tự động
- PHP có `gd` (hoặc Imagick) **hỗ trợ WebP** nếu muốn dùng chức năng chuyển ảnh sang WebP

## Cài đặt

```bash
composer require roxone/movie-nguonc-crawler
php artisan optimize:clear
```

## Cập nhật

```bash
composer update roxone/movie-nguonc-crawler
php artisan optimize:clear
```

## Các trang trong admin

| Trang | Đường dẫn | Việc |
|---|---|---|
| Crawler | `/admin/plugin/movie-crawler` | crawl nguồn định dạng ophim |
| Crawler Nguonc | `/admin/plugin/nguonc-crawler` | crawl nguồn định dạng nguonc |
| Option | `/admin/plugin/movie-crawler/options` | cấu hình: nguồn, ảnh, R2, lịch chạy |
| Chuyển ảnh lên R2 | `/admin/plugin/movie-crawler/images-r2` | đưa ảnh của phim hiện có lên Cloudflare R2 |
| Chuyển ảnh sang WebP | `/admin/plugin/movie-crawler/images-webp` | encode ảnh của phim hiện có sang WebP |
| Dọn ảnh rác | `/admin/plugin/movie-crawler/images-cleanup` | xoá thư mục ảnh của phim đã bị xoá / đổi slug |

## Lệnh

```bash
# Chạy thủ công một lượt crawl theo cấu hình đang lưu
php artisan movie:plugins:movie-crawler:schedule

# Đưa ảnh lên R2 (bỏ --run để chỉ liệt kê)
php artisan movie:plugins:movie-crawler:images-to-r2 --run [--limit=N] [--force]

# Chuyển ảnh sang WebP
php artisan movie:plugins:movie-crawler:images-to-webp --run [--limit=N] [--delete-original] [--with-external] [--force]

# Dọn ảnh mồ côi (mặc định dry-run)
php artisan movie:plugins:movie-crawler:cleanup-images
```

## Lưu ảnh

Option **Nơi lưu ảnh** chọn giữa `local` (`storage/app/public`) và `r2` (Cloudflare R2 qua S3 driver). Cấu hình R2 lấy từ biến `R2_*` trong `.env`, bị ghi đè bởi các ô ở tab *Cloudflare R2* trong Options.

Đổi disk hoặc đổi định dạng **không** tự di chuyển ảnh cũ — đó là việc của hai trang *Chuyển ảnh lên R2* và *Chuyển ảnh sang WebP*. Cả hai chạy theo lô, chạy lại được nhiều lần (ảnh đã xử lý tự bị bỏ qua), và đổi link trong DB bằng query builder để không chạm `updated_at` — nếu chạm, mọi phim sẽ trông như vừa cập nhật và làm hỏng thứ tự "phim mới cập nhật" ngoài trang chủ.

Chuyển sang WebP mặc định **giữ nguyên file gốc**; muốn xoá thì tích ô trên form hoặc thêm `--delete-original`.

## Cron

```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

Bật/tắt và đặt biểu thức cron của crawler ở Options › tab *Schedule*.

## Ghi chú

- Cấu hình lưu trong bảng `settings`, khoá `hacoidev/movie-crawler.options`
- Log: `storage/logs/hacoidev/movie-crawler.log` (daily, giữ 7 ngày)
- Việc crawl chạy trực tiếp qua HTTP từ trang admin, không dùng queue — gom quá nhiều trang vào một lượt dễ chạm timeout của PHP/web server
- Tính idempotent dựa vào `movies.update_handler` + `update_identity` + `update_checksum`; không đổi thì bỏ qua, trừ khi bật *Force update*
- Hai định dạng nguồn được cài đặt thành các cặp method song song (`handle()`/`handle_nguonc()`, `get()`/`get_nguonc()`...), nên sửa một định dạng thường phải sửa đối xứng ở định dạng còn lại

## Changelog

### 2026-08
- Thêm trang + lệnh **chuyển ảnh sang WebP** cho ảnh của phim đã có
- Thêm trang + lệnh **chuyển ảnh lên Cloudflare R2**
- Thêm trang + lệnh **dọn ảnh mồ côi**
- Vá `Option::get()` query DB mỗi lần gọi — với site vài chục nghìn phim, trang quản lý ảnh vượt `max_execution_time` nên không mở được
- Vá 6 lỗi ở luồng crawl định dạng ophim; vá danh sách thể loại/quốc gia rỗng ở 2 trang crawler
- Tách nút "Chọn hết"/"Bỏ hết" và tách khoá localStorage của 2 trang crawler

### 1.1.0
- Cập nhật crawler schedule

### 1.0.3
- Sửa logic lưu field khi crawl

### 1.0.2
- Bật kiểm tra `hasChange`

### 1.0.1
- Sửa đồng bộ tập phim

### 23/09/2022
- Ghi nhớ fields crawl + download images
- Fix crawl pages hạn chế timeout khi nhiều page

### 22/09/2022
- Thêm lọc bỏ qua theo định dạng
- Tạo thể loại đối với định dạng là `hoạt hình` và `tv shows`

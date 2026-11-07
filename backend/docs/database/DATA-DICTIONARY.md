# TAPZY — Data Dictionary (Kamus Data) — v1.5 Final

Dokumen ini memuat spesifikasi teknis lengkap untuk seluruh tabel database platform SaaS **TAPZY** (NFC Smart Queue & Ordering Platform), diselaraskan secara penuh dengan **PRD v1.0**, **Keputusan Bisnis Final C1-C5**, serta hasil finalisasi audit v1.5.

---

## 1. Tabel: `users`
**Tujuan Bisnis:** Menyimpan akun pengelola sistem (`super_admin`) dan pemilik/staf toko (`merchant`).

> **Catatan Audit Kolom `is_active` (PRD 9.1 & Keputusan C3):**
> Sesuai PRD 9.1, entitas `users` **tidak memiliki kolom `is_active`**. Akun merchant dinonaktifkan dengan **Soft Delete (`users.deleted_at`)**. Semua user soft-deleted, termasuk super admin, ditolak untuk autentikasi dashboard. Status operasional toko diatur terpisah pada `stores.is_active`.

| Nama Kolom | Tipe Data MySQL | Nullable | Default | Constraint / Index | Keterangan & Asal Migration |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `BIGINT UNSIGNED` | `NO` | *AUTO_INCREMENT* | `PK` | ID unik pengguna *(Bawaan)* |
| `name` | `VARCHAR(150)` | `NO` | - | - | Nama lengkap pengguna *(Bawaan)* |
| `email` | `VARCHAR(255)` | `NO` | - | `UNIQUE` | Email login pengguna *(Bawaan)* |
| `email_verified_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu verifikasi email *(Bawaan)* |
| `password` | `VARCHAR(255)` | `NO` | - | - | Hash kata sandi Bcrypt *(Bawaan)* |
| `role` | `ENUM('super_admin', 'merchant')` | `NO` | `'merchant'` | `INDEX(role)` | Peran otorisasi *(Migration Tambahan)* |
| `store_id` | `BIGINT UNSIGNED` | `YES` | `NULL` | `FK`, `INDEX` | Relasi toko (`NULL` untuk super_admin) *(Migration Tambahan)* |
| `remember_token` | `VARCHAR(100)` | `YES` | `NULL` | - | Token autentikasi web *(Bawaan)* |
| `created_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu pembuatan akun *(Bawaan)* |
| `updated_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu pembaruan akun *(Bawaan)* |
| `deleted_at` | `TIMESTAMP` | `YES` | `NULL` | `INDEX` | Timestamp soft delete akun *(Migration Tambahan)* |

- **Foreign Key:** `store_id` REFERENCES `stores(id)` ON DELETE RESTRICT ON UPDATE CASCADE.
- **Catatan Teknis RESTRICT:** Kolom `store_id` bersifat `NULLABLE`. Untuk `super_admin`, nilai `NULL` diizinkan sepenuhnya oleh MySQL. Namun untuk akun `merchant` yang memiliki nilai `store_id = X`, toko X tidak dapat di-hard delete secara fisik selama akun merchant masih ada.
- **Kebijakan Soft Delete dan autentikasi:** Semua user dengan `deleted_at IS NOT NULL` ditolak saat login dan pada setiap request dashboard terproteksi. Dashboard menggunakan Sanctum SPA session cookie, bukan Personal Access Token. Data historis akun, toko, dan transaksi tetap dipertahankan; akses super admin terhadap data tersebut harus mengikuti otorisasi endpoint yang berlaku.

---

## 2. Tabel: `stores`
**Tujuan Bisnis:** Entitas tenant utama yang mewakili outlet atau toko merchant. Menjadi batas partisi logis seluruh data operasional (PRD 9.2).

| Nama Kolom | Tipe Data MySQL | Nullable | Default | Constraint / Index | Keterangan & Pemetaan PRD |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `BIGINT UNSIGNED` | `NO` | *AUTO_INCREMENT* | `PK` | ID unik toko (`id`) |
| `name` | `VARCHAR(150)` | `NO` | - | - | Nama resmi toko / outlet (`nama_toko`) |
| `slug` | `VARCHAR(150)` | `NO` | - | `UNIQUE` | Identifier URL ramah SEO & QR code (`slug`) |
| `description` | `TEXT` | `YES` | `NULL` | - | Deskripsi profil toko (`deskripsi`) |
| `phone` | `VARCHAR(30)` | `YES` | `NULL` | - | Nomor kontak/WhatsApp toko |
| `address` | `TEXT` | `YES` | `NULL` | - | Alamat fisik lokasi toko (`alamat`) |
| `google_maps_url` | `VARCHAR(255)` | `YES` | `NULL` | - | URL tautan Google Maps (`google_maps_url`) |
| `avg_service_time`| `INT UNSIGNED` | `NO` | `10` | - | Rata-rata waktu layanan dalam menit (`rata_rata_waktu_layanan`) |
| `queue_prefix` | `VARCHAR(5)` | `NO` | `'A'` | - | Prefix format nomor antrean (`prefix_antrean`) |
| `ordering_mode` | `ENUM('queue_only', 'queue_and_order')` | `NO` | `'queue_and_order'` | - | Mode pemesanan toko (`mode_pemesanan`, PRD 4.1.3/4.1.4) |
| `is_active` | `TINYINT(1)` | `NO` | `1` | `INDEX(is_active)`| Status operasional toko (1=aktif, 0=nonaktif) (`status`) |
| `created_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu pendaftaran toko |
| `updated_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu pembaruan data toko |
| `deleted_at` | `TIMESTAMP` | `YES` | `NULL` | `INDEX` | Soft delete timestamp |

---

## 3. Tabel: `nfc_cards`
**Tujuan Bisnis:** Mengelola tag/kartu NFC fisik (dan QR cadangan) di meja, kios antrean, atau konter (PRD 9.3). Token publik disimpan dalam bentuk hash SHA-256 demi keamanan database dump.

| Nama Kolom | Tipe Data MySQL | Nullable | Default | Constraint / Index | Keterangan & Pemetaan PRD |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `BIGINT UNSIGNED` | `NO` | *AUTO_INCREMENT* | `PK` | ID unik kartu NFC |
| `store_id` | `BIGINT UNSIGNED` | `NO` | - | `FK`, `INDEX` | Relasi toko pemilik kartu |
| `card_uid` | `VARCHAR(100)` | `NO` | - | `INDEX` | Serial hardware chip fisik NFC |
| `public_token_hash`| `CHAR(64)` | `NO` | - | `UNIQUE` | Hash SHA-256 dari token URL NDEF publik (`token`) |
| `label` | `VARCHAR(100)` | `NO` | - | - | Label lokasi (contoh: "Meja 01", "Kios Antrean") |
| `type` | `ENUM('table', 'queue_kiosk', 'counter')` | `NO` | `'table'` | `INDEX(type)` | Tipe peruntukan titik akses NFC |
| `is_active` | `TINYINT(1)` | `NO` | `1` | `INDEX` | Status aktif kartu (`status`) |
| `last_used_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu terakhir kali kartu di-tap |
| `activated_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu kartu diaktifkan (`activated_at`) |
| `deactivated_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu kartu dinonaktifkan (`deactivated_at`) |
| `created_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu kartu didaftarkan |
| `updated_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu pembaruan kartu |
| `deleted_at` | `TIMESTAMP` | `YES` | `NULL` | `INDEX` | Soft delete timestamp |

- **Foreign Key:** `store_id` REFERENCES `stores(id)` ON DELETE RESTRICT ON UPDATE CASCADE *(Melindungi data aset kartu dari penghapusan fisik toko)*.
- **Unique Constraint:** `UNIQUE KEY uq_store_card_uid (store_id, card_uid)`.
- **Index:** `INDEX idx_store_card_status (store_id, is_active)`.

---

## 4. Tabel: `queue_sessions`
**Tujuan Bisnis:** Siklus operasional antrean harian/shift per toko (PRD 9.4).

| Nama Kolom | Tipe Data MySQL | Nullable | Default | Constraint / Index | Keterangan & Pemetaan PRD |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `BIGINT UNSIGNED` | `NO` | *AUTO_INCREMENT* | `PK` | ID unik sesi antrean |
| `store_id` | `BIGINT UNSIGNED` | `NO` | - | `FK`, `INDEX` | Relasi toko penyelenggara antrean |
| `session_code` | `VARCHAR(50)` | `NO` | - | - | Kode unik sesi per toko (contoh: `QS-20261105-001`) |
| `session_date` | `DATE` | `NO` | - | `INDEX` | Tanggal kalender sesi antrean (`tanggal`) |
| `last_queue_number` | `INT UNSIGNED` | `NO` | `0` | - | Counter nomor antrean terakhir (`nomor_terakhir`) |
| `status` | `ENUM('active', 'closed')` | `NO` | `'active'` | `INDEX(status)` | Status sesi antrean (`status`) |
| `active_store_id` | `BIGINT UNSIGNED` | `YES` | *GENERATED* | `UNIQUE` | Kolom virtual: `IF(status = 'active', store_id, NULL)` |
| `opened_at` | `TIMESTAMP` | `NO` | `CURRENT_TIMESTAMP`| - | Waktu sesi dibuka (`started_at`) |
| `closed_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu sesi ditutup (`ended_at`) |
| `created_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu rekaman dibuat |
| `updated_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu pembaruan data sesi |

- **Foreign Key:** `store_id` REFERENCES `stores(id)` ON DELETE RESTRICT ON UPDATE CASCADE *(Melindungi riwayat sesi operasional dari hard delete toko)*.
- **Definisi Virtual Column (MySQL 8):**
  ```sql
  `active_store_id` BIGINT UNSIGNED GENERATED ALWAYS AS (IF(`status` = 'active', `store_id`, NULL)) VIRTUAL
  ```
- **Proteksi Database Sesi Ganda & Kode Sesi:**
  - `UNIQUE KEY uq_store_single_active_session (active_store_id)` (Maksimal 1 sesi aktif per toko).
  - `UNIQUE KEY uq_store_session_code (store_id, session_code)` (Mencegah duplikasi kode sesi dalam toko yang sama).

---

## 5. Tabel: `queues`
**Tujuan Bisnis:** Menyimpan tiket nomor antrean pelanggan dalam suatu sesi operasional toko (PRD 9.5).

| Nama Kolom | Tipe Data MySQL | Nullable | Default | Constraint / Index | Keterangan & Pemetaan PRD |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `BIGINT UNSIGNED` | `NO` | *AUTO_INCREMENT* | `PK` | ID unik antrean |
| `store_id` | `BIGINT UNSIGNED` | `NO` | - | `FK`, `INDEX` | Relasi toko penyedia antrean |
| `queue_session_id` | `BIGINT UNSIGNED` | `NO` | - | `FK`, `INDEX` | Relasi sesi antrean aktif |
| `nfc_card_id` | `BIGINT UNSIGNED` | `YES` | `NULL` | `FK`, `INDEX` | Kartu NFC sumber tap (opsional) |
| `queue_number` | `INT UNSIGNED` | `NO` | - | - | Nomor urut integer (1, 2, 3...) (`nomor_antrean`) |
| `formatted_number` | `VARCHAR(20)` | `NO` | - | - | Tampilan format nomor (contoh: "A-001") |
| `customer_name` | `VARCHAR(100)` | `YES` | `NULL` | - | Nama panggilan pelanggan (`nama_pelanggan`) |
| `customer_phone` | `VARCHAR(30)` | `YES` | `NULL` | - | Nomor WhatsApp pelanggan (`nomor_whatsapp`) |
| `source` | `ENUM('nfc', 'barcode', 'manual')` | `NO` | `'nfc'` | `INDEX(source)` | Sumber pengambilan tiket (`sumber_antrean`, PRD 9.5) |
| `access_token_hash`| `CHAR(64)` | `NO` | - | `UNIQUE` | Hash SHA-256 token pelacakan (`access_token`) |
| `status` | `ENUM('waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled')` | `NO` | `'waiting'` | `INDEX(status)` | Status progres antrean (`status`, PRD 5.3) |
| `called_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu nomor antrean dipanggil (`called_at`) |
| `served_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu mulai dilayani oleh petugas |
| `completed_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu pelayanan selesai (`completed_at`) |
| `cancelled_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu antrean dibatalkan (`cancelled_at`) |
| `notes` | `TEXT` | `YES` | `NULL` | - | Catatan khusus antrean |
| `created_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu tiket dibuat |
| `updated_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu perubahan data |

- **Foreign Keys:**
  - `store_id` REFERENCES `stores(id)` ON DELETE RESTRICT ON UPDATE CASCADE *(Riwayat antrean tidak boleh terhapus jika toko di-hard delete)*.
  - `queue_session_id` REFERENCES `queue_sessions(id)` ON DELETE RESTRICT ON UPDATE CASCADE *(Antrean tidak boleh terhapus jika sesi di-hard delete)*.
  - `nfc_card_id` REFERENCES `nfc_cards(id)` ON DELETE SET NULL ON UPDATE CASCADE *(Jika kartu fisik dinonaktifkan/dihapus, nomor tiket antrean tetap utuh)*.
- **Aturan Kombinasi Kolom `source` dan `nfc_card_id`:**
  - `source = 'nfc'`: Pelanggan tap chip NFC meja/kios ➔ `nfc_card_id` **wajib terisi** (NOT NULL).
  - `source = 'barcode'`: Pelanggan scan QR code pada standee meja ➔ `nfc_card_id` **terisi**; jika toko memasang banner QR umum di pintu masuk toko, `nfc_card_id` bernilai `NULL`.
  - `source = 'manual'`: Kasir menambahkan antrean via tombol *"Tambah Antrean Manual"* (PRD 4.2.13) ➔ `nfc_card_id` **bernilai `NULL`**.
- **Unique Constraints:**
  - `UNIQUE KEY uq_session_queue_num (queue_session_id, queue_number)` (Mencegah duplikasi nomor antrean dalam 1 sesi).
  - `UNIQUE KEY uq_queue_access_token_hash (access_token_hash)`.
- **Index:** `INDEX idx_store_queue_status_time (store_id, status, created_at)`.

---

## 6. Tabel: `products`
**Tujuan Bisnis:** Katalog menu makanan, minuman, atau layanan yang dijual oleh toko merchant (PRD 9.6).

| Nama Kolom | Tipe Data MySQL | Nullable | Default | Constraint / Index | Keterangan & Pemetaan PRD |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `BIGINT UNSIGNED` | `NO` | *AUTO_INCREMENT* | `PK` | ID unik produk |
| `store_id` | `BIGINT UNSIGNED` | `NO` | - | `FK`, `INDEX` | Relasi toko pemilik menu |
| `name` | `VARCHAR(150)` | `NO` | - | - | Nama produk / menu (`nama_produk`) |
| `description` | `TEXT` | `YES` | `NULL` | - | Deskripsi produk dan komposisi (`deskripsi`) |
| `price` | `DECIMAL(12, 2)` | `NO` | `0.00` | - | Harga jual satuan saat ini (Rp) (`harga`) |
| `category` | `VARCHAR(100)` | `YES` | `NULL` | `INDEX` | Kategori produk |
| `image_url` | `VARCHAR(255)` | `YES` | `NULL` | - | URL foto produk (`gambar`) |
| `is_available` | `TINYINT(1)` | `NO` | `1` | `INDEX` | Ketersediaan stok (1=ada, 0=habis) |
| `is_active` | `TINYINT(1)` | `NO` | `1` | `INDEX` | Visibilitas menu (1=tampil, 0=sembunyi) (`status_aktif`) |
| `created_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu produk ditambahkan |
| `updated_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu pembaruan produk |
| `deleted_at` | `TIMESTAMP` | `YES` | `NULL` | `INDEX` | Soft delete timestamp |

- **Foreign Key:** `store_id` REFERENCES `stores(id)` ON DELETE RESTRICT ON UPDATE CASCADE *(Melindungi integritas katalog dari hard delete toko)*.
- **Index:** `INDEX idx_store_product_catalog (store_id, is_active, is_available)`.

---

## 7. Tabel: `orders`
**Tujuan Bisnis:** Menyimpan transaksi pemesanan menu (PRD 9.7).

| Nama Kolom | Tipe Data MySQL | Nullable | Default | Constraint / Index | Keterangan & Penyelarasan PRD / Keputusan Final |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `BIGINT UNSIGNED` | `NO` | *AUTO_INCREMENT* | `PK` | ID unik transaksi pesanan |
| `order_code` | `VARCHAR(50)` | `NO` | - | `UNIQUE` | Nomor struk pesanan unik (`nomor_pesanan`) |
| `store_id` | `BIGINT UNSIGNED` | `NO` | - | `FK`, `INDEX` | Relasi toko tempat transaksi berlangsung |
| `queue_id` | `BIGINT UNSIGNED` | `YES` | `NULL` | `FK`, `UNIQUE` | Relasi antrean. **Maksimal 1 order per antrean (C1)** |
| `nfc_card_id` | `BIGINT UNSIGNED` | `YES` | `NULL` | `FK`, `INDEX` | Relasi kartu NFC / meja tempat pemesanan (opsional) |
| `customer_name` | `VARCHAR(100)` | `YES` | `NULL` | - | Nama pemesan |
| `total_items` | `INT UNSIGNED` | `NO` | `0` | - | Jumlah total kuantitas item dipesan |
| `subtotal` | `DECIMAL(12, 2)` | `NO` | `0.00` | - | Total harga barang sebelum pajak & diskon (`subtotal`) |
| `tax_amount` | `DECIMAL(12, 2)` | `NO` | `0.00` | - | Nominal pajak (opsional, default 0.00) |
| `discount_amount` | `DECIMAL(12, 2)` | `NO` | `0.00` | - | Nominal potongan promo (opsional, default 0.00) |
| `total_price` | `DECIMAL(12, 2)` | `NO` | `0.00` | - | Total tagihan akhir (`total`) |
| `status` | `ENUM('pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled')` | `NO` | `'pending'` | `INDEX(status)` | Status progres pemrosesan pesanan (`status`) |
| `completed_at` | `TIMESTAMP` | `YES` | `NULL` | `INDEX` | Waktu pesanan diserahkan (`completed_at`, PRD 9.7) |
| `notes` | `TEXT` | `YES` | `NULL` | - | Catatan khusus pesanan |
| `created_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu pesanan dibuat |
| `updated_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu perubahan pesanan |
| `deleted_at` | `TIMESTAMP` | `YES` | `NULL` | `INDEX` | Soft delete timestamp |

- **Foreign Keys:**
  - `store_id` REFERENCES `stores(id)` ON DELETE RESTRICT ON UPDATE CASCADE *(Data transaksi dilindungi secara mutlak dari penghapusan toko)*.
  - `queue_id` REFERENCES `queues(id)` ON DELETE RESTRICT ON UPDATE CASCADE *(Perlindungan Audit: Antrean yang memiliki pesanan tidak boleh di-hard delete secara fisik agar tautan historis tidak terputus)*.
  - `nfc_card_id` REFERENCES `nfc_cards(id)` ON DELETE SET NULL ON UPDATE CASCADE.
- **Unique Constraint Relasi Antrean (Keputusan C1):**
  ```sql
  UNIQUE KEY `uq_orders_queue_id` (`queue_id`)
  ```
- **Keterangan Pembayaran Eksternal & Pendapatan (Keputusan C2 & PRD 5.5):**
  - Kolom `payment_status`, `payment_method`, dan `paid_at` **DIHAPUS DARI RANCANGAN** karena TAPZY MVP tidak memproses transaksi keuangan langsung dan tidak memiliki payment gateway.
  - Sesuai PRD 5.5, pendapatan toko dihitung berdasarkan total nominal dari order dengan **`status = 'completed'`** (penyelesaian layanan fisik di outlet), bukan dari verifikasi transfer perbankan.
- **Index:** `INDEX idx_store_order_search (store_id, status, created_at)`.

---

## 8. Tabel: `order_items`
**Tujuan Bisnis:** Baris rincian item pesanan dengan **snapshot harga dan nama produk** saat transaksi terjadi (PRD 9.8).

| Nama Kolom | Tipe Data MySQL | Nullable | Default | Constraint / Index | Keterangan & Pemetaan PRD |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `BIGINT UNSIGNED` | `NO` | *AUTO_INCREMENT* | `PK` | ID unik baris item |
| `order_id` | `BIGINT UNSIGNED` | `NO` | - | `FK`, `INDEX` | Relasi pesanan induk |
| `product_id` | `BIGINT UNSIGNED` | `YES` | `NULL` | `FK`, `INDEX` | Relasi produk master (bisa NULL jika produk dihapus) |
| `product_name_snapshot`| `VARCHAR(150)`| `NO` | - | - | Snapshot nama produk saat dipesan (`nama_produk_snapshot`) |
| `unit_price_snapshot` | `DECIMAL(12, 2)`| `NO` | `0.00` | - | Snapshot harga satuan saat dipesan (Rp) (`harga_snapshot`) |
| `quantity` | `INT UNSIGNED` | `NO` | `1` | - | Kuantitas item yang dipesan (`jumlah`) |
| `subtotal` | `DECIMAL(12, 2)` | `NO` | `0.00` | - | Hasil perkalian `quantity * unit_price_snapshot` (`subtotal`) |
| `notes` | `VARCHAR(255)` | `YES` | `NULL` | - | Catatan varian item (`catatan`) |
| `created_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu item dibuat |
| `updated_at` | `TIMESTAMP` | `YES` | `NULL` | - | Waktu pembaruan item |

- **Foreign Keys:**
  - `order_id` REFERENCES `orders(id)` ON DELETE RESTRICT ON UPDATE CASCADE *(Perlindungan Finansial: Mencegah penghapusan pesanan secara fisik menghapus rincian item transaksinya)*.
  - `product_id` REFERENCES `products(id)` ON DELETE SET NULL ON UPDATE CASCADE *(Jika produk master dihapus, data detail transaksi tetap utuh berkat snapshot)*.

---

## 9. Tabel: `queue_status_histories`
**Tujuan Bisnis:** Log audit transisi status tiket antrean untuk pengukuran waktu tunggu dan durasi pelayanan (PRD 9.9).

| Nama Kolom | Tipe Data MySQL | Nullable | Default | Constraint / Index | Keterangan & Pemetaan PRD |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `BIGINT UNSIGNED` | `NO` | *AUTO_INCREMENT* | `PK` | ID unik histori status |
| `queue_id` | `BIGINT UNSIGNED` | `NO` | - | `FK`, `INDEX` | Relasi tiket antrean yang berubah |
| `previous_status` | `ENUM('waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled')` | `YES` | `NULL` | - | Status tiket sebelumnya (`status_sebelumnya`) |
| `new_status` | `ENUM('waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled')` | `NO` | - | `INDEX` | Status tiket yang baru (`status_baru`) |
| `changed_by_user_id`| `BIGINT UNSIGNED`| `YES` | `NULL` | `FK`, `INDEX` | Petugas pengubah status (`changed_by`) |
| `notes` | `VARCHAR(255)` | `YES` | `NULL` | - | Catatan alasan perubahan status (`keterangan`) |
| `created_at` | `TIMESTAMP` | `NO` | `CURRENT_TIMESTAMP` | `INDEX` | Waktu transisi status (immutable) |

- **Foreign Keys:**
  - `queue_id` REFERENCES `queues(id)` ON DELETE RESTRICT ON UPDATE CASCADE *(Riwayat transisi status tidak boleh dihapus jika antrean di-hard delete)*.
  - `changed_by_user_id` REFERENCES `users(id)` ON DELETE SET NULL ON UPDATE CASCADE *(Jika akun petugas dihapus, riwayat audit status tetap aman)*.

---

## 10. Tabel: `audit_logs`
**Tujuan Bisnis:** Jejak rekam aktivitas administratif dan perubahan data penting (PRD 9.10).

> **Status Kebijakan Retensi (Keputusan C5):**
> **"Audit log tidak dihapus secara otomatis. Data dipertahankan sampai kebijakan retensi ditetapkan melalui keputusan bisnis dan kepatuhan. Masa penyimpanan akhir masih belum diputuskan."**

| Nama Kolom | Tipe Data MySQL | Nullable | Default | Constraint / Index | Keterangan & Pemetaan PRD |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `BIGINT UNSIGNED` | `NO` | *AUTO_INCREMENT* | `PK` | ID unik log audit |
| `store_id` | `BIGINT UNSIGNED` | `YES` | `NULL` | `FK`, `INDEX` | Relasi toko terdampak (`NULL` jika toko dihapus/global) |
| `user_id` | `BIGINT UNSIGNED` | `YES` | `NULL` | `FK`, `INDEX` | Pengguna pelaku aksi (`NULL` jika user dihapus/sistem) |
| `action` | `VARCHAR(100)` | `NO` | - | `INDEX` | Nama aksi (contoh: `queue.reset`, `order.cancel`) (`aktivitas`) |
| `entity_type` | `VARCHAR(100)` | `YES` | `NULL` | - | Class model terdampak |
| `entity_id` | `BIGINT UNSIGNED` | `YES` | `NULL` | `INDEX` | Primary key entitas terdampak |
| `old_values` | `JSON` | `YES` | `NULL` | - | Nilai sebelum perubahan |
| `new_values` | `JSON` | `YES` | `NULL` | - | Nilai sesudah perubahan (`detail`) |
| `ip_address` | `VARCHAR(45)` | `YES` | `NULL` | - | Alamat IP pemanggil (IPv4 / IPv6) |
| `user_agent` | `TEXT` | `YES` | `NULL` | - | User-Agent browser / client |
| `created_at` | `TIMESTAMP` | `NO` | `CURRENT_TIMESTAMP` | `INDEX` | Waktu log dicatat (immutable, append-only) |

- **Foreign Keys:**
  - `store_id` REFERENCES `stores(id)` ON DELETE SET NULL ON UPDATE CASCADE.
  - `user_id` REFERENCES `users(id)` ON DELETE SET NULL ON UPDATE CASCADE.
- **Catatan Keamanan & Privasi Data:**
  - Nilai pada `old_values` dan `new_values` dapat tetap berisi snapshot informasi historis. Penggunaan `ON DELETE SET NULL` pada foreign key menjaga kueri log tidak rusak (*orphan-safe*), namun tidak otomatis menyamarkan data pribadi di dalam payload JSON.

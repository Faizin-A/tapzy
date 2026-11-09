# TAPZY — Business Rules & Architecture Constraints — v1.7 Final

Dokumen ini mendefinisikan aturan bisnis resmi, logika keamanan multi-tenant, siklus antrean, strategi concurrency, tata kelola token kriptografis, dan evaluasi integritas basis data untuk platform SaaS **TAPZY** (NFC Smart Queue & Ordering Platform). Dokumen ini diselaraskan secara penuh dengan **PRD v1.0**, **Keputusan Bisnis Final C1–C5**, dan **Hasil Audit Konsistensi v1.7**.

---

## 1. Aturan Multi-Tenancy & Batas Isolasi Data

### 1.1 Batas Partisi Tenant (Store-Based Scoping)
1. **Single Database Multi-Tenant:** Seluruh tenant berbagi satu basis data MySQL pusat.
2. **Tenant Boundary:** Tabel `stores` berfungsi sebagai batas isolasi seluruh data operasional (`nfc_cards`, `queue_sessions`, `queues`, `products`, `orders`).
3. **Pemberian Identitas Toko Server-Side:**
   - Backend **dilarang mempercayai nilai `store_id` yang dikirim dari payload request client pengguna terautentikasi**. Nilai `store_id` wajib dibaca server-side dari user yang terautentikasi melalui session (`$request->user()->store_id`).
4. **Batas Akses Token Pelanggan:**
   - Pelanggan publik tidak memiliki akun login.
   - Akses pelanggan dibatasi oleh `public_token` (NFC/QR) yang hanya membuka profil toko dan status antrean toko tersebut, serta `access_token` yang hanya mengizinkan pembacaan live status antrean miliknya.

### 1.2 Arsitektur Pertahanan Berlapis (Defense in Depth)
Pencegahan kebocoran data antar-toko (*cross-tenant leakage*) ditegakkan melalui 5 lapis pengamanan:
1. *Context Middleware:* Tenant dashboard hanya berasal dari user pada session terautentikasi (`$request->user()->store_id`), bukan dari request. Tenant context bersifat request-scoped dan dibersihkan setelah request, termasuk saat terjadi exception. Context yang tidak tersedia membuat query tenant-scoped gagal tertutup.
2. *Policy & Authorization:* Memeriksa kepemilikan data (`$order->store_id === $user->store_id`). Super Admin hanya memperoleh bypass tenant melalui route yang secara eksplisit mengaktifkan platform context dan mengotorisasi ability resource terkait.
3. *FormRequest Validation:* Memvalidasi parameter relasi berada pada toko yang sama dan benar-benar terkait dengan parent route:
   ```php
   Rule::exists('queues', 'id')->where('store_id', $storeId)
   ```
4. *Explicit Scope Query:* Seluruh query mutasi dan pembacaan menyertakan `->where('store_id', $storeId)` secara eksplisit.
5. *Eloquent Global Scope:* Jaring pengaman otomatis pada model Eloquent. Tanpa TenantContext, scope tidak mengembalikan data tenant. Bypass scope hanya aktif selama middleware platform khusus Super Admin pada route terotorisasi; group Super Admin dasar tidak mengaktifkan bypass.

---

## 2. Aturan Otorisasi & Keamanan Akun Pengguna

### 2.1 Peran Pengguna
1. **`merchant`:**
   - Terikat ke satu toko melalui `users.store_id`.
   - Hanya memiliki wewenang mengelola data operasional toko miliknya.
2. **`super_admin`:**
   - Kolom `users.store_id` bernilai `NULL`.
   - Memiliki wewenang lintas toko untuk registrasi toko, aktivasi kartu NFC, dan audit kepatuhan platform.

### 2.2 Penegakan Keamanan Soft Delete Akun (Keputusan C3)
- **Mekanisme:** Akun merchant dinonaktifkan menggunakan kolom **`users.deleted_at`** (Laravel `SoftDeletes`). Seluruh user soft-deleted, termasuk `super_admin`, ditolak untuk login dan request dashboard terproteksi.
- **Audit Kolom `users.is_active`:** Entitas `users` **tidak memiliki kolom `is_active`**. Status akun merchant dikendalikan oleh `users.deleted_at`. Status operasional toko diatur terpisah pada `stores.is_active`.
- **Preservasi Data:** Data toko (`stores`), katalog menu (`products`), tiket antrean (`queues`), pesanan (`orders`), dan riwayat audit (`audit_logs`) **tetap dipertahankan**.
- **Akses Super Admin:** Super Admin dapat mengakses akun dan riwayat merchant terkait menggunakan `User::withTrashed()`.

> **PENTING — Titik Pemeriksaan Autentikasi yang Wajib Diterapkan:**
> 1. **Proses Login:** Autentikasi memakai Laravel Sanctum SPA Cookie/Session dengan email dan password. User soft-deleted ditolak; `super_admin` wajib memiliki `store_id = NULL`; merchant wajib memiliki Store yang tidak soft-deleted, `is_active = 1`, dan belum kedaluwarsa.
> 2. **Session Dashboard:** Request first-party menggunakan session cookie dan perlindungan CSRF. Dashboard tidak menerbitkan atau menggunakan Personal Access Token (PAT).
> 3. **Session Generation:** Setiap user memiliki `users.session_generation`. Login menyimpan generation terbaru pada session browser; setiap request session dashboard membandingkannya dengan nilai database dan menolak marker yang hilang, invalid, atau stale.
> 4. **Soft Delete dan Restore:** Semua perubahan status akun wajib melalui `UserSessionRevocationService`. Saat soft-delete maupun restore, generation dinaikkan dan seluruh session database milik user dicabut berdasarkan `user_id`. Setelah restore, user wajib login ulang; session lama tidak boleh aktif kembali.
> 5. **Request Terproteksi:** Setiap request dashboard menolak user soft-deleted, termasuk `super_admin`. Request merchant juga memeriksa ulang bahwa Store masih valid, tidak soft-deleted, aktif, dan belum kedaluwarsa.
> 6. **Logout:** Session di-invalidate dan token CSRF diregenerasi. Session user yang sudah soft-deleted tidak boleh mengautentikasi request berikutnya.
> 7. **PAT Masa Depan:** Personal Access Token dapat digunakan untuk integrasi eksternal atau aplikasi mobile di masa depan, tetapi bukan untuk dashboard browser.

---

### 2.3 Kepemilikan Child Resource dan Pencatatan Backend
- `order_items` dimiliki oleh `orders`; kepemilikan Store ditentukan melalui Order. Product pada OrderItem harus berasal dari Store yang sama.
- `queue_status_histories` dimiliki oleh `queues`; kepemilikan Store ditentukan melalui Queue.
- Relasi Queue–QueueSession–NFC Card dan Order–Queue–NFC Card wajib divalidasi agar seluruh resource berasal dari Store yang sama.
- `audit_logs` dan `queue_status_histories` dibuat oleh mekanisme backend yang memvalidasi aksi/transition. Dashboard/client tidak boleh mengirim catatan audit atau history secara bebas.

---

## 3. Aturan Relasi Antrean, Sumber, dan Kartu NFC

### 3.1 One Queue = Maximum One Order (Keputusan C1)
- Relasi antara `queues` dan `orders` bersifat **One-to-Zero-or-One (`1:0..1`)**.
- Satu nomor antrean maksimal hanya boleh memiliki **satu pesanan**.
- Pelanggan diperbolehkan mengambil antrean tanpa memesan makanan (Mode Antrean Saja - PRD 4.1.3).
- Pelanggan di meja dapat memesan makanan langsung tanpa tiket antrean fisik (`orders.queue_id = NULL`).
- Ditegakkan di database MySQL via **`UNIQUE KEY uq_orders_queue_id (queue_id)`**.

### 3.2 Matriks Kombinasi `queues.source` dan `queues.nfc_card_id` Saat Queue Dibuat
Berdasarkan mekanisme NFC dan Barcode pada PRD Bab 2.3 dan 4.2.13, kombinasi berikut divalidasi ketika Queue baru dibuat:

| Nilai `source` | Nilai `nfc_card_id` | Alur Operasional & Sumber Transaksi |
| :--- | :---: | :--- |
| **`nfc`** | **NOT NULL** *(Wajib saat pembuatan Queue)* | Pelanggan melakukan tap fisik pada chip NFC meja/kios. Nilai `nfc_card_id` diverifikasi dari hasil hash `public_token_hash`. |
| **`barcode`** | **NOT NULL** *(Mayoritas)* atau **NULL** *(Khusus)* | **NOT NULL:** Pelanggan memindai QR code pada standee meja/kartu fisik yang memuat URL token kartu yang sama dengan NFC.<br>**NULL:** Jika toko memasang poster/banner QR umum di pintu masuk toko (akses toko umum tanpa identitas meja). |
| **`manual`** | **NULL** *(Wajib saat pembuatan Queue)* | Kasir menambahkan nomor antrean secara manual melalui dashboard merchant (PRD 4.2.13: *"Tambah Antrean Manual"*). Tidak ada kartu NFC/meja yang terlibat. |

Ketentuan historis setelah Queue dibuat:
- Jika NFC Card yang terkait dihapus secara fisik, foreign key `ON DELETE SET NULL` mengubah `queues.nfc_card_id` menjadi `NULL`; nilai `queues.source` tetap menyimpan sumber awal, termasuk `nfc`.
- Queue historis dengan `source = 'nfc'` dan `nfc_card_id = NULL` setelah hard delete kartu diperbolehkan. Kombinasi historis ini bukan input yang valid untuk pembuatan Queue baru.
- Soft delete NFC Card tidak memicu aksi foreign key; `queues.nfc_card_id` tetap merujuk ke kartu tersebut.

### 3.3 Aturan Manajemen Kartu NFC
- Satu Store maksimal memiliki **satu kartu non-deleted**, termasuk kartu berstatus pending atau inactive. Ditegakkan via virtual column `nfc_cards.active_store_id` dan `UNIQUE KEY uq_store_single_card`.
- `nfc_cards.card_uid` unik global, termasuk kartu soft-deleted. Ditegakkan via `UNIQUE KEY uq_card_uid_global`.
- Kartu baru selalu dibuat pending: `is_active = false`, `activated_at = NULL`, `deactivated_at = NULL`. Aktivasi dilakukan melalui aksi eksplisit.
- Create/assign kartu hanya membutuhkan Store yang tidak soft-deleted. Aktivasi dan resolusi publik hanya berjalan jika Store operasional: `is_active = true`, `expires_at` belum lewat, dan `deleted_at = NULL`.
- Hapus kartu hanya soft delete. Soft delete membebaskan slot Store. Tidak ada restore, force delete, reassignment ke Store lain, atau pembuatan inventory kartu tanpa Store.
- Perubahan label/tipe dan perubahan status adalah aksi terpisah. No-op tidak menulis audit atau mengubah timestamp.
- Merchant hanya dapat melihat metadata kartu milik Store-nya. Response merchant tidak memuat `card_uid`, token, hash, atau `store_id`.
- Plaintext token hanya dikembalikan sekali saat create. Tidak ada endpoint untuk mengambil ulang atau merotasi token. Database hanya menyimpan hash SHA-256.
- Resolusi publik memakai lookup hash token, tidak membocorkan ID internal/UID/token/hash, dan mengembalikan 404 generik untuk token invalid, kartu tidak aktif/soft-deleted, atau Store tidak operasional. `last_used_at` hanya diperbarui setelah resolusi sukses.

---

## 4. Pembayaran Eksternal & Pengakuan Pendapatan (Keputusan C2 & PRD 5.5)

### 4.1 Pembayaran di Luar TAPZY (External Payment)
1. **Bukan Payment Gateway:**
   - TAPZY MVP **tidak memproses transaksi keuangan secara langsung**, tidak menyediakan gateway perbankan, dan tidak menampung dana (*escrow*).
   - Pembayaran dilakukan langsung antara pelanggan dan pedagang di luar sistem (tunai di kasir fisik atau QRIS statis toko).
2. **Penyederhanaan Skema Database:**
   - Kolom `payment_status`, `payment_method`, dan `paid_at` **DIHAPUS DARI RANCANGAN TABEL `orders`** pada MVP untuk mencegah asumsi keliru bahwa sistem memvalidasi dana perbankan.

### 4.2 Pengakuan Pendapatan (Revenue Recognition)
1. Sesuai **PRD 5.5 (Aturan Pendapatan)**, rekapitulasi omzet dan pendapatan toko dihitung berdasarkan total nominal dari order yang memiliki status:
   ```text
   orders.status = 'completed'
   ```
2. **Makna Status `completed`:**
   - Status `completed` menandakan bahwa pesanan telah **selesai secara operasional** (makanan/minuman telah disiapkan oleh dapur dan diserahkan kepada pelanggan).
   - Status `completed` **bukan merupakan bukti bahwa sistem TAPZY telah memverifikasi penerimaan uang**.

---

## 5. Aturan Sesi Antrean & Reset Sesi (Keputusan C4 Final)

### 5.1 Satu Sesi Aktif per Toko & Keunikan Kode Sesi
1. **Satu Sesi Aktif:** Satu toko **hanya boleh memiliki tepat satu sesi antrean aktif** (`status = 'active'`) pada satu waktu.
   - *Proteksi Database MySQL 8:* Kolom virtual `active_store_id` dan `UNIQUE KEY uq_store_single_active_session (active_store_id)`.
   - *Proteksi Konkurensi Aplikasi:* Transaksi pembukaan/reset sesi mengunci baris toko utama menggunakan `Store::lockForUpdate()`.
2. **Keunikan `session_code`:**
   - Ditegakkan constraint unik per toko: **`UNIQUE KEY uq_store_session_code (store_id, session_code)`**.

### 5.2 Perilaku Reset Sesi Antrean (Final)
Ketika merchant melakukan reset antrean:

| Status Antrean Sebelumnya | Status Antrean Setelah Reset | Keterangan & Tindakan Sistem |
| :--- | :---: | :--- |
| **`waiting`** | **`cancelled`** | Dibatalkan otomatis; kolom `cancelled_at` diisi waktu reset. |
| **`called`** | **`cancelled`** | Dibatalkan otomatis; kolom `cancelled_at` diisi waktu reset. |
| **`in_service`** | **`cancelled`** | Dibatalkan otomatis (Keputusan C4); kolom `cancelled_at` diisi waktu reset. |
| **`skipped`** | **`cancelled`** | **Keputusan Final:** Dibatalkan otomatis; alasan dicatat `Auto-cancelled due to session reset` dan `cancelled_at` diisi waktu reset. |
| **`completed`** | **`completed`** | **Tidak berubah;** status antrean yang telah selesai tetap utuh sebagai riwayat. |
| **`cancelled`** | **`cancelled`** | **Tidak berubah;** antrean yang sudah batal tetap batal. |

Ketentuan Tambahan Saat Reset:
1. **Pencatatan Histori Status:** Setiap perubahan status antrean (`waiting`, `called`, `in_service`, `skipped` menjadi `cancelled`) wajib dicatat satu per satu ke dalam tabel `queue_status_histories` dengan catatan: *"Auto-cancelled due to session reset"*.
2. **Perilaku Terhadap Order Terkait:**
   - Order terkait antrean yang **belum selesai** (`pending`, `confirmed`, `preparing`, `ready`) ➔ **ikut dibatalkan (`status = 'cancelled'`)**.
   - Order yang **sudah `completed`** ➔ **TIDAK BOLEH DIUBAH** dan data transaksinya tetap utuh.
3. **Atomisitas Transaksi:** Seluruh rangkaian proses reset (pembatalan antrean, pencatatan histori, pembatalan order belum selesai, penutupan sesi lama, dan pembukaan sesi baru) **wajib berada di dalam satu transaksi database atomik (`DB::transaction`)**.
4. **Siklus Sesi:** Sesi lama ditutup (`status = 'closed'`, `closed_at = now()`), dan sesi baru dibuka (`status = 'active'`, `opened_at = now()`, `last_queue_number = 0`). Seluruh data lama tersimpan permanen.

---

## 6. Kebijakan Soft Delete dan Hard Delete

Platform TAPZY membedakan secara tegas antara penghapusan logis (*soft delete*) dan penghapusan fisik (*hard delete*):

### 6.1 Penerapan Soft Delete di Tingkat Entitas
- **Akun Merchant:** Soft delete melalui **`users.deleted_at`**. Merchant dinonaktifkan tanpa menghapus akun atau memutus data relasi toko.
- **Toko (Store):** Soft delete melalui **`stores.deleted_at`**, serta kontrol operasional harian melalui flag status **`stores.is_active`**.
- **Katalog Produk:** Soft delete melalui **`products.deleted_at`**, atau penonaktifan sementara tanpa delete via `is_available = 0` / `is_active = 0`.
- **Kartu NFC:** Penonaktifan operasional via `is_active = 0`, serta soft delete melalui **`nfc_cards.deleted_at`**.
- **Pesanan (Order):** Kolom `deleted_at` disiapkan untuk soft delete; **dilarang keras melakukan hard delete fisik pada pesanan dalam alur operasional normal aplikasi**.
- **Antrean (Queue):** Tidak ada hard delete dalam alur operasional normal aplikasi; pembatalan antrean dilakukan melalui transisi `status = 'cancelled'`.
- **Riwayat Status Antrean (`queue_status_histories`):** Data log mutlak; **tidak boleh dihapus** dalam alur operasional normal aplikasi.
- **Log Audit (`audit_logs`):** Bersifat *append-only*; **tidak boleh dihapus secara otomatis**.

### 6.2 Perbedaan Konseptual Soft Delete vs Hard Delete
1. **Soft Delete Bukan Hard Delete:** Operasi soft delete murni memodifikasi kolom timestamp `deleted_at = now()`. Baris data fisik tetap tersimpan utuh di storage engine disk MySQL.
2. **Soft Delete Tidak Memicu Foreign Key `ON DELETE`:** Karena baris fisik tidak dihapus dari MySQL, aksi foreign key `ON DELETE RESTRICT`, `ON DELETE CASCADE`, atau `ON DELETE SET NULL` **tidak akan pernah terpicu** oleh operasi soft delete.
3. **Larangan Fitur Hard Delete untuk Data Historis:** Aplikasi bisnis TAPZY tidak boleh menyediakan fitur atau endpoint API untuk melakukan hard delete fisik terhadap data historis (toko, antrean, sesi, pesanan, dan log audit).

### 6.3 Kebijakan Masa Berlaku Store (`stores.expires_at`)
1. **Wajib dan NOT NULL:** Setiap Store wajib memiliki `expires_at`. Migration `add_expires_at_to_stores_table` menambahkan kolom nullable, mem-backfill Store lama, lalu mengubahnya menjadi `NOT NULL`.
2. **Tipe Data:** Kolom memakai `DATETIME`, bukan `TIMESTAMP`, agar dapat menyimpan tanggal jauh seperti 2099. Rentang `TIMESTAMP` MySQL berhenti pada 2038-01-19 UTC.
3. **Timezone:** Nilai disimpan dalam **UTC**. Input dan tampilan memakai **Asia/Jakarta**. Konversi dua arah dilakukan oleh cast `App\Casts\UtcDateTime`; string input dianggap waktu Asia/Jakarta.
4. **Nilai Transisi:** Store lama yang `expires_at`-nya NULL di-backfill `2099-12-31 23:59:59` UTC. Nilai ini adalah sentinel transisi, bukan masa berlaku bisnis final, dan wajib ditinjau serta diperbarui Super Admin.
5. **Pemisahan Status:** `expires_at` dan `is_active` adalah dua status berbeda. Melewati `expires_at` **tidak** mengubah `is_active` secara otomatis.
6. **Definisi Kedaluwarsa:** Store dianggap kedaluwarsa jika `expires_at <= now()`. Store dengan `expires_at` NULL dianggap kedaluwarsa (fail-closed).
7. **Akses:** Store yang kedaluwarsa atau soft-deleted tidak boleh digunakan untuk membuat antrean/pesanan baru sesuai aturan akses endpoint yang berlaku. Login merchant, dashboard merchant, dan tenant context wajib menolak Store kedaluwarsa; akses Super Admin tidak terpengaruh.
8. **Perbandingan SQL:** Semua perbandingan expiry di level SQL wajib memakai string UTC (`Y-m-d H:i:s`), bukan `NOW()` lokal.

### 6.4 Pengelolaan Store oleh Super Admin (Batch 3.2)
1. **Otorisasi:** Seluruh endpoint admin Store menggunakan middleware `dashboard.super_admin` + `tenant.platform`. Setiap operasi memeriksa policy (`viewAny`, `create`, `manage`, `delete`). Merchant tidak memiliki akses endpoint admin pada fase ini.
2. **Slug:** `slug` opsional saat create; jika kosong dibuat dari `name`. Slug unik global termasuk Store yang sudah soft-deleted. Bentrok slug otomatis memakai suffix deterministik `-2`, `-3`, dst; slug eksplisit yang bentrok ditolak 422. Slug tidak dapat diubah melalui update profil pada fase ini.
3. **Create:** `expires_at` wajib dan harus masa depan. Super Admin boleh menetapkan tanggal masa depan apa pun, termasuk mengoreksi sentinel 2099.
4. **Update Profil:** Hanya `name`, `description`, `phone`, `address`, `google_maps_url`, `avg_service_time`, `queue_prefix`, dan `ordering_mode`. Field `is_active`, `expires_at`, `slug`, dan `store_id` ditolak 422.
5. **Status:** Perubahan `is_active` hanya melalui endpoint status terpisah.
6. **Expiry:** Perubahan `expires_at` hanya melalui endpoint expiry terpisah; tanggal lampau ditolak 422; perubahan expiry tidak mengubah `is_active`.
7. **Soft Delete:** Hapus Store hanya melalui soft delete. Store soft-deleted tersembunyi dari list, detail, dan expiry alerts. Tidak ada restore, force delete, atau hard delete.
8. **Filter & Alert:** List mendukung `search` (name/slug), `is_active`, `expiry_status=expired|expiring|valid`, dan pagination. `valid` berarti belum kedaluwarsa (termasuk yang akan kedaluwarsa). `expiring` memakai default 7 hari (batas 1..365), tidak memasukkan Store yang sudah kedaluwarsa atau sentinel 2099. Endpoint `expiry-alerts` memisahkan `expired` dan `expiring`; pagination tiap kelompok memakai `expired_page` dan `expiring_page`.
9. **Audit:** Aksi `store.created`, `store.updated`, `store.activated`, `store.deactivated`, `store.expiry_updated`, dan `store.deleted`; `entity_type = App\Models\Store`; payload snapshot whitelist; `expires_at` UTC ISO; key sensitif diredaksi rekursif; append-only; kegagalan audit membatalkan transaksi.

---

## 7. Matriks Kebijakan Foreign Key Deletion (Database Level)

Untuk memastikan bahwa perlindungan data historis tidak hanya bergantung pada larangan di level kode aplikasi, melainkan **dijamin secara mutlak oleh storage engine database MySQL**, seluruh foreign key dikonfigurasi sebagai berikut:

| No | Relasi Foreign Key | Tabel Anak (Kolom FK) | Tabel Induk | Kebijakan Deletion | Konsekuensi MySQL Storage Engine |
| :---: | :--- | :--- | :--- | :---: | :--- |
| 1 | `stores → queues` | `queues.store_id` | `stores.id` | **RESTRICT** | Toko tidak bisa di-hard delete jika memiliki data antrean. |
| 2 | `stores → queue_sessions` | `queue_sessions.store_id` | `stores.id` | **RESTRICT** | Toko tidak bisa di-hard delete jika memiliki siklus sesi antrean. |
| 3 | `stores → products` | `products.store_id` | `stores.id` | **RESTRICT** | Toko tidak bisa di-hard delete jika memiliki katalog produk. |
| 4 | `stores → nfc_cards` | `nfc_cards.store_id` | `stores.id` | **RESTRICT** | Toko tidak bisa di-hard delete jika memiliki aset kartu NFC. |
| 5 | `stores → orders` | `orders.store_id` | `stores.id` | **RESTRICT** | Toko tidak bisa di-hard delete jika memiliki transaksi pesanan. |
| 6 | `queues → queue_status_histories` | `queue_status_histories.queue_id` | `queues.id` | **RESTRICT** | Antrean tidak bisa di-hard delete jika memiliki riwayat status. |
| 7 | `orders → order_items` | `order_items.order_id` | `orders.id` | **RESTRICT** | Pesanan tidak bisa di-hard delete jika memiliki baris item transaksi. |
| 8 | `queues → orders` | `orders.queue_id` | `queues.id` | **RESTRICT** | Antrean tidak bisa di-hard delete jika telah menghasilkan pesanan. |
| 9 | `users → stores` | `users.store_id` | `stores.id` | **RESTRICT** | Toko tidak bisa di-hard delete jika masih ada akun merchant terikat. *(Super admin tetap diizinkan `store_id = NULL`)*. |
| 10 | `products → order_items` | `order_items.product_id` | `products.id` | **SET NULL** | Jika master produk dihapus fisik, baris pesanan tetap aman berkat snapshot nama dan harga. |
| 11 | `nfc_cards → queues` | `queues.nfc_card_id` | `nfc_cards.id` | **SET NULL** | Jika kartu fisik dinonaktifkan/dihapus, tiket antrean pelanggan tetap utuh. |
| 12 | `nfc_cards → orders` | `orders.nfc_card_id` | `nfc_cards.id` | **SET NULL** | Jika kartu meja dihapus, transaksi pesanan meja tetap tersimpan utuh. |
| 13 | `audit_logs → stores` | `audit_logs.store_id` | `stores.id` | **SET NULL** | Catatan audit platform tetap utuh (`store_id` menjadi `NULL`). |
| 14 | `audit_logs → users` | `audit_logs.user_id` | `users.id` | **SET NULL** | Catatan audit platform tetap utuh (`user_id` menjadi `NULL`). |
| 15 | `queue_sessions → queues` | `queues.queue_session_id` | `queue_sessions.id` | **RESTRICT** | Sesi tidak bisa di-hard delete jika sudah memiliki tiket antrean. |
| 16 | `users → queue_status_histories` | `queue_status_histories.changed_by_user_id` | `users.id` | **SET NULL** | Jika akun petugas dihapus, rekam jejak audit status antrean tetap aman. |

---

## 8. Kebijakan Retensi & Penegakan Audit Log (Keputusan C5)

### 8.1 Status Kebijakan Retensi
> **"Audit log tidak dihapus secara otomatis. Data dipertahankan sampai kebijakan retensi ditetapkan melalui keputusan bisnis dan kepatuhan. Masa penyimpanan akhir masih belum diputuskan."**

- Dilarang membuat scheduled job, cron, atau mekanisme auto-purge audit log pada tahap MVP.
- Kebijakan retensi menunggu keputusan formal dari pemangku kepentingan bisnis dan kepatuhan hukum.

### 8.2 Penegakan Append-Only
- **Level Aplikasi:** Model Eloquent `AuditLog` menonaktifkan operasi mutasi normal (`update`, `delete`, `restore`). Tidak ada rute API untuk mengubah atau menghapus data audit.
- **Pengakuan Batasan Keamanan:** Pembatasan pada model merupakan perlindungan di level aplikasi. Akses database langsung (via root MySQL/DBA) tetap membutuhkan pembatasan privilege `REVOKE UPDATE, DELETE ON audit_logs`.
- **Integritas Relasi:** Foreign key `audit_logs.store_id` dan `audit_logs.user_id` menggunakan `ON DELETE SET NULL`.
- **Aksi Store:** `store.created`, `store.updated`, `store.activated`, `store.deactivated`, `store.expiry_updated`, dan `store.deleted`. Payload memakai snapshot whitelist; nilai `expires_at` disimpan sebagai UTC ISO; key sensitif diredaksi rekursif; tidak ada raw request body.
- **Aksi NFC Card:** `nfc_card.created`, `nfc_card.updated`, `nfc_card.activated`, `nfc_card.deactivated`, dan `nfc_card.deleted`. Snapshot memakai whitelist metadata kartu; plaintext token dan `public_token_hash` tidak pernah masuk audit. Penulisan audit berada dalam transaksi yang sama dengan perubahan kartu.

---

## 9. Tata Kelola Token Kriptografis

1. **Token Publik Kartu NFC (`nfc_cards.public_token_hash`):**
   - Plaintext token di-generate via CSPRNG (40 karakter acak) dan ditanam pada chip NFC / QR URL.
   - Database hanya menyimpan **hash SHA-256** (`CHAR(64)`).
   - Lookup URL: `WHERE public_token_hash = hash('sha256', $plainInput)`.
   - Plaintext token hanya dikembalikan satu kali pada response create. Tidak ada endpoint untuk mengambil ulang atau merotasi token.
   - Resolusi publik dibatasi rate limit berdasarkan IP dan hash token; plaintext token tidak boleh dicatat ke log.
2. **Token Pelacakan Antrean Pelanggan (`queues.access_token_hash`):**
   - Plaintext token dikirim satu kali ke perangkat pelanggan saat tiket dibuat.
   - Database hanya menyimpan hash SHA-256.
   - Pelanggan melacak status live melalui hash matching tanpa perlu membuat akun login.
3. **Keamanan Log:**
   - **Token plaintext dilarang keras dicatat ke dalam `audit_logs` atau file log aplikasi.**

---

## 10. Changelog Revisi Desain

| Versi | Tanggal | Ringkasan Revisi |
| :---: | :---: | :--- |
| **v1.0** | 2026-11-05 | Inisialisasi awal 10 entitas database. |
| **v1.1** | 2026-11-05 | Urutan migration, hash SHA-256 token, dan virtual column `active_store_id`. |
| **v1.2** | 2026-11-05 | Penerapan One Queue = Maximum One Order dan soft delete merchant. |
| **v1.3** | 2026-11-05 | Penyelarasan penuh PRD v1.0, penghapusan kolom pembayaran, dan integrasi migration Sanctum riil. |
| **v1.4** | 2026-11-05 | Penegasan ketiadaan `users.is_active`, unique `session_code`, dan pengetatan foreign key. |
| **v1.5 (Final)** | 2026-11-06 | **1.** Finalisasi penanganan status `skipped` saat reset antrean (resmi diubah menjadi `cancelled`).<br>**2.** Finalisasi kebijakan foreign key deletion: pengetatan menjadi **`RESTRICT`** pada `orders -> order_items`, `queues -> orders`, dan `users -> stores` untuk mencegah penghapusan kaskade data historis finansial dan operasional.<br>**3.** Penegasan eksplisit bahwa soft delete tidak memicu foreign key `ON DELETE` dan dilarang membuat fitur hard delete untuk data historis dalam alur operasional normal.<br>**4.** Audit sinkronisasi total antara ERD, Data Dictionary, dan Business Rules. |
| **v1.6 (Final)** | 2026-11-07 | **1.** Penambahan kebijakan masa berlaku Store melalui `stores.expires_at` bertipe `DATETIME` UTC.<br>**2.** Backfill Store lama dengan `2099-12-31 23:59:59` UTC sebagai nilai transisi.<br>**3.** Penegasan bahwa `expires_at` terpisah dari `is_active` dan tidak menonaktifkan Store secara otomatis.<br>**4.** Konversi input/tampilan Asia/Jakarta melalui cast `App\Casts\UtcDateTime`. |
| **v1.7 (Final)** | 2026-11-07 | **1.** Penambahan aturan pengelolaan Store oleh Super Admin: slug otomatis/unik, pemisahan update profil/status/expiry, soft delete, filter, dan expiry alerts.<br>**2.** Penegasan enforcement expiry pada login merchant, dashboard merchant, dan tenant context.<br>**3.** Penambahan aksi audit Store, snapshot whitelist, redaksi key sensitif, serta rollback transaksi jika audit gagal.<br>**4.** Penegasan perbandingan expiry SQL memakai string UTC, bukan `NOW()` lokal. |

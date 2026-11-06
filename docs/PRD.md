# PRODUCT REQUIREMENTS DOCUMENT (PRD)

# NFC Smart Queue & Ordering Platform

**Versi:** 1.0  
**Tanggal:** 30 Oktober 2026  
**Status:** Draft – Siap untuk AI Agent  
**Jenis Produk:** Multi-Tenant SaaS + NFC Card + Barcode  
**Target Platform:** Responsive Web Application / PWA

---

# 1. PRODUCT OVERVIEW

## 1.1. Latar Belakang

Banyak pedagang UMKM masih menggunakan sistem antrean manual. Pelanggan harus bertanya kepada penjual mengenai nomor antrean, menunggu tanpa mengetahui perkiraan waktu pelayanan, dan terkadang harus datang langsung untuk melakukan pemesanan.

Sistem ini bertujuan membantu pedagang melakukan digitalisasi proses antrean dan pemesanan melalui kartu NFC dan barcode.

Setiap pedagang akan mendapatkan kartu NFC yang sudah dikonfigurasi dengan alamat sistem toko masing-masing. Pelanggan cukup menempelkan smartphone yang mendukung NFC pada kartu tersebut atau memindai barcode menggunakan kamera smartphone.

Setelah itu, pelanggan akan diarahkan ke halaman digital toko untuk:

- Mengambil nomor antrean.
- Melihat jumlah antrean yang sedang berjalan.
- Memesan produk apabila toko mengaktifkan fitur pemesanan.
- Memantau status antrean secara real-time.
- Membatalkan antrean apabila tidak jadi dilayani.

Sementara itu, penjual mendapatkan dashboard untuk mengelola antrean, menu, pesanan, pelanggan, dan laporan harian.

## 1.2. Product Vision

Menjadi platform antrean dan pemesanan digital yang mudah digunakan, terjangkau, dan dapat diimplementasikan oleh berbagai jenis pedagang UMKM di Indonesia.

## 1.3. Product Goals

1. Mempermudah pelanggan mengambil antrean tanpa harus bertanya kepada penjual.
2. Mengurangi kepadatan dan kebingungan antrean di lokasi pedagang.
3. Memberikan informasi status antrean secara real-time.
4. Memungkinkan pelanggan memesan produk sebelum mendapatkan giliran pelayanan.
5. Mempermudah pedagang mengelola antrean dan pesanan.
6. Menyediakan laporan aktivitas dan pendapatan harian.
7. Menyediakan sistem yang dapat digunakan oleh banyak pedagang dalam satu platform.
8. Menghubungkan kartu NFC dan barcode fisik dengan halaman toko yang sesuai.

## 1.4. Value Proposition

**Untuk pedagang:**

- Tidak perlu membuat sistem antrean sendiri.
- Tidak perlu membeli perangkat antrean khusus.
- Dapat mengelola antrean melalui smartphone.
- Dapat menerima pesanan secara digital.
- Dapat mengetahui rekapitulasi transaksi.

**Untuk pelanggan:**

- Cukup tap NFC atau scan barcode.
- Tidak perlu menginstal aplikasi.
- Bisa memantau antrean melalui HP.
- Bisa memesan sebelum mendapatkan giliran.
- Bisa mengetahui estimasi waktu tunggu.

---

# 2. BUSINESS MODEL & SYSTEM CONCEPT

## 2.1. Model Bisnis

Produk yang dijual terdiri dari dua komponen:

**A. Physical Product**

- Kartu NFC.
- Barcode yang tercetak pada kartu.
- Setiap kartu mempunyai identitas unik dan terhubung ke satu toko.

**B. Digital Product**

- Dashboard penjual.
- Halaman pelanggan.
- Sistem antrean.
- Sistem pemesanan.
- Pengelolaan menu.
- Rekapitulasi harian.

## 2.2. Konsep Multi-Tenant

Sistem harus mendukung banyak pedagang dalam satu aplikasi.

Contoh:

| Pedagang             | URL Toko                    | Kartu NFC |
| -------------------- | --------------------------- | --------- |
| Toko Abadi           | domain.com/toko/toko-abadi  | NFC A     |
| Kedai Kopi Senja     | domain.com/toko/kopi-senja  | NFC B     |
| Ayam Geprek Pak Budi | domain.com/toko/ayam-geprek | NFC C     |

Setiap pedagang memiliki:

- Akun login sendiri.
- Profil toko sendiri.
- Menu sendiri.
- Antrean sendiri.
- Riwayat pesanan sendiri.
- Rekapitulasi sendiri.
- Kartu NFC dan barcode sendiri.

Data satu toko tidak boleh dapat diakses atau dimodifikasi oleh toko lain.

## 2.3. Mekanisme NFC dan Barcode

Setiap kartu NFC memiliki URL unik yang dapat dibuka melalui smartphone.

Contoh:

`https://domain.com/t/8f3a91c2`

Barcode pada kartu harus berisi URL yang sama.

Alur:

1. Admin platform membuat toko.
2. Sistem menghasilkan identitas kartu unik.
3. Admin menghubungkan kartu tersebut dengan toko.
4. URL ditulis ke chip NFC.
5. URL yang sama dicetak menjadi barcode.
6. Pelanggan melakukan tap NFC atau scan barcode.
7. Sistem mengidentifikasi toko berdasarkan identitas kartu.
8. Pelanggan diarahkan ke halaman toko yang sesuai.

**Ketentuan penting:**

- NFC dan barcode harus mengarah ke toko yang sama.
- Pelanggan tidak perlu login atau mendaftar akun.
- NFC tidak menyimpan data pesanan atau informasi pelanggan.
- Informasi transaksi disimpan di server.
- Identitas kartu tidak boleh menggunakan ID toko yang mudah ditebak.
- Admin harus dapat menonaktifkan atau mengganti kartu apabila kartu hilang atau rusak.
- Sistem harus dapat memeriksa apakah kartu sudah terdaftar dan aktif.

---

# 3. USER ROLES & PERMISSIONS

Sistem memiliki tiga jenis pengguna.

## 3.1. Super Admin (Platform Owner)

Super Admin adalah pemilik platform atau pengelola startup.

Hak akses:

- Login ke dashboard super admin.
- Melihat seluruh daftar toko.
- Membuat akun toko/penjual.
- Mengaktifkan dan menonaktifkan toko.
- Mengelola identitas kartu NFC.
- Menghubungkan kartu NFC dengan toko.
- Melihat status kartu NFC.
- Mengelola paket atau langganan toko (untuk pengembangan berikutnya).
- Melihat statistik keseluruhan platform.
- Mengelola akun penjual.
- Melihat aktivitas platform.

Super Admin tidak boleh mengubah transaksi toko tanpa alasan dan pencatatan aktivitas yang jelas.

## 3.2. Penjual (Merchant)

Penjual adalah pemilik atau petugas toko.

Hak akses:

- Login menggunakan username dan password.
- Melihat dashboard toko.
- Mengelola profil toko.
- Mengelola menu dan produk.
- Mengaktifkan atau menonaktifkan menu.
- Melihat antrean aktif.
- Memanggil antrean berikutnya.
- Melewati antrean.
- Membatalkan antrean.
- Menyelesaikan antrean.
- Menambahkan pelanggan secara manual.
- Melihat detail pesanan pelanggan.
- Menghubungi pelanggan melalui WhatsApp.
- Melihat daftar antrean selesai dan dibatalkan.
- Melihat rekapitulasi harian.
- Mengunduh laporan.

Penjual hanya boleh mengakses data tokonya sendiri.

## 3.3. Pelanggan (Customer)

Pelanggan tidak perlu memiliki akun.

Hak akses:

- Membuka halaman toko melalui NFC atau barcode.
- Melihat informasi antrean.
- Mengambil antrean.
- Mengisi nama dan nomor WhatsApp secara opsional.
- Melihat daftar menu jika fitur pemesanan aktif.
- Menambahkan produk ke keranjang.
- Mengatur jumlah produk.
- Menambahkan catatan pesanan.
- Melakukan konfirmasi pesanan.
- Melihat nomor antrean.
- Memantau antrean secara real-time.
- Membatalkan antrean selama masih memenuhi aturan pembatalan.

Pelanggan hanya boleh mengakses antrean dan pesanan miliknya sendiri.

---

# 4. CORE FEATURES

# 4.1. CUSTOMER APPLICATION

Halaman pelanggan harus didesain mobile-first karena sebagian besar pelanggan akan mengaksesnya melalui smartphone.

Tidak diperlukan instalasi aplikasi.

## 4.1.1. Halaman Awal / Informasi Antrean

Ketika pelanggan membuka URL NFC atau barcode, sistem menampilkan halaman toko.

Informasi yang ditampilkan:

- Nama toko.
- Nomor antrean yang sedang dilayani.
- Status antrean yang sedang berjalan.
- Jumlah antrean sebelum giliran pelanggan.
- Estimasi waktu tunggu.
- Nomor antrean pelanggan apabila sudah mengambil antrean.

Contoh:

**TOKO ABADI**

| Informasi            | Nilai     |
| -------------------- | --------- |
| Sedang dilayani      | A-7       |
| Antrean sebelum kamu | 2         |
| Estimasi menunggu    | ±10 menit |
| Nomor antrean kamu   | A-9       |

Tampilkan dua pilihan:

- YA: Tetap mengambil antrean.
- TIDAK: Kembali atau keluar dari proses pengambilan antrean.

Jika pelanggan belum mengambil antrean, sistem belum boleh memberikan nomor antrean.

## 4.1.2. Formulir Identitas Pelanggan

Setelah memilih untuk mengambil antrean, pelanggan dapat mengisi:

- Nama pelanggan (opsional).
- Nomor WhatsApp (opsional).

Ketentuan:

- Nama boleh dikosongkan.
- Nomor WhatsApp boleh dikosongkan.
- Nomor WhatsApp digunakan untuk memudahkan penjual menghubungi pelanggan.
- Berikan keterangan bahwa nomor WhatsApp disarankan apabila pelanggan meninggalkan pesanan.
- Validasi format nomor telepon apabila diisi.

Tombol:

- **AMBIL ANTRIAN.**

Setelah diklik, sistem membuat nomor antrean dan menampilkan halaman berikutnya.

## 4.1.3. Mode Antrean Saja

Toko dapat menggunakan sistem hanya untuk antrean tanpa pemesanan.

Jika fitur pemesanan dinonaktifkan:

- Pelanggan hanya mengambil antrean.
- Daftar menu tidak ditampilkan.
- Keranjang tidak ditampilkan.
- Tidak ada konfirmasi pembelian.
- Pelanggan langsung diarahkan ke halaman pemantauan antrean.

## 4.1.4. Mode Antrean + Pemesanan

Jika fitur pemesanan aktif, pelanggan dapat mengambil antrean sekaligus memesan produk.

Alur:

1. Pelanggan mengambil nomor antrean.
2. Pelanggan mengisi identitas.
3. Sistem menampilkan daftar menu.
4. Pelanggan memilih produk.
5. Produk masuk ke keranjang.
6. Pelanggan mengatur jumlah produk.
7. Pelanggan dapat menambahkan catatan.
8. Pelanggan melihat ringkasan pesanan.
9. Pelanggan melakukan konfirmasi.
10. Sistem menyimpan pesanan dan menghubungkannya dengan nomor antrean.

## 4.1.5. Daftar Menu

Informasi setiap produk:

- Nama produk.
- Harga.
- Status ketersediaan.
- Tombol tambah (+).
- Gambar produk (opsional untuk MVP).

Fitur:

- Pencarian menu.
- Menambah produk ke keranjang.
- Mengurangi jumlah produk.
- Menambah jumlah produk.
- Menampilkan total harga secara otomatis.

Produk yang tidak tersedia tidak boleh bisa ditambahkan ke keranjang.

## 4.1.6. Keranjang dan Catatan Pesanan

Keranjang menampilkan:

- Nama produk.
- Harga satuan.
- Jumlah produk.
- Subtotal.
- Catatan khusus produk.
- Total keseluruhan.

Contoh catatan:

- Es batu dipisah.
- Tidak pedas.
- Saus dipisah.
- Jangan menggunakan bawang.

Catatan dapat diberikan per produk.

Pelanggan dapat mengubah jumlah dan menghapus produk sebelum melakukan konfirmasi.

## 4.1.7. Konfirmasi Pesanan

Sebelum pesanan disimpan, tampilkan ringkasan:

- Nama toko.
- Nomor antrean.
- Nama pelanggan.
- Nomor WhatsApp.
- Daftar produk.
- Jumlah masing-masing produk.
- Catatan.
- Total harga.

Tombol:

- **PESAN & ANTRI.**

Setelah dikonfirmasi:

- Pesanan tersimpan.
- Nomor antrean terhubung dengan pesanan.
- Sistem mengarahkan pelanggan ke halaman status antrean.

Catatan: Konfirmasi pesanan bukan berarti pembayaran online telah dilakukan. MVP menggunakan pencatatan pesanan dan pembayaran secara langsung di lokasi pedagang.

## 4.1.8. Halaman Status Antrean

Halaman ini harus diperbarui secara real-time.

Informasi:

- Nama toko.
- Nomor antrean pelanggan.
- Nomor antrean yang sedang dilayani.
- Jumlah antrean yang masih menunggu.
- Estimasi waktu tunggu.
- Status antrean.
- Daftar antrean yang relevan.
- Detail pesanan jika pelanggan melakukan pemesanan.

Status antrean:

- MENUNGGU.
- DIPANGGIL.
- SEDANG DILAYANI.
- DILEWATI.
- SELESAI.
- DIBATALKAN.

Ketentuan:

- Jika nomor pelanggan sudah dipanggil, tampilkan pemberitahuan yang jelas.
- Jika pelanggan sudah dilayani, tampilkan status selesai.
- Jika antrean dibatalkan, tampilkan informasi pembatalan.
- Jika halaman ditutup dan dibuka kembali, pelanggan dapat melanjutkan pemantauan antrean melalui tautan status yang dimiliki.

## 4.1.9. Pembatalan Antrean

Pelanggan dapat membatalkan antrean melalui tombol **BATAL**.

Aturan:

- Pelanggan hanya dapat membatalkan antrean miliknya sendiri.
- Antrean yang sudah selesai tidak dapat dibatalkan.
- Antrean yang sedang dilayani tidak dapat dibatalkan melalui halaman pelanggan.
- Sistem menyimpan waktu dan alasan pembatalan jika tersedia.
- Nomor antrean yang dibatalkan tidak boleh digunakan kembali.

---

# 4.2. MERCHANT DASHBOARD

Dashboard penjual harus mudah digunakan melalui smartphone maupun desktop.

## 4.2.1. Login Penjual

Form login:

- Username.
- Password.
- Tombol MASUK.
- Lupa password.

Ketentuan:

- Username unik.
- Password disimpan dalam bentuk hash.
- Sesi login harus aman.
- Penjual tidak dapat masuk ke dashboard toko lain.
- Sediakan mekanisme reset password melalui email yang sudah terdaftar.

## 4.2.2. Navigasi Utama

Dashboard penjual memiliki empat menu utama sesuai rancangan:

1. RESET
2. TAMBAH
3. DAFTAR
4. AKUN

Menu navigasi dapat ditampilkan sebagai bottom navigation pada smartphone dan sidebar atau navigasi yang sesuai pada desktop.

## 4.2.3. Dashboard Utama

Menampilkan ringkasan:

- Jumlah antrean aktif.
- Jumlah antrean selesai.
- Jumlah total antrean hari ini.

Informasi tambahan:

- Nomor antrean yang sedang dilayani.
- Daftar antrean terbaru.
- Status operasional toko.

Contoh:

- ANTREAN: 3
- SELESAI: 6
- TOTAL: 10

Angka harus diperbarui otomatis ketika status antrean berubah.

## 4.2.4. Profil Toko

Penjual dapat mengatur:

- Nama toko.
- Lokasi atau alamat toko.
- Link Google Maps.
- Rata-rata waktu layanan per pelanggan.

Rata-rata waktu layanan digunakan untuk menghitung estimasi waktu tunggu.

Contoh:

- Rata-rata waktu layanan: 5 menit.
- Terdapat 3 antrean aktif sebelum pelanggan.
- Estimasi tunggu: sekitar 15 menit.

Sistem dapat menampilkan estimasi dalam bentuk perkiraan dan harus memperbaruinya ketika antrean berubah.

## 4.2.5. Pengaturan Kata Sandi

Form:

- Password lama.
- Password baru.
- Konfirmasi password baru.
- Email pemulihan.

Ketentuan:

- Password lama harus diverifikasi.
- Password baru harus memenuhi aturan keamanan.
- Konfirmasi harus sama dengan password baru.
- Email digunakan untuk proses pemulihan akun.

## 4.2.6. Pengelolaan Menu / Produk

Penjual dapat mengelola produk.

Fitur:

- Menambahkan menu.
- Mengedit menu.
- Menghapus atau mengarsipkan menu.
- Mengaktifkan menu.
- Menonaktifkan menu.
- Mencari menu.

Data produk:

- Nama produk.
- Harga.
- Status ketersediaan.
- Deskripsi (opsional).
- Foto (opsional).

Status:

- AKTIF: produk dapat dipesan.
- NONAKTIF: produk tidak dapat dipesan.

Gunakan indikator visual:

- Hijau untuk tersedia.
- Merah untuk habis atau tidak tersedia.

Pengaturan daftar menu memiliki toggle:

- ON: fitur pemesanan aktif dan penjual wajib memiliki minimal satu produk aktif.
- OFF: toko hanya menyediakan fitur antrean.

Jika daftar menu dinonaktifkan, pesanan baru tidak dapat dibuat oleh pelanggan.

Pesanan lama harus tetap tersimpan dan tidak boleh terhapus.

## 4.2.7. Pengelolaan Antrean

Halaman DAFTAR menjadi pusat pengelolaan antrean.

Terdapat dua tab:

**A. ANTREAN**

- Menunggu.
- Dipanggil.
- Dilewati.
- Sedang dilayani.

**B. SELESAI**

- Selesai.
- Dibatalkan.

Informasi setiap antrean:

- Nomor antrean.
- Nama pelanggan.
- Status.
- Waktu pengambilan.
- Indikator apakah memiliki pesanan.

## 4.2.8. Panggil Antrean Berikutnya

Penjual memiliki tombol **PANGGIL ANTRIAN BERIKUTNYA**.

Ketika diklik:

- Sistem memilih antrean berikutnya yang berstatus MENUNGGU.
- Status berubah menjadi DIPANGGIL atau SEDANG DILAYANI sesuai mekanisme pelayanan yang digunakan.
- Nomor antrean yang aktif diperbarui.
- Halaman pelanggan diperbarui secara real-time.

Jangan sampai dua antrean berbeda berstatus sedang dilayani secara bersamaan dalam satu loket pelayanan.

## 4.2.9. Lewati Antrean

Penjual dapat menekan tombol **LEWATI**.

Ketentuan:

- Status antrean berubah menjadi DILEWATI.
- Antrean tetap tersimpan dalam riwayat.
- Antrean tidak dihapus.
- Penjual dapat melanjutkan ke antrean berikutnya.
- Antrean yang dilewati dapat dipanggil kembali melalui tindakan manual jika diperlukan.

## 4.2.10. Batalkan Antrean

Penjual dapat membatalkan antrean apabila pelanggan tidak datang atau terdapat alasan operasional.

Ketentuan:

- Status berubah menjadi DIBATALKAN.
- Data tetap disimpan.
- Antrean masuk ke tab SELESAI.
- Jumlah pembatalan masuk dalam laporan.

## 4.2.11. Selesaikan Antrean

Penjual menekan tombol **SELESAI** setelah pelayanan selesai.

Ketentuan:

- Status antrean berubah menjadi SELESAI.
- Data antrean masuk ke tab SELESAI.
- Jika memiliki pesanan, transaksi dicatat sebagai selesai.
- Nilai pesanan masuk ke rekap pendapatan sesuai aturan pencatatan transaksi.

## 4.2.12. Detail Pesanan Pelanggan

Penjual dapat membuka detail antrean melalui tombol detail atau ikon mata.

Informasi:

- Nomor antrean.
- Nama pelanggan.
- Nomor WhatsApp.
- Daftar produk.
- Jumlah setiap produk.
- Catatan pesanan.
- Total harga.

Tombol:

- **HUBUNGI WA.**

Tombol WhatsApp membuka percakapan dengan nomor pelanggan jika tersedia.

Jika antrean hanya untuk pelayanan tanpa pemesanan, bagian daftar pesanan tidak perlu ditampilkan.

## 4.2.13. Tambah Antrean Manual

Fitur ini digunakan jika pelanggan:

- Tidak membawa smartphone.
- Tidak bisa menggunakan NFC.
- Tidak dapat memindai barcode.
- Meminta bantuan petugas.

Alur:

1. Penjual membuka menu TAMBAH.
2. Sistem menampilkan formulir pelanggan.
3. Penjual mengisi nama dan nomor WhatsApp (opsional).
4. Jika pemesanan aktif, penjual dapat memilih produk.
5. Penjual mengisi catatan pesanan jika diperlukan.
6. Penjual mengonfirmasi penambahan.
7. Sistem menghasilkan nomor antrean.
8. Antrean masuk ke daftar antrean aktif.

Antrean manual harus menggunakan mekanisme nomor yang sama dengan antrean pelanggan agar tidak terjadi duplikasi.

## 4.2.14. Rekapitulasi Harian

Penjual dapat melihat rekapitulasi berdasarkan tanggal.

Informasi:

- Total antrean.
- Total antrean dibatalkan.
- Total antrean selesai.
- Total pendapatan tercatat.
- Detail setiap transaksi.

Contoh:

**Tanggal: Rabu, 14 Oktober 2026**

| Statistik     |    Jumlah |
| ------------- | --------: |
| Total antrean |        10 |
| Dibatalkan    |         2 |
| Selesai       |         8 |
| Pendapatan    | Rp500.000 |

Detail laporan:

- Nomor antrean.
- Nama pelanggan.
- Nomor WhatsApp jika tersedia.
- Produk yang dipesan.
- Jumlah produk.
- Harga satuan.
- Subtotal.
- Total transaksi.

Tombol:

- **UNDUH.**

Format unduhan yang direkomendasikan:

- PDF untuk laporan yang mudah dibaca.
- CSV untuk pengolahan data.

Pendapatan harus dihitung berdasarkan transaksi yang benar-benar diselesaikan, bukan seluruh pesanan yang dibuat.

Untuk antrean tanpa pesanan, nilai transaksi adalah Rp0 dan tidak menambah pendapatan.

## 4.2.15. Reset Antrean

Penjual dapat melakukan reset antrean untuk memulai periode antrean baru.

Sebelum reset, sistem harus menampilkan konfirmasi.

Contoh:

> Apakah kamu yakin ingin melakukan reset antrean? Data antrean aktif pada periode ini akan ditutup.

Ketentuan:

- Reset tidak boleh menghapus riwayat transaksi.
- Reset tidak boleh menghapus data laporan.
- Reset hanya mengakhiri siklus antrean.
- Nomor antrean dapat dimulai kembali dari nomor awal sesuai konfigurasi.
- Data periode sebelumnya tetap dapat diakses.

---

# 4.3. SUPER ADMIN DASHBOARD

Fitur ini diperlukan agar startup dapat mengelola seluruh pedagang yang menggunakan platform.

## 4.3.1. Dashboard Super Admin

Informasi:

- Total toko terdaftar.
- Total toko aktif.
- Total toko nonaktif.
- Total kartu NFC terdaftar.
- Total kartu NFC aktif.
- Total pelanggan/antrean keseluruhan.
- Statistik penggunaan platform.

## 4.3.2. Manajemen Toko

Fitur:

- Tambah toko.
- Edit toko.
- Lihat detail toko.
- Aktifkan toko.
- Nonaktifkan toko.
- Kelola akun penjual.
- Lihat kartu yang terhubung.

Data toko:

- Nama toko.
- Slug toko.
- Pemilik toko.
- Username.
- Email.
- Nomor telepon.
- Alamat.
- Status toko.
- Tanggal pendaftaran.

## 4.3.3. Manajemen Kartu NFC

Fitur:

- Generate kartu baru.
- Melihat ID kartu.
- Menghubungkan kartu dengan toko.
- Mengubah toko yang terhubung dengan prosedur yang aman.
- Mengaktifkan kartu.
- Menonaktifkan kartu.
- Mengganti kartu yang rusak atau hilang.
- Melihat riwayat penggantian kartu.

Status kartu:

- BELUM TERDAFTAR.
- AKTIF.
- NONAKTIF.
- DIBLOKIR.

Data kartu:

- ID kartu internal.
- Token unik.
- Toko yang terhubung.
- URL tujuan.
- Status.
- Tanggal pembuatan.
- Tanggal aktivasi.

Setiap kartu hanya dapat terhubung ke satu toko aktif pada satu waktu.

## 4.3.4. Generate Barcode

Ketika kartu NFC dibuat atau dihubungkan dengan toko:

- Sistem menghasilkan URL unik.
- Sistem menghasilkan barcode QR dari URL tersebut.
- Admin dapat mengunduh QR dalam format PNG atau SVG.
- QR dapat digunakan untuk dicetak pada kartu fisik.

NFC dan QR harus memiliki tujuan URL yang sama.

---

# 5. BUSINESS RULES

Bagian ini merupakan aturan utama yang wajib dipatuhi sistem.

## 5.1. Aturan Multi-Tenant

- Setiap toko memiliki identitas unik.
- Setiap toko hanya dapat mengakses datanya sendiri.
- Semua data transaksi harus terhubung dengan toko.
- ID toko tidak boleh dijadikan satu-satunya pengaman akses.
- Semua endpoint harus melakukan pemeriksaan kepemilikan data.

## 5.2. Aturan Nomor Antrean

- Nomor antrean dibuat otomatis.
- Nomor antrean unik dalam satu toko dan satu periode antrean.
- Format nomor dapat berupa A-1, A-2, A-3.
- Prefix dapat dikonfigurasi.
- Nomor antrean yang sudah digunakan tidak boleh digunakan kembali dalam periode yang sama.
- Reset memulai periode baru, bukan menghapus riwayat.
- Pembuatan nomor harus aman terhadap dua pelanggan yang mengambil antrean bersamaan.

## 5.3. Aturan Status Antrean

Transisi status yang diperbolehkan:

| Status awal     | Aksi                  | Status berikutnya |
| --------------- | --------------------- | ----------------- |
| MENUNGGU        | Panggil               | DIPANGGIL         |
| DIPANGGIL       | Mulai melayani        | SEDANG DILAYANI   |
| DIPANGGIL       | Lewati                | DILEWATI          |
| MENUNGGU        | Batalkan              | DIBATALKAN        |
| DIPANGGIL       | Batalkan oleh penjual | DIBATALKAN        |
| SEDANG DILAYANI | Selesaikan            | SELESAI           |
| DILEWATI        | Panggil kembali       | DIPANGGIL         |

Status yang sudah terminal tidak boleh berubah sembarangan. Tindakan koreksi harus melalui mekanisme khusus dan tercatat dalam audit log.

## 5.4. Aturan Pemesanan

- Pesanan harus memiliki minimal satu produk.
- Produk harus aktif ketika pesanan dibuat.
- Harga produk disimpan sebagai snapshot pada detail pesanan.
- Perubahan harga produk tidak mengubah pesanan lama.
- Jumlah produk harus lebih dari nol.
- Catatan pesanan bersifat opsional.
- Pesanan harus terhubung dengan antrean.
- Total pesanan dihitung otomatis oleh backend.
- Pelanggan tidak boleh mengirim nilai total harga yang langsung dipercaya server.

## 5.5. Aturan Pendapatan

- Pesanan yang baru dibuat belum dianggap sebagai pendapatan selesai.
- Pesanan yang dibatalkan tidak menambah pendapatan.
- Pendapatan dihitung ketika transaksi ditandai selesai.
- Antrean tanpa pesanan tidak menghasilkan pendapatan.
- Data transaksi lama tidak boleh berubah ketika harga menu diperbarui.

## 5.6. Aturan Kartu NFC

- Satu kartu terhubung ke satu toko.
- Kartu nonaktif tidak boleh digunakan untuk mengambil antrean.
- Token kartu harus unik.
- Token tidak boleh mudah ditebak.
- Kartu yang hilang dapat dinonaktifkan.
- Kartu pengganti mendapatkan token baru.
- Barcode harus mengikuti status kartu NFC.

---

# 6. REAL-TIME SYSTEM

Real-time merupakan salah satu fitur utama produk.

Ketika penjual melakukan perubahan status antrean, halaman pelanggan harus langsung memperbarui informasi tanpa perlu memuat ulang halaman secara manual.

Contoh:

1. Pelanggan A mengambil nomor A-9.
2. Penjual memanggil A-7.
3. Halaman pelanggan A-9 memperbarui informasi jumlah antrean.
4. Penjual menyelesaikan A-7.
5. Sistem memanggil antrean berikutnya.
6. Pelanggan A-9 mendapatkan pembaruan status.

Teknologi yang direkomendasikan:

- Laravel Broadcasting.
- Laravel Reverb atau layanan WebSocket.
- Private channel untuk data yang bersifat pribadi.
- Public atau store-scoped channel untuk informasi antrean yang memang boleh dilihat pelanggan.

Polling berkala dapat digunakan sebagai fallback jika koneksi WebSocket terputus.

Ketentuan:

- Perubahan antrean harus konsisten di semua perangkat.
- Jangan mengandalkan data frontend sebagai sumber kebenaran.
- Server menjadi sumber utama status antrean.
- Pelanggan tidak boleh melihat data pribadi pelanggan lain.
- Data publik antrean hanya menampilkan informasi yang diperlukan.

---

# 7. UI/UX REQUIREMENTS

## 7.1. Design Principles

- Mobile-first.
- Responsive.
- Ringan dan cepat.
- Mudah dipahami pedagang yang tidak terbiasa menggunakan aplikasi.
- Tombol utama harus mudah ditemukan.
- Tidak terlalu banyak menu.
- Tampilan pelanggan dan penjual harus memiliki karakter visual konsisten.

## 7.2. Customer Interface

Halaman pelanggan harus sederhana.

Prioritas:

1. Nomor antrean.
2. Status antrean.
3. Estimasi menunggu.
4. Pemesanan produk.
5. Detail pesanan.

Jangan membuat pelanggan harus melewati terlalu banyak halaman.

## 7.3. Merchant Interface

Dashboard penjual harus mengutamakan kecepatan operasional.

Prioritas:

1. Antrean yang sedang dilayani.
2. Tombol panggil berikutnya.
3. Daftar antrean.
4. Detail pesanan.
5. Tambah antrean manual.
6. Ringkasan aktivitas.

## 7.4. Visual Feedback

Gunakan indikator warna:

- Hijau: aktif, tersedia, selesai.
- Merah: dibatalkan, tidak tersedia.
- Kuning: menunggu.
- Biru: sedang dipanggil atau sedang dilayani.
- Abu-abu: nonaktif.

Warna tidak boleh menjadi satu-satunya indikator. Selalu tampilkan teks status.

---

# 8. NON-FUNCTIONAL REQUIREMENTS

## 8.1. Performance

- Halaman utama harus ringan.
- Halaman pelanggan harus dapat dibuka dengan koneksi internet standar.
- API harus mengembalikan respons secara efisien.
- Gunakan pagination untuk data antrean dan riwayat.
- Hindari query database yang tidak diperlukan.

## 8.2. Security

- Gunakan authentication yang aman.
- Password wajib di-hash.
- Validasi seluruh input di backend.
- Proteksi CSRF untuk endpoint yang sesuai.
- Proteksi terhadap SQL Injection.
- Rate limiting untuk endpoint publik.
- Gunakan token kartu yang sulit ditebak.
- Batasi akses pelanggan berdasarkan token akses antrean.
- Batasi akses penjual berdasarkan tenant.
- Gunakan HTTPS pada production.
- Catat aktivitas penting melalui audit log.

## 8.3. Reliability

- Data pesanan dan antrean harus tersimpan di database.
- Gangguan koneksi tidak boleh menyebabkan duplikasi antrean.
- Proses pembuatan antrean harus menggunakan transaksi database.
- Status antrean harus konsisten.
- Sistem harus memiliki mekanisme penanganan kegagalan koneksi real-time.

## 8.4. Compatibility

- Android Chrome.
- Android browser lainnya.
- iPhone Safari.
- Desktop Chrome.
- Desktop Edge.

NFC bergantung pada dukungan perangkat dan browser. Karena itu, QR Code wajib tersedia sebagai alternatif akses.

---

# 9. DATABASE REQUIREMENTS

Berikut rancangan awal entitas database.

## 9.1. users

Menyimpan akun pengguna.

Kolom utama:

- id
- name
- username
- email
- password
- role
- email_verified_at
- created_at
- updated_at

Role:

- super_admin
- merchant

## 9.2. stores

Menyimpan data toko.

Kolom:

- id
- user_id
- nama_toko
- slug
- alamat
- google_maps_url
- rata_rata_waktu_layanan
- prefix_antrean
- mode_pemesanan
- status
- created_at
- updated_at

## 9.3. nfc_cards

Menyimpan informasi kartu NFC.

Kolom:

- id
- store_id
- token
- status
- activated_at
- deactivated_at
- created_at
- updated_at

## 9.4. queue_sessions

Menyimpan periode antrean.

Kolom:

- id
- store_id
- tanggal
- nomor_terakhir
- status
- started_at
- ended_at
- created_at
- updated_at

## 9.5. queues

Menyimpan antrean pelanggan.

Kolom:

- id
- store_id
- queue_session_id
- nomor_antrean
- nama_pelanggan
- nomor_whatsapp
- status
- sumber_antrean
- access_token
- created_at
- called_at
- completed_at
- cancelled_at
- updated_at

sumber_antrean:

- nfc
- barcode
- manual

## 9.6. products

Menyimpan daftar menu atau produk.

Kolom:

- id
- store_id
- nama_produk
- deskripsi
- harga
- gambar
- status_aktif
- created_at
- updated_at

## 9.7. orders

Menyimpan data pesanan.

Kolom:

- id
- store_id
- queue_id
- nomor_pesanan
- subtotal
- total
- status
- created_at
- completed_at
- updated_at

## 9.8. order_items

Menyimpan detail produk dalam pesanan.

Kolom:

- id
- order_id
- product_id
- nama_produk_snapshot
- harga_snapshot
- jumlah
- subtotal
- catatan
- created_at
- updated_at

## 9.9. queue_status_histories

Menyimpan riwayat perubahan status antrean.

Kolom:

- id
- queue_id
- status_sebelumnya
- status_baru
- changed_by
- keterangan
- created_at

## 9.10. audit_logs

Menyimpan aktivitas penting pengguna.

Kolom:

- id
- user_id
- store_id
- aktivitas
- detail
- created_at

Catatan implementasi:

- Gunakan foreign key.
- Tambahkan index pada store_id, queue_session_id, status, dan tanggal.
- Gunakan decimal atau integer rupiah untuk nilai uang, bukan floating point.
- Sesuaikan nama kolom dan relasi ketika implementasi dilakukan.
- Seluruh data harus memiliki batas kepemilikan toko yang jelas.

---

# 10. RECOMMENDED TECHNOLOGY STACK

Jika belum ada teknologi yang ditentukan, gunakan stack berikut:

## Backend

- Laravel 13.
- PHP 8.3 atau lebih baru.
- Laravel REST API.
- Laravel Sanctum untuk autentikasi yang sesuai.
- Laravel Reverb untuk real-time.

## Frontend

- Vue.js 3.
- Vite.
- Tailwind CSS.
- Axios.
- Responsive Web Application.
- PWA sebagai pengembangan lanjutan.

## Database

- MySQL 8 atau PostgreSQL.

## Additional Libraries

- Library QR Code generator.
- Library PDF export.
- Library CSV export.
- WebSocket client.

## Deployment

- VPS atau cloud server.
- Domain utama.
- SSL/HTTPS.
- Database production.
- Backup database berkala.

Teknologi ini merupakan rekomendasi, bukan ketentuan mutlak. Jika proyek sudah memiliki stack tertentu, pertahankan teknologi yang ada selama memenuhi kebutuhan sistem.

---

# 11. API REQUIREMENTS

API harus menggunakan RESTful convention dan memiliki versi, misalnya `/api/v1`.

Contoh endpoint:

## Authentication

- POST /api/v1/auth/login
- POST /api/v1/auth/logout
- GET /api/v1/auth/me
- POST /api/v1/auth/forgot-password
- POST /api/v1/auth/reset-password

## Public Store

- GET /api/v1/public/stores/{token}
- GET /api/v1/public/stores/{token}/queue-status
- GET /api/v1/public/stores/{token}/products

## Customer

- POST /api/v1/public/stores/{token}/queues
- GET /api/v1/public/queues/{access_token}
- POST /api/v1/public/queues/{access_token}/orders
- POST /api/v1/public/queues/{access_token}/cancel

## Merchant Dashboard

- GET /api/v1/merchant/dashboard
- GET /api/v1/merchant/profile
- PUT /api/v1/merchant/profile
- GET /api/v1/merchant/products
- POST /api/v1/merchant/products
- PUT /api/v1/merchant/products/{id}
- DELETE /api/v1/merchant/products/{id}
- GET /api/v1/merchant/queues
- POST /api/v1/merchant/queues/manual
- POST /api/v1/merchant/queues/{id}/call
- POST /api/v1/merchant/queues/{id}/skip
- POST /api/v1/merchant/queues/{id}/cancel
- POST /api/v1/merchant/queues/{id}/complete
- GET /api/v1/merchant/reports/daily
- GET /api/v1/merchant/reports/export

## Super Admin

- GET /api/v1/admin/dashboard
- GET /api/v1/admin/stores
- POST /api/v1/admin/stores
- PUT /api/v1/admin/stores/{id}
- PATCH /api/v1/admin/stores/{id}/status
- GET /api/v1/admin/nfc-cards
- POST /api/v1/admin/nfc-cards
- POST /api/v1/admin/nfc-cards/{id}/assign
- PATCH /api/v1/admin/nfc-cards/{id}/status
- GET /api/v1/admin/nfc-cards/{id}/qr

Endpoint di atas merupakan rancangan awal. AI Agent harus menyesuaikan kontrak API dengan implementasi sebenarnya dan mendokumentasikan seluruh endpoint.

---

# 12. MVP SCOPE & DEVELOPMENT PRIORITY

Pengembangan harus dilakukan bertahap agar sistem dapat diuji dan digunakan oleh pedagang sungguhan.

## Phase 1 — Core MVP

Wajib selesai:

1. Sistem multi-tenant.
2. Login super admin.
3. Login penjual.
4. Manajemen toko.
5. Generate token NFC.
6. Generate QR Code.
7. Halaman pelanggan.
8. Pengambilan antrean.
9. Form identitas pelanggan.
10. Pengelolaan menu.
11. Mode antrean saja.
12. Mode antrean + pemesanan.
13. Keranjang dan catatan pesanan.
14. Konfirmasi pesanan.
15. Dashboard penjual.
16. Panggil antrean.
17. Lewati antrean.
18. Batalkan antrean.
19. Selesaikan antrean.
20. Tambah antrean manual.
21. Pemantauan real-time.
22. Rekapitulasi harian.
23. Export PDF/CSV.
24. Reset periode antrean tanpa menghapus riwayat.

## Phase 2 — Business Enhancement

Fitur pengembangan berikutnya:

- Manajemen paket berlangganan.
- Pembayaran langganan pedagang.
- Statistik penjualan.
- Grafik performa toko.
- Multi-user untuk satu toko.
- Role kasir dan pemilik toko.
- Notifikasi WhatsApp otomatis.
- Notifikasi pelanggan saat antrean mendekati giliran.
- Foto produk.
- Kustomisasi tampilan toko.
- Riwayat pelanggan.

## Phase 3 — Advanced Platform

- Integrasi payment gateway.
- Pembayaran pesanan online.
- Multi-cabang.
- Analisis penjualan.
- Program loyalitas pelanggan.
- Diskon dan promo.
- Integrasi printer struk.
- Integrasi display antrean.
- Manajemen stok.

Fitur Phase 2 dan Phase 3 tidak boleh menghambat penyelesaian MVP.

---

# 13. ACCEPTANCE CRITERIA

Sistem dinyatakan memenuhi kebutuhan MVP apabila seluruh skenario berikut berhasil.

## A. NFC & QR

- Admin dapat membuat kartu baru.
- Admin dapat menghubungkan kartu dengan toko.
- Sistem menghasilkan URL unik.
- Sistem menghasilkan QR Code dari URL yang sama.
- NFC dan QR membuka toko yang sama.
- Kartu yang dinonaktifkan tidak dapat mengambil antrean.

## B. Customer

- Pelanggan dapat membuka halaman tanpa login.
- Pelanggan dapat mengambil antrean.
- Nomor antrean dibuat otomatis.
- Nama dan WhatsApp boleh kosong.
- Pelanggan dapat melihat status antrean.
- Pelanggan dapat membatalkan antrean sesuai aturan.
- Pelanggan dapat melakukan pemesanan jika fitur aktif.
- Pelanggan tidak dapat memesan produk nonaktif.
- Pelanggan dapat melihat detail pesanannya.

## C. Merchant

- Penjual dapat login.
- Penjual hanya melihat data tokonya.
- Penjual dapat mengelola menu.
- Penjual dapat mengaktifkan dan menonaktifkan menu.
- Penjual dapat memanggil antrean.
- Penjual dapat melewati antrean.
- Penjual dapat membatalkan antrean.
- Penjual dapat menyelesaikan antrean.
- Penjual dapat menambahkan antrean manual.
- Penjual dapat melihat detail pesanan.
- Penjual dapat melihat laporan harian.

## D. Real-Time

- Ketika antrean dipanggil, pelanggan mendapat pembaruan.
- Ketika antrean selesai, pelanggan mendapat pembaruan.
- Estimasi waktu berubah mengikuti kondisi antrean.
- Pembaruan tidak memerlukan refresh manual.

## E. Data Integrity

- Nomor antrean tidak boleh duplikat.
- Dua pelanggan yang mengambil antrean bersamaan mendapatkan nomor berbeda.
- Pesanan lama tidak berubah ketika harga menu diperbarui.
- Data toko tidak dapat diakses toko lain.
- Reset tidak menghapus transaksi sebelumnya.
- Pendapatan tidak menghitung pesanan yang belum selesai atau dibatalkan.

---

# 14. EDGE CASES & ERROR HANDLING

AI Agent wajib menangani skenario berikut:

1. Pelanggan melakukan tap NFC berulang kali.
2. Pelanggan menekan tombol ambil antrean berkali-kali.
3. Dua pelanggan mengambil antrean pada waktu bersamaan.
4. Kartu NFC tidak terdaftar.
5. Kartu NFC sudah dinonaktifkan.
6. Toko sedang tidak aktif.
7. Toko menonaktifkan pemesanan ketika pelanggan sedang membuka halaman menu.
8. Produk menjadi nonaktif ketika pelanggan sedang mengisi keranjang.
9. Penjual dan pelanggan mengubah data antrean hampir bersamaan.
10. Koneksi internet pelanggan terputus.
11. Koneksi internet penjual terputus.
12. Penjual menutup browser ketika antrean sedang dilayani.
13. Pelanggan membuka kembali link antrean setelah beberapa jam.
14. Penjual melakukan reset ketika masih terdapat antrean aktif.
15. Pesanan dibatalkan setelah pelanggan melakukan pemesanan.
16. Pelanggan tidak mengisi nama maupun nomor WhatsApp.

Setiap error harus menghasilkan pesan yang mudah dipahami pengguna, bukan error teknis mentah.

---

# 15. SECURITY & PRIVACY REQUIREMENTS

1. Setiap toko harus terisolasi.
2. Endpoint publik tidak boleh membuka seluruh data pelanggan.
3. Pelanggan harus memiliki token akses antrean yang aman dan tidak mudah ditebak.
4. Token akses antrean hanya boleh memberikan akses ke data antrean terkait.
5. Nomor WhatsApp tidak boleh ditampilkan kepada pelanggan lain.
6. Endpoint perubahan antrean harus dilindungi autentikasi penjual.
7. Endpoint super admin harus dilindungi role khusus.
8. Semua input harus divalidasi.
9. Gunakan proteksi rate limiting.
10. Gunakan HTTPS pada production.
11. Catat aktivitas administratif penting.
12. Sediakan kebijakan privasi dan informasi penggunaan data pelanggan.

---

# 16. IMPORTANT PRODUCT DECISIONS

Beberapa keputusan berikut harus diperhatikan ketika implementasi.

**1. NFC bukan alat pembayaran.**

NFC digunakan sebagai media untuk membuka URL toko. Pembayaran tetap dilakukan secara langsung kepada pedagang pada versi MVP.

**2. Barcode merupakan alternatif NFC.**

Barcode harus memiliki URL yang sama dengan kartu NFC.

**3. Pelanggan tidak wajib membuat akun.**

Pelanggan hanya memerlukan tautan akses antrean untuk memantau statusnya.

**4. Satu platform untuk banyak toko.**

Jangan membangun sistem terpisah untuk setiap pedagang.

**5. Pemesanan dapat dinonaktifkan.**

Pedagang dapat memilih hanya menggunakan antrean atau antrean sekaligus pemesanan.

**6. Riwayat harus tetap tersimpan.**

Reset antrean bukan berarti menghapus data transaksi.

**7. Real-time merupakan fitur utama.**

Pelanggan harus mendapatkan pembaruan status tanpa harus melakukan refresh manual.

**8. Penjual tetap dapat melayani pelanggan tanpa smartphone.**

Fitur tambah antrean manual wajib tersedia.

---

# 17. OPEN QUESTIONS / DECISIONS FOR FUTURE DEVELOPMENT

Hal-hal berikut belum ditentukan secara final dan tidak boleh dibuat sebagai asumsi bisnis yang sudah pasti:

1. Apakah kartu NFC dijual satu kali atau termasuk biaya langganan?
2. Apakah pedagang harus membayar biaya bulanan?
3. Apakah setiap toko bisa memiliki lebih dari satu kartu?
4. Apakah satu toko bisa memiliki beberapa petugas/kasir?
5. Apakah sistem perlu mendukung beberapa loket pelayanan?
6. Apakah pelanggan yang membatalkan antrean dapat mengambil antrean baru?
7. Apakah pesanan harus dibayar sebelum diproses atau setelah dilayani?
8. Apakah sistem membutuhkan notifikasi WhatsApp otomatis?
9. Apakah ada batas maksimal antrean?
10. Apakah ada batas pemakaian berdasarkan paket langganan?

Untuk MVP, gunakan keputusan sementara berikut:

- Satu toko minimal memiliki satu kartu aktif.
- Satu toko menggunakan satu antrean utama.
- Tidak ada pembayaran online.
- Tidak ada langganan berbayar yang diwajibkan.
- Tidak ada notifikasi WhatsApp otomatis.
- Pelanggan tidak wajib membuat akun.
- Penjual tetap dapat menambahkan pelanggan secara manual.

---

# 18. INSTRUCTIONS FOR AI AGENT

AI Agent bertindak sebagai software engineer yang bertanggung jawab membangun aplikasi berdasarkan PRD ini.

## 18.1. General Instructions

1. Baca dan pahami seluruh PRD sebelum menulis kode.
2. Jangan langsung membuat seluruh sistem dalam satu langkah.
3. Buat struktur proyek yang rapi dan scalable.
4. Gunakan clean code dan pemisahan tanggung jawab.
5. Terapkan validasi backend.
6. Gunakan database migration.
7. Terapkan relasi database dengan benar.
8. Gunakan autentikasi dan otorisasi yang aman.
9. Terapkan multi-tenant isolation sejak awal.
10. Buat desain responsive mobile-first.
11. Gunakan komponen frontend yang reusable.
12. Jangan menggunakan data dummy sebagai pengganti fitur backend yang seharusnya sudah berfungsi.
13. Jangan membuat tombol yang hanya tampil tetapi tidak memiliki fungsi.
14. Jangan menghapus atau mengganti fitur yang sudah berhasil tanpa alasan.
15. Jangan menganggap suatu fitur sudah selesai sebelum diuji.

## 18.2. Development Workflow

Kerjakan pengembangan dalam tahapan berikut:

**Tahap 1 — Project Foundation**

- Inisialisasi proyek.
- Konfigurasi database.
- Struktur backend dan frontend.
- Konfigurasi environment.
- Desain database.
- Migration dan model.
- Seed data untuk testing.

**Tahap 2 — Authentication & Multi-Tenant**

- Login super admin.
- Login penjual.
- Role dan permission.
- Isolasi data toko.
- Middleware dan authorization.

**Tahap 3 — NFC & QR Management**

- Generate token.
- Manajemen kartu.
- Assign kartu ke toko.
- Generate QR.
- Validasi kartu.

**Tahap 4 — Customer Application**

- Halaman toko.
- Informasi antrean.
- Pengambilan antrean.
- Identitas pelanggan.
- Pemesanan.
- Keranjang.
- Konfirmasi pesanan.
- Status antrean.

**Tahap 5 — Merchant Dashboard**

- Dashboard.
- Profil toko.
- Manajemen produk.
- Manajemen antrean.
- Detail pesanan.
- Input manual.
- Rekapitulasi.

**Tahap 6 — Real-Time**

- Broadcasting.
- WebSocket.
- Update status.
- Update estimasi waktu.
- Fallback polling.

**Tahap 7 — Reporting**

- Rekapitulasi.
- Export PDF.
- Export CSV.
- Riwayat transaksi.

**Tahap 8 — Testing & Deployment**

- Unit testing.
- Feature testing.
- API testing.
- Security testing.
- Responsive testing.
- Production configuration.
- Deployment documentation.

## 18.3. Mandatory Rules

- Setiap tahap harus menghasilkan kode yang dapat dijalankan.
- Jangan melanjutkan tahap berikutnya jika tahap sebelumnya masih mengalami error kritis.
- Buat dokumentasi instalasi dan konfigurasi.
- Buat `.env.example`.
- Jangan menyimpan credential dalam repository.
- Berikan instruksi menjalankan aplikasi.
- Buat testing untuk proses antrean yang bersamaan.
- Pastikan perhitungan harga dilakukan di backend.
- Pastikan transaksi dan antrean menggunakan database transaction jika diperlukan.
- Jangan mengimplementasikan fitur pembayaran online tanpa persetujuan pengembangan.
- Jangan mengimplementasikan sistem langganan tanpa persetujuan pengembangan.

## 18.4. Expected Deliverables

AI Agent harus menghasilkan:

1. Source code backend.
2. Source code frontend.
3. Database migrations.
4. Database seeders.
5. API documentation.
6. Dokumentasi instalasi.
7. Dokumentasi konfigurasi NFC dan QR.
8. Testing untuk fitur utama.
9. Dokumentasi deployment.
10. Akun demo super admin dan penjual melalui seeder development.
11. Laporan fitur yang sudah selesai dan belum selesai.

---

# 19. FINAL DEFINITION OF DONE

MVP dianggap selesai ketika:

- Super admin dapat membuat toko.
- Super admin dapat membuat dan menghubungkan kartu NFC.
- QR Code dapat diunduh.
- Pelanggan dapat membuka toko melalui NFC dan QR.
- Pelanggan dapat mengambil antrean.
- Pelanggan dapat memesan produk.
- Penjual dapat mengelola menu.
- Penjual dapat mengelola antrean.
- Penjual dapat memasukkan antrean secara manual.
- Status antrean diperbarui secara real-time.
- Penjual dapat melihat rekapitulasi harian.
- Data setiap toko terisolasi.
- Sistem dapat digunakan dari smartphone dan desktop.
- Semua fitur utama telah melewati pengujian.

**END OF PRODUCT REQUIREMENTS DOCUMENT**

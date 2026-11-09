# Admin dan staff LOOPLY

## Aktifkan database lama

Perubahan database diterapkan otomatis saat halaman login atau halaman aplikasi dibuka. Sebelum mengangkat akun admin pertama, buka aplikasi sekali agar migrasi menambahkan role `admin`/`staff`, status moderasi, tabel laporan, dan audit log.

## Jadikan akun sebagai admin pertama

1. Daftarkan akun biasa melalui aplikasi dan login sekali.
2. Di phpMyAdmin, pilih database `looply`, buka tab **SQL**, lalu jalankan query berikut dengan email akun tersebut:

```sql
UPDATE users
SET role = 'admin'
WHERE email = 'email-kampus@example.com';
```

3. Login kembali. Dashboard admin tersedia melalui menu **Dashboard**.

Jangan menyediakan pilihan admin/staff pada form pendaftaran umum. Admin tidak dapat diangkat dari dashboard; admin pertama harus dibuat langsung oleh pemilik database.

## Hak akses

- **Admin:** melihat, mencari, dan memfilter semua transaksi; mengubah status transaksi dengan alasan; mengangkat atau mencabut staff; meninjau laporan; menonaktifkan/mengaktifkan akun; menyembunyikan/memulihkan produk; melihat audit log.
- **Staff:** memproses laporan, menambahkan catatan, menandai status laporan, serta menangguhkan akun atau menyembunyikan produk yang terkait laporan terbuka yang ditugaskan kepadanya. Staff tidak dapat melihat/mengubah semua transaksi, mengelola role, atau memulihkan akun/produk.
- **Pengguna:** melaporkan akun, listing, atau transaksi yang melibatkan mereka. Form laporan memerlukan alasan dan keterangan.

Setiap perubahan transaksi mengharuskan alasan, memperbarui status reservasi produk, memberi notifikasi kepada buyer dan seller, serta dicatat di audit log. Perubahan akses dan moderasi juga dicatat.

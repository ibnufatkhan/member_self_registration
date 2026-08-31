# Member Self Registration v2.1.1 (PHP 8.3)

Plugin SLiMS untuk pendaftaran anggota secara mandiri (daftar online). Versi ini disesuaikan agar berjalan di **PHP 8.3** sesuai prasyarat SLiMS 9 Bulian terbaru.

Plugin ini merupakan pengembangan dari karya asli [Drajat Hasan](https://github.com/drajathasan/member_self_registration).

## Kredit

- **Drajat Hasan** — pengembang asli plugin
- **Ibnufatkhan** — port dan penyesuaian kompatibilitas PHP 8.3

## Prasyarat

1. SLiMS 9.6.1 atau lebih baru
2. PHP 8.3 atau lebih baru

## Cara pasang

1. Unduh source code dari repositori ini
2. Ekstrak plugin ini pada folder `plugins/` dengan nama folder `member_self_registration`
3. Aktifkan plugin pada modul sistem menu plugin

## Perubahan utama PHP 8.3

- Menghapus polyfill PHP < 5.4 yang tidak lagi relevan
- Mengganti `array_pop()` pada nilai sementara (deprecated/warning di PHP 8)
- Mengganti `strpos() == true` dengan `str_contains()`
- Menggunakan `json_validate()` (fitur PHP 8.3) saat impor skema
- Penanganan null yang lebih ketat pada hasil `fetchObject()` dan `json_decode()`
- Reflection property diakses lewat nama properti, bukan `array_pop()` pada daftar properti privat
- v2.1.1: memperbaiki error tabel pendaftaran tidak ditemukan jika nama skema lebih dari 32 karakter (nama di database terpotong, sementara tabel fisik memakai nama lengkap)

## Disclaimer

Ikuti tutorial penjelasan dan pemasangan secara detail di channel youtube pengembang asli [di sini](https://www.youtube.com/c/MafriaTechEdu).

# Daftar akun warga dengan Google (v23)

Di halaman "Buat akun warga" ada tombol **Daftar dengan Google**, dan di halaman Masuk ada **Masuk dengan Google**
supaya warga yang mendaftar lewat Google bisa masuk lagi. Akun demo ("Coba langsung sebagai") tetap seperti sebelumnya.

## Cara mengaktifkan
Tombol Google sudah tampil, tetapi baru berfungsi setelah langkah berikut:

1. Buka https://console.cloud.google.com/ dan buat project (misalnya "SEA WARNING").
2. Menu **APIs & Services > OAuth consent screen**: pilih *External*, isi nama aplikasi, email dukungan, dan email developer.
   Selama status *Testing*, tambahkan email Google yang boleh mencoba di bagian *Test users*.
3. Menu **APIs & Services > Credentials > Create credentials > OAuth client ID**:
   - Application type: **Web application**
   - Authorized redirect URIs: alamat berkas `google.php`, sama persis, misalnya
     `http://localhost/sea_warning/google.php` (sesuaikan nama folder di htdocs).
4. Salin **Client ID** dan **Client secret** ke `config.php`:

```php
'google' => [
    'client_id'     => 'xxxxxxxx.apps.googleusercontent.com',
    'client_secret' => 'GOCSPX-xxxxxxxx',
    'redirect_uri'  => '',   // kosongkan, dibuat otomatis; isi bila alamatnya berbeda
    'cainfo'        => '',   // isi lokasi cacert.pem bila muncul galat sertifikat SSL
],
```

5. Pastikan `extension=curl` aktif di `php.ini` XAMPP, lalu restart Apache.

Akun yang masuk lewat Google otomatis menjadi **Warga**. Bila email Google sama dengan email akun yang sudah ada, akun itu yang dipakai.
Client secret jangan dibagikan atau diunggah ke tempat umum.

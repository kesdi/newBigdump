# Modern BigDump — PHP 8.x & MySQL 8.x Parçalı Yedek Yükleyici

Büyük boyutlu MySQL veritabanı yedeklerini (`.sql` ve `.sql.gz`) sunucunun `max_execution_time` veya `memory_limit` kısıtlamalarına takılmadan, tarayıcı üzerinden parça parça (staggered) içe aktarmak için modernize edilmiş tek dosyalık PHP aracıdır.

Klasik BigDump 0.36b mantığı üzerine inşa edilmiş; **PHP 8.1+ uyumluluğu**, **UTF8MB4 desteği**, **Fetch JSON API ilerleme çubuğu** ve **güvenlik korumaları** ile yeniden yazılmıştır.

---

## Özellikler

- **PHP 8.x Tam Uyumluluk:** `strict_types`, null güvenliği ve güncel `mysqli` API'si ile hatasız çalışır.
- **Zaman Aşımı (Timeout) Koruması:** Dosyayı tek seferde RAM'e yüklemez; `fseek` / `gzseek` kullanarak belirlenen satır grupları halinde işler ve her adımda kendini otomatik tetikler.
- **Sıkıştırılmış Dosya Desteği:** `.sql` dosyalarının yanı sıra `.sql.gz` (GZip) arşivlerini sunucuda açmaya gerek kalmadan doğrudan okur ve içeri aktarır.
- **UTF8MB4 & Modern MySQL Desteği:** Türkçe karakterler, özel semboller ve emojilerin bozulmaması için varsayılan olarak `utf8mb4` karakter setini kullanır.
- **Extended Inserts Desteği:** Güncel `mysqldump` çıktılarındaki uzun tek satır `INSERT` bloklarını bölmeden işler.
- **Güvenlik Sertleştirmesi:**
  - Şifreli erişim ekranı (`$access_password` ile sabit zamanlı `hash_equals` doğrulaması)
  - CSRF korumalı dosya yükleme ve silme işlemleri
  - Directory Traversal (üst dizin erişimi) engeli
- **Canlı İlerleme Takibi:** Modern Fetch API ve JSON altyapısı ile işlenen satır, çalıştırılan sorgu sayısı ve yüzdelik ilerleme çubuğu.

---

## Gereksinimler

- **PHP:** 8.0, 8.1, 8.2 veya 8.3+
- **PHP Eklentileri:** `mysqli`, `zlib` (`.gz` dosyaları için), `mbstring`
- **Veritabanı:** MySQL 5.7+ veya MySQL 8.x / MariaDB 10.x+

---

## Kurulum ve Kullanım

1. **Dosyayı Yapılandırın:**
   `bigdump.php` dosyasını bir metin editörü ile açın ve en üstteki ayarlar bölümünü kendi sunucu bilgilerinize göre düzenleyin:

   ```php
   $access_password       = 'CokGucluBirSifre123!'; // Panele giriş şifresi (Mutlaka değiştirin!)

   $db_server             = 'localhost';
   $db_name               = 'veritabani_adi';$db_username           = 'kullanici_adi';
   $db_password           = 'veritabani_sifresi';$db_connection_charset = 'utf8mb4';

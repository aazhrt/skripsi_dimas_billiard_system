# Panduan Remote Version Control & Deployment - Billiard System

Aplikasi ini sudah terhubung secara seamless antara **Laptop lokal**, **GitHub**, dan **Server Production**.

---

## 1. Konfigurasi Remote Git

Di laptop Anda (`/Users/azhr/orca/projects/billiard-system`), telah terkonfigurasi 2 remote:
- **`origin`** : `https://github.com/aazhrt/skripsi_dimas_billiard_system.git` (Repository GitHub Anda)
- **`server`** : `ssh://root@43.134.89.165/root/skripsi_dimas_billiard_system` (Server Production)

Branch aktif: **`production`** (kode live yang berjalan di server).

---

## 2. Cara Kerja / Alur Development

### A. Deploy Otomatis (1 Perintah)
Setelah Anda melakukan perubahan kode di laptop dan melakukan commit:
```bash
./deploy.sh
```
Perintah ini otomatis:
1. Melakukan `git push origin <branch>` (Backup & Sync ke GitHub).
2. Melakukan `git push server <branch>` (Update kode di server secara instan).
3. Menjalankan auto-hook di server:
   - `php artisan optimize:clear` (membersihkan cache route/view/config).
   - Restart background workers (`billiard-queue` dan `billiard-reverb`).

### B. Opsi Perintah Tambahan di `./deploy.sh`

| Perintah | Deskripsi |
|---|---|
| `./deploy.sh` | Deploy kode terbaru ke GitHub & Server |
| `./deploy.sh --status` | Cek status Git server & status container Docker |
| `./deploy.sh --migrate` | Jalankan `php artisan migrate --force` di server |
| `./deploy.sh --artisan <command>` | Eksekusi artisan command apa pun (contoh: `./deploy.sh --artisan "route:list"`) |
| `./deploy.sh --logs [container]` | Pantau live logs container (contoh: `./deploy.sh --logs billiard-php`) |

---

## 3. Deployment Manual via Git Langsung

Jika ingin push manual tanpa script:
```bash
# Push ke server langsung:
git push server production

# Push ke GitHub:
git push origin production
```
*(Server sudah dilengkapi Git post-receive hook yang otomatis memperbarui working directory dan membersihkan cache aplikasi).*

---

## 4. Akses Aplikasi
- Domain Web: [https://billiard-system.azhr.cloud](https://billiard-system.azhr.cloud)
- Server Host: `ssh root@43.134.89.165`
- Path Server: `/root/skripsi_dimas_billiard_system`

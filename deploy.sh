#!/usr/bin/env bash
# ==============================================================================
# Remote Deployment & Management Script for Billiard System
# Server: root@43.134.89.165
# ==============================================================================

set -e

SERVER_HOST="root@43.134.89.165"
SERVER_PATH="/root/skripsi_dimas_billiard_system"
CURRENT_BRANCH=$(git branch --show-current)

# Helper function
info() { echo -e "\033[1;34m[INFO]\033[0m $1"; }
success() { echo -e "\033[1;32m[SUCCESS]\033[0m $1"; }
warn() { echo -e "\033[1;33m[WARN]\033[0m $1"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $1"; exit 1; }

case "$1" in
    --status|-s)
        info "Checking remote server status..."
        ssh "$SERVER_HOST" "cd $SERVER_PATH && git status -s && echo '--- DOCKER CONTAINERS ---' && docker ps --filter name=billiard"
        exit 0
        ;;
    --logs|-l)
        TARGET="${2:-billiard-php}"
        info "Streaming logs from container: $TARGET (Ctrl+C to exit)..."
        ssh -t "$SERVER_HOST" "docker logs -f --tail 100 $TARGET"
        exit 0
        ;;
    --artisan|-a)
        shift
        CMD="$*"
        if [ -z "$CMD" ]; then
            error "Tentukan perintah artisan. Contoh: ./deploy.sh --artisan migrate:status"
        fi
        info "Running 'php artisan $CMD' on server..."
        ssh "$SERVER_HOST" "docker exec billiard-php php artisan $CMD"
        exit 0
        ;;
    --migrate|-m)
        info "Running migrations on server..."
        ssh "$SERVER_HOST" "docker exec billiard-php php artisan migrate --force"
        success "Migration completed."
        exit 0
        ;;
    --help|-h)
        echo "Usage: ./deploy.sh [OPTIONS]"
        echo ""
        echo "Deploy workflow:"
        echo "  ./deploy.sh                Push branch aktif ke GitHub & Server, lalu deploy"
        echo "  ./deploy.sh --migrate      Jalankan php artisan migrate di server"
        echo "  ./deploy.sh --status       Cek status git dan docker container di server"
        echo "  ./deploy.sh --logs [name]  Lihat live log container (default: billiard-php)"
        echo "  ./deploy.sh --artisan <cmd> Jalankan perintah artisan di container server"
        exit 0
        ;;
esac

info "Deploying branch: \033[1;35m$CURRENT_BRANCH\033[0m"

# 1. Pastikan perubahan lokal sudah di-commit
if ! git diff-index --quiet HEAD --; then
    warn "Ada perubahan lokal yang belum di-commit!"
    git status -s
    read -p "Apakah Anda ingin melanjutkan deploy tanpa commit perubahan uncommitted? (y/N) " confirm
    if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
        error "Deploy dibatalkan. Silakan commit perubahan Anda terlebih dahulu."
    fi
fi

# 2. Push ke GitHub (origin)
info "1/3 Pushing to GitHub (origin/$CURRENT_BRANCH)..."
git push origin "$CURRENT_BRANCH" || warn "Gagal push ke GitHub origin. Melanjutkan ke server..."

# 3. Push ke Server (server/$CURRENT_BRANCH)
info "2/3 Pushing to Server (server/$CURRENT_BRANCH)..."
git push server "$CURRENT_BRANCH"

# 4. Trigger reload & cache clear di server
info "3/3 Optimizing cache & restarting workers on server..."
ssh "$SERVER_HOST" "docker exec billiard-php php artisan optimize:clear && docker restart billiard-queue billiard-reverb >/dev/null 2>&1"

success "Deployment selesai! Aplikasi berjalan di https://billiard-system.azhr.cloud"

# Production Deployment, Upgrade & Recovery Runbook

## 1. Initial Mac Pro Provisioning
1. Run `./scripts/preflight_macpro.sh` to verify `/` vs. remaining SSD partition space, `avx` flags, and `macfanctld`.
2. Run `sudo ./scripts/setup_macpro_host.sh` to enforce `10m × 3` Docker log caps and thermal control.
3. Configure `.env` from `.env.example` with strong generated secrets (`APP_KEY`, `POSTGRES_PASSWORD`, `REDIS_PASSWORD`, `QDRANT_API_KEY`, `RAG_INTERNAL_SECRET`, `RAG_CALLBACK_SECRET`, `GEMINI_API_KEY`).

## 2. Deploy / Upgrade
```bash
git pull --ff-only
docker compose up -d --build
docker exec -it rag-ollama ollama pull nomic-embed-text
docker exec -it rag-web-app php artisan migrate --force
docker exec -it rag-web-app php artisan optimize
```

## 3. Rollback
1. Check out the previous known-good Git tag/commit (`git checkout <tag>`).
2. Roll back database migrations if schema changed (`docker exec -it rag-web-app php artisan migrate:rollback --step=1`).
3. Rebuild and restart containers (`docker compose up -d --build`).
4. For prompt/model regressions without code changes, use **Admin → Settings → Version History → Rollback**.

## 4. Nightly Backup & Clean-Machine Restore Drill
- Nightly backup: `./scripts/backup.sh` (configure `BACKUP_OFFBOX_DEST` for off-box `rsync`).
- Restore verification (required before pilot sign-off per SRS §9):
  ```bash
  ./scripts/restore.sh /path/to/backups/<TIMESTAMP>
  ```

## 5. Pilot Acceptance Gate (SRS §6.1 & §9)
1. Run `python benchmarks/run_golden_eval.py` (≥100 cases, ≥20 unanswerable) and verify:
   - Recall@5 ≥ 90%
   - Grounded citation support ≥ 95%
   - Unanswerable no-answer accuracy ≥ 90%
2. Run 20-concurrent 30-minute soak test:
   ```bash
   python benchmarks/soak_test.py --base-url https://localhost --token "$API_KEY" --concurrency 20 --duration-seconds 1800 --insecure
   ```
   Verify error rate < 1%, TTFT p95 < 2.5 s, and full answer p95 < 8 s.

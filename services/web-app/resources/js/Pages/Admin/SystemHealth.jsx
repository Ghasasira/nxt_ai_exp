import { Head } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';

export default function SystemHealth({ telemetry }) {
    const services = [
        { name: 'PostgreSQL', ok: telemetry.postgres_ok },
        { name: 'Redis', ok: telemetry.redis_ok },
        { name: 'rag-core / Qdrant / Ollama', ok: telemetry.rag_core?.ok },
    ];

    return (
        <AppLayout title="System Health">
            <Head title="System Health" />
            <div className="grid gap-4 sm:grid-cols-3">
                {services.map((s) => (
                    <div key={s.name} className="rounded-xl border border-slate-800 bg-slate-900 p-4 flex items-center justify-between">
                        <span className="text-sm font-medium text-white">{s.name}</span>
                        <span
                            className={`rounded-full px-2.5 py-0.5 text-xs ${
                                s.ok ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'
                            }`}
                        >
                            {s.ok ? 'Healthy' : 'Unavailable'}
                        </span>
                    </div>
                ))}
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div className="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <div className="text-xs text-slate-400">Active Queue Depth</div>
                    <div className="mt-1 text-2xl font-semibold text-white">{telemetry.queue_depth}</div>
                </div>
                <div className="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <div className="text-xs text-slate-400">Failed Ingestion Jobs</div>
                    <div className="mt-1 text-2xl font-semibold text-rose-300">{telemetry.failed_jobs}</div>
                </div>
                <div className="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <div className="text-xs text-slate-400">Disk Usage</div>
                    <div className="mt-1 text-2xl font-semibold text-white">
                        {telemetry.disk_used_gb} / {telemetry.disk_total_gb} GB
                    </div>
                </div>
                <div className="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <div className="text-xs text-slate-400">CPU Thermal Sensor</div>
                    <div className="mt-1 text-2xl font-semibold text-white">
                        {telemetry.cpu_temp_c !== null ? `${telemetry.cpu_temp_c}°C` : 'N/A (container)'}
                    </div>
                </div>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 className="text-sm font-semibold text-white">Last Nightly Backup</h2>
                {telemetry.last_backup ? (
                    <div className="mt-2 grid gap-3 text-xs sm:grid-cols-3">
                        <div>
                            <span className="text-slate-400">Timestamp: </span>
                            <span className="text-white">{telemetry.last_backup.timestamp}</span>
                        </div>
                        <div>
                            <span className="text-slate-400">Size: </span>
                            <span className="text-white">
                                {(Number(telemetry.last_backup.size_bytes || 0) / 1_048_576).toFixed(2)} MB
                            </span>
                        </div>
                        <div>
                            <span className="text-slate-400">Off-box Synced: </span>
                            <span className={telemetry.last_backup.offbox_synced ? 'text-emerald-300' : 'text-amber-300'}>
                                {telemetry.last_backup.offbox_synced ? 'Yes' : 'Local Only'}
                            </span>
                        </div>
                    </div>
                ) : (
                    <p className="mt-1 text-xs text-slate-400">No backup marker found yet. Run scripts/backup.sh.</p>
                )}
            </div>
        </AppLayout>
    );
}

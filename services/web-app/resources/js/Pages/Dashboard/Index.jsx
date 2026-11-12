import { Head, Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

export default function DashboardIndex({ stats, recentFailures, docGaps }) {
    const kpis = [
        { label: 'Queries (30d)', value: stats.total_queries_30d, sub: `${stats.today_queries} today` },
        { label: 'No-Answer Rate', value: `${stats.no_answer_rate}%`, sub: 'Short-circuited before LLM' },
        { label: 'Avg Latency', value: `${stats.avg_latency_ms} ms`, sub: 'End-to-end p50/avg' },
        {
            label: 'Month Spend',
            value: `$${stats.month_cost_usd.toFixed(2)}`,
            sub: `${stats.budget_pct}% of $${stats.spend_cap_usd} cap (${stats.month_tokens.toLocaleString()} tok)`,
        },
    ];

    return (
        <AppLayout title="Operations Dashboard">
            <Head title="Dashboard" />
            {stats.budget_pct >= 80 && (
                <div className="flex items-center gap-2 rounded-xl border border-amber-500/40 bg-amber-950/50 px-4 py-3 text-sm text-amber-200">
                    <AlertTriangle className="h-4 w-4 shrink-0" />
                    <span>
                        Monthly spend has reached {stats.budget_pct}% of the ${stats.spend_cap_usd} cap (FR-ADM-02 alert).
                    </span>
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {kpis.map((k) => (
                    <div key={k.label} className="rounded-xl border border-slate-800 bg-slate-900 p-4">
                        <div className="text-xs text-slate-400">{k.label}</div>
                        <div className="mt-1 text-2xl font-semibold text-white">{k.value}</div>
                        <div className="mt-1 text-xs text-slate-500">{k.sub}</div>
                    </div>
                ))}
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <section className="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-white">Recent Ingestion Failures</h2>
                        <Link href="/ingestion" className="text-xs text-indigo-400 hover:underline">
                            View Queue
                        </Link>
                    </div>
                    {recentFailures.length === 0 ? (
                        <p className="text-xs text-slate-500">No recent ingestion failures.</p>
                    ) : (
                        <ul className="space-y-2 text-xs">
                            {recentFailures.map((job) => (
                                <li key={job.id} className="rounded-lg border border-rose-500/20 bg-slate-950 p-3">
                                    <div className="font-medium text-rose-300">{job.document?.title || job.document_id}</div>
                                    <div className="mt-1 text-slate-400">{job.error_message}</div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 className="mb-3 text-sm font-semibold text-white">Documentation Gaps (Unanswered Queries)</h2>
                    {docGaps.length === 0 ? (
                        <p className="text-xs text-slate-500">No unanswered query clusters recorded yet.</p>
                    ) : (
                        <ul className="space-y-2 text-xs">
                            {docGaps.map((gap, i) => (
                                <li key={i} className="flex items-center justify-between rounded-lg bg-slate-950 px-3 py-2">
                                    <span className="truncate text-slate-200 pr-3">{gap.query_text}</span>
                                    <span className="shrink-0 rounded bg-slate-800 px-2 py-0.5 text-slate-400">
                                        {gap.occurrences}×
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

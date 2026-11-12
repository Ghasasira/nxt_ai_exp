import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';

export default function AdminSettings({ runtime, monthlySpendCapUsd, retentionDays, promptVersions }) {
    const form = useForm({
        model_id: runtime.model_id || 'gemini-3.1-flash-lite',
        temperature: runtime.temperature ?? 0.0,
        thinking_budget: runtime.thinking_budget ?? 0,
        top_k: runtime.top_k ?? 20,
        similarity_threshold: runtime.similarity_threshold ?? 0.35,
        reranker_enabled: Boolean(runtime.reranker_enabled),
        monthly_spend_cap_usd: monthlySpendCapUsd ?? 200,
        retention_days: retentionDays ?? 90,
    });

    return (
        <AppLayout title="Model & Retrieval Settings">
            <Head title="Settings" />
            <div className="grid gap-6 lg:grid-cols-2">
                <section className="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 className="mb-4 text-sm font-semibold text-white">Runtime Configuration</h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put('/admin/settings');
                        }}
                        className="space-y-3 text-sm"
                    >
                        <div>
                            <label className="mb-1 block text-xs text-slate-400">Gemini Model ID</label>
                            <input
                                type="text"
                                value={form.data.model_id}
                                onChange={(e) => form.setData('model_id', e.target.value)}
                                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="mb-1 block text-xs text-slate-400">Temperature</label>
                                <input
                                    type="number"
                                    step="0.05"
                                    min="0"
                                    max="1"
                                    value={form.data.temperature}
                                    onChange={(e) => form.setData('temperature', Number(e.target.value))}
                                    className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-slate-400">Thinking Budget (0 = off)</label>
                                <input
                                    type="number"
                                    min="0"
                                    max="8192"
                                    value={form.data.thinking_budget}
                                    onChange={(e) => form.setData('thinking_budget', Number(e.target.value))}
                                    className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-slate-400">Top-K Candidates</label>
                                <input
                                    type="number"
                                    min="1"
                                    max="50"
                                    value={form.data.top_k}
                                    onChange={(e) => form.setData('top_k', Number(e.target.value))}
                                    className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-slate-400">Similarity Threshold</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max="1"
                                    value={form.data.similarity_threshold}
                                    onChange={(e) => form.setData('similarity_threshold', Number(e.target.value))}
                                    className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-slate-400">Monthly Spend Cap ($)</label>
                                <input
                                    type="number"
                                    step="1"
                                    min="0"
                                    value={form.data.monthly_spend_cap_usd}
                                    onChange={(e) => form.setData('monthly_spend_cap_usd', Number(e.target.value))}
                                    className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-slate-400">Query Log Retention (days)</label>
                                <input
                                    type="number"
                                    min="1"
                                    max="3650"
                                    value={form.data.retention_days}
                                    onChange={(e) => form.setData('retention_days', Number(e.target.value))}
                                    className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                                />
                            </div>
                        </div>
                        <label className="flex items-center gap-2 text-xs text-slate-300">
                            <input
                                type="checkbox"
                                checked={form.data.reranker_enabled}
                                onChange={(e) => form.setData('reranker_enabled', e.target.checked)}
                            />
                            Enable CPU cross-encoder reranker
                        </label>
                        <div className="flex items-center justify-between pt-2">
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                            >
                                Save & Snapshot Version
                            </button>
                            <button
                                type="button"
                                onClick={() => router.post('/admin/settings/purge-logs')}
                                className="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300 hover:bg-slate-800"
                            >
                                Purge Expired Query Text
                            </button>
                        </div>
                    </form>
                </section>

                <section className="rounded-xl border border-slate-800 bg-slate-900 p-5 space-y-3">
                    <h2 className="text-sm font-semibold text-white">Version History & Rollback</h2>
                    <div className="space-y-2 text-xs">
                        {promptVersions.map((pv) => (
                            <div
                                key={pv.id}
                                className="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-950 px-3 py-2.5"
                            >
                                <div>
                                    <div className="font-medium text-white">
                                        {pv.version_label} {pv.is_active && <span className="text-emerald-400">(active)</span>}
                                    </div>
                                    <div className="text-slate-400">
                                        {pv.model_id} · top_k={pv.top_k} · thresh={pv.similarity_threshold} · think=
                                        {pv.thinking_budget}
                                    </div>
                                </div>
                                {!pv.is_active && (
                                    <button
                                        type="button"
                                        onClick={() => router.post(`/admin/settings/rollback/${pv.id}`)}
                                        className="rounded bg-slate-800 px-2.5 py-1 text-indigo-300 hover:bg-slate-700"
                                    >
                                        Rollback
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}

import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function QueryLogsIndex({ logs, filter, goldenSets }) {
    const [selectedSetId, setSelectedSetId] = useState(goldenSets[0]?.id || '');

    const addToGoldenSet = (log) => {
        if (!selectedSetId || !log.query_text) return;
        router.post('/evaluation/cases', {
            golden_set_id: selectedSetId,
            question: log.query_text,
            expected_answer: log.answer_text || '',
            answerable: log.mode !== 'no_answer',
        });
    };

    return (
        <AppLayout title="Query Logs & Feedback">
            <Head title="Query Logs" />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex gap-2 text-xs">
                    {[
                        { label: 'All', value: '' },
                        { label: 'Unanswered', value: 'unanswered' },
                        { label: 'Low Rated', value: 'low_rated' },
                    ].map((btn) => (
                        <button
                            key={btn.label}
                            type="button"
                            onClick={() => router.get('/query-logs', btn.value ? { filter: btn.value } : {})}
                            className={`rounded-lg px-3 py-1.5 ${
                                filter === btn.value ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300'
                            }`}
                        >
                            {btn.label}
                        </button>
                    ))}
                </div>
                {goldenSets.length > 0 && (
                    <div className="flex items-center gap-2 text-xs">
                        <span className="text-slate-400">Target Golden Set:</span>
                        <select
                            value={selectedSetId}
                            onChange={(e) => setSelectedSetId(e.target.value)}
                            className="rounded-lg border border-slate-700 bg-slate-900 px-2.5 py-1 text-white"
                        >
                            {goldenSets.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {g.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
            </div>

            <div className="space-y-3">
                {logs.data.map((log) => (
                    <div key={log.id} className="rounded-xl border border-slate-800 bg-slate-900 p-4 text-xs space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="font-semibold text-white">{log.query_text || '[redacted]'}</span>
                            <div className="flex items-center gap-2 text-slate-400">
                                <span>{log.mode}</span>
                                <span>· {log.latency_ms} ms</span>
                                <span>· ${Number(log.estimated_cost_usd).toFixed(5)}</span>
                                {goldenSets.length > 0 && log.query_text && (
                                    <button
                                        type="button"
                                        onClick={() => addToGoldenSet(log)}
                                        className="rounded bg-slate-800 px-2 py-0.5 text-indigo-300 hover:bg-slate-700"
                                    >
                                        + Golden Set
                                    </button>
                                )}
                            </div>
                        </div>
                        <p className="text-slate-300 line-clamp-3">{log.answer_text || '[redacted]'}</p>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}

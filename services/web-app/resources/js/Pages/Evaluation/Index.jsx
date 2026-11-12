import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';

export default function EvaluationIndex({ goldenSets, evalRuns, documents }) {
    const setForm = useForm({ name: '', description: '' });
    const caseForm = useForm({
        golden_set_id: goldenSets[0]?.id || '',
        question: '',
        expected_answer: '',
        expected_document_ids: [],
        answerable: true,
    });

    return (
        <AppLayout title="Golden-Set Evaluation">
            <Head title="Evaluation" />
            <div className="grid gap-6 lg:grid-cols-2">
                <section className="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 className="mb-3 text-sm font-semibold text-white">Create Golden Set</h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            setForm.post('/evaluation/sets', { onSuccess: () => setForm.reset() });
                        }}
                        className="space-y-3 text-sm"
                    >
                        <input
                            type="text"
                            required
                            placeholder="Set name (e.g. HR Policies v1)"
                            value={setForm.data.name}
                            onChange={(e) => setForm.setData('name', e.target.value)}
                            className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                        />
                        <input
                            type="text"
                            placeholder="Description"
                            value={setForm.data.description}
                            onChange={(e) => setForm.setData('description', e.target.value)}
                            className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                        />
                        <button type="submit" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white">
                            Create Set
                        </button>
                    </form>
                </section>

                <section className="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 className="mb-3 text-sm font-semibold text-white">Add Golden Case</h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            caseForm.post('/evaluation/cases', { onSuccess: () => caseForm.reset('question', 'expected_answer') });
                        }}
                        className="space-y-3 text-sm"
                    >
                        <select
                            required
                            value={caseForm.data.golden_set_id}
                            onChange={(e) => caseForm.setData('golden_set_id', e.target.value)}
                            className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                        >
                            <option value="">Select golden set</option>
                            {goldenSets.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {g.name}
                                </option>
                            ))}
                        </select>
                        <input
                            type="text"
                            required
                            placeholder="Question"
                            value={caseForm.data.question}
                            onChange={(e) => caseForm.setData('question', e.target.value)}
                            className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                        />
                        <select
                            multiple
                            value={caseForm.data.expected_document_ids}
                            onChange={(e) =>
                                caseForm.setData(
                                    'expected_document_ids',
                                    Array.from(e.target.selectedOptions).map((o) => o.value)
                                )
                            }
                            className="h-20 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-1.5 text-xs text-white"
                        >
                            {documents.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.title}
                                </option>
                            ))}
                        </select>
                        <label className="flex items-center gap-2 text-xs text-slate-300">
                            <input
                                type="checkbox"
                                checked={caseForm.data.answerable}
                                onChange={(e) => caseForm.setData('answerable', e.target.checked)}
                            />
                            Answerable by documents (uncheck for no-answer trap test)
                        </label>
                        <button type="submit" className="rounded-lg bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">
                            Add Case
                        </button>
                    </form>
                </section>
            </div>

            <section className="rounded-xl border border-slate-800 bg-slate-900 p-5 space-y-3">
                <h2 className="text-sm font-semibold text-white">Golden Sets</h2>
                <div className="grid gap-3 md:grid-cols-2">
                    {goldenSets.map((g) => (
                        <div key={g.id} className="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-950 p-3">
                            <div>
                                <div className="text-sm font-medium text-white">{g.name}</div>
                                <div className="text-xs text-slate-400">{g.cases_count} test cases</div>
                            </div>
                            <button
                                type="button"
                                onClick={() => router.post(`/evaluation/sets/${g.id}/run`)}
                                className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500"
                            >
                                Run Evaluation
                            </button>
                        </div>
                    ))}
                </div>
            </section>

            <section className="rounded-xl border border-slate-800 bg-slate-900 p-5 overflow-x-auto">
                <h2 className="mb-3 text-sm font-semibold text-white">Evaluation Run Comparison</h2>
                <table className="w-full text-left text-xs">
                    <thead className="border-b border-slate-800 text-slate-400">
                        <tr>
                            <th className="py-2 pr-4">Golden Set</th>
                            <th className="py-2 pr-4">Model</th>
                            <th className="py-2 pr-4">Threshold</th>
                            <th className="py-2 pr-4">Recall@5 (≥90%)</th>
                            <th className="py-2 pr-4">Grounded (≥95%)</th>
                            <th className="py-2 pr-4">No-Answer (≥90%)</th>
                            <th className="py-2">Avg Latency</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800/70">
                        {evalRuns.map((run) => (
                            <tr key={run.id}>
                                <td className="py-2 pr-4 text-white">{run.golden_set?.name}</td>
                                <td className="py-2 pr-4 text-slate-300">{run.model_id}</td>
                                <td className="py-2 pr-4 text-slate-300">{run.similarity_threshold}</td>
                                <td className="py-2 pr-4 font-semibold text-emerald-300">{(run.recall_at_5 * 100).toFixed(1)}%</td>
                                <td className="py-2 pr-4 font-semibold text-indigo-300">{(run.grounded_rate * 100).toFixed(1)}%</td>
                                <td className="py-2 pr-4 text-slate-200">{(run.no_answer_accuracy * 100).toFixed(1)}%</td>
                                <td className="py-2 text-slate-400">{run.avg_latency_ms} ms</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}

import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function PlaygroundIndex({ collections }) {
    const [query, setQuery] = useState('');
    const [collectionId, setCollectionId] = useState('');
    const [topK, setTopK] = useState(10);
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);

    const runSearch = async (e) => {
        e.preventDefault();
        setLoading(true);
        try {
            const res = await fetch('/api/v1/search', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    query,
                    collection_ids: collectionId ? [collectionId] : null,
                    top_k: Number(topK),
                }),
            });
            const data = await res.json();
            setResults(data.results || []);
        } finally {
            setLoading(false);
        }
    };

    return (
        <AppLayout title="Retrieval Playground">
            <Head title="Retrieval Playground" />
            <div className="rounded-xl border border-slate-800 bg-slate-900 p-5 space-y-4">
                <p className="text-xs text-slate-400">
                    Test hybrid (dense + BM25 RRF) retrieval scores without calling the cloud LLM.
                </p>
                <form onSubmit={runSearch} className="grid gap-3 md:grid-cols-4">
                    <input
                        type="text"
                        required
                        placeholder="Enter test query..."
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white md:col-span-2"
                    />
                    <select
                        value={collectionId}
                        onChange={(e) => setCollectionId(e.target.value)}
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                    >
                        <option value="">All permitted collections</option>
                        {collections.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                    <div className="flex gap-2">
                        <input
                            type="number"
                            min={1}
                            max={50}
                            value={topK}
                            onChange={(e) => setTopK(e.target.value)}
                            className="w-20 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                        />
                        <button
                            type="submit"
                            disabled={loading}
                            className="flex-1 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                        >
                            {loading ? 'Searching...' : 'Search'}
                        </button>
                    </div>
                </form>

                <div className="space-y-3">
                    {results.map((r, i) => (
                        <div key={r.chunk_id} className="rounded-lg border border-slate-800 bg-slate-950 p-3 text-xs">
                            <div className="flex items-center justify-between text-indigo-300">
                                <span className="font-medium">
                                    #{i + 1} · {r.title} ({r.heading_path})
                                </span>
                                <span className="rounded bg-slate-800 px-2 py-0.5 font-mono text-emerald-300">
                                    score: {Number(r.score).toFixed(4)}
                                </span>
                            </div>
                            <p className="mt-1.5 whitespace-pre-wrap text-slate-300">{r.text}</p>
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

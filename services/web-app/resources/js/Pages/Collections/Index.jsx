import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function CollectionsIndex({ collections, users }) {
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [allowCloud, setAllowCloud] = useState(true);

    const accessForm = useForm({
        user_id: users[0]?.id || '',
        can_read: true,
        can_write: false,
    });
    const [grantColId, setGrantColId] = useState(collections[0]?.id || '');

    const createCollection = async (e) => {
        e.preventDefault();
        const res = await fetch('/api/v1/collections', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, description, allow_cloud_generation: allowCloud }),
        });
        if (res.ok) {
            setName('');
            setDescription('');
            router.reload();
        }
    };

    const toggleCloud = async (col) => {
        await fetch(`/api/v1/collections/${col.id}`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ allow_cloud_generation: !col.allow_cloud_generation }),
        });
        router.reload();
    };

    return (
        <AppLayout title="Collections & Access">
            <Head title="Collections" />
            <div className="grid gap-6 lg:grid-cols-2">
                <section className="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 className="mb-3 text-sm font-semibold text-white">Create Collection</h2>
                    <form onSubmit={createCollection} className="space-y-3 text-sm">
                        <input
                            type="text"
                            required
                            placeholder="Collection name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                        />
                        <input
                            type="text"
                            placeholder="Description"
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                        />
                        <label className="flex items-center gap-2 text-xs text-slate-300">
                            <input
                                type="checkbox"
                                checked={allowCloud}
                                onChange={(e) => setAllowCloud(e.target.checked)}
                            />
                            Allow cloud LLM generation (uncheck for retrieval-only sensitive collections)
                        </label>
                        <button type="submit" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white">
                            Create Collection
                        </button>
                    </form>
                </section>

                <section className="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 className="mb-3 text-sm font-semibold text-white">Grant User Access</h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!grantColId) return;
                            accessForm.post(`/collections/${grantColId}/access`);
                        }}
                        className="space-y-3 text-sm"
                    >
                        <select
                            value={grantColId}
                            onChange={(e) => setGrantColId(e.target.value)}
                            className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                        >
                            {collections.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                        <select
                            value={accessForm.data.user_id}
                            onChange={(e) => accessForm.setData('user_id', e.target.value)}
                            className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                        >
                            {users.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name} ({u.role})
                                </option>
                            ))}
                        </select>
                        <div className="flex gap-4 text-xs text-slate-300">
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="checkbox"
                                    checked={accessForm.data.can_read}
                                    onChange={(e) => accessForm.setData('can_read', e.target.checked)}
                                />
                                Can Read
                            </label>
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="checkbox"
                                    checked={accessForm.data.can_write}
                                    onChange={(e) => accessForm.setData('can_write', e.target.checked)}
                                />
                                Can Write
                            </label>
                        </div>
                        <button type="submit" className="rounded-lg bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">
                            Save Access Grant
                        </button>
                    </form>
                </section>
            </div>

            <section className="rounded-xl border border-slate-800 bg-slate-900 p-5 space-y-3">
                <h2 className="text-sm font-semibold text-white">Collections ({collections.length})</h2>
                <div className="space-y-3">
                    {collections.map((c) => (
                        <div key={c.id} className="rounded-lg border border-slate-800 bg-slate-950 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div className="font-medium text-white">{c.name}</div>
                                    <div className="text-xs text-slate-400">
                                        {c.documents_count} docs · slug: {c.slug}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => toggleCloud(c)}
                                    className={`rounded-full px-3 py-1 text-xs font-medium ${
                                        c.allow_cloud_generation
                                            ? 'bg-emerald-500/20 text-emerald-300'
                                            : 'bg-amber-500/20 text-amber-300'
                                    }`}
                                >
                                    {c.allow_cloud_generation ? 'Cloud Generation: ON' : 'Retrieval-Only: ON'}
                                </button>
                            </div>
                            {c.accesses?.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    {c.accesses.map((a) => (
                                        <span key={a.id} className="rounded bg-slate-900 px-2 py-0.5 text-[11px] text-slate-300">
                                            {a.user?.name}: {a.can_read ? 'R' : ''}
                                            {a.can_write ? 'W' : ''}
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </section>
        </AppLayout>
    );
}
